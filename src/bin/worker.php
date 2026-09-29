<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Observability\ErrorTracking\ErrorTrackerFactory;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Observability\Metrics\MetricsFactory;
use CentralVet\Queue\QueueMessage;
use CentralVet\Queue\RedisQueue;

const HEARTBEAT_FILE = '/tmp/centralvet-worker.heartbeat';
const POP_TIMEOUT_SECONDS = 1;

$logger = JsonLogger::fromEnvironment('worker');
$metrics = MetricsFactory::fromEnvironment($logger);
$errorTracker = ErrorTrackerFactory::fromEnvironment($logger);

$queueNames = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (getenv('WORKER_QUEUE_NAMES') ?: 'default')),
)));
if ($queueNames === []) {
    $queueNames = ['default'];
}

$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

/**
 * Handles a single job payload. Fase 0 has no business jobs yet, so this is
 * a generic placeholder that proves the pipeline end to end (dequeue,
 * ack/retry/dead-letter, structured logging, metrics, error tracking);
 * future job types should dispatch from here into real Application
 * services, keyed by a `type` field on the payload.
 */
$handle = static function (QueueMessage $message) use ($logger): void {
    $logger->info('queue.job.processing', [
        'queue' => $message->queue,
        'job_id' => $message->id,
        'tenant_id' => $message->tenantId,
        'attempts' => $message->attempts,
    ]);
};

$logger->info('worker.started', ['queues' => $queueNames]);

try {
    $queue = RedisQueue::fromEnvironment();
} catch (\Throwable $exception) {
    $errorTracker->captureException($exception, ['phase' => 'startup']);
    $logger->critical('worker.startup_failed', ['reason' => $exception->getMessage()]);

    // Keep heartbeating so the container is not restart-looped while Redis
    // recovers; docker-compose already gates worker startup on Redis's own
    // healthcheck, so this path is a defensive fallback, not the norm.
    while ($running) {
        touch(HEARTBEAT_FILE);
        sleep(10);
    }

    $logger->info('worker.stopped', []);
    exit(1);
}

while ($running) {
    touch(HEARTBEAT_FILE);
    CorrelationIdContext::start();

    try {
        foreach ($queueNames as $queueName) {
            $recovered = $queue->recoverDue($queueName);
            if ($recovered > 0) {
                $logger->debug('queue.recovered_due', ['queue' => $queueName, 'count' => $recovered]);
            }
        }

        $message = null;
        foreach ($queueNames as $queueName) {
            $message = $queue->pop($queueName, POP_TIMEOUT_SECONDS);
            if ($message !== null) {
                break;
            }
        }

        if ($message === null) {
            continue;
        }

        CorrelationIdContext::start($message->correlationId);
        $metrics->increment('queue.job.received', 1, ['queue' => $message->queue]);

        try {
            $handle($message);
            $queue->ack($message);
            $metrics->increment('queue.job.completed', 1, ['queue' => $message->queue]);
            $logger->info('queue.job.completed', ['queue' => $message->queue, 'job_id' => $message->id]);
        } catch (\Throwable $jobException) {
            $errorTracker->captureException($jobException, [
                'queue' => $message->queue,
                'job_id' => $message->id,
                'attempts' => $message->attempts + 1,
            ]);
            $queue->fail($message, $jobException->getMessage());
            $metrics->increment('queue.job.failed', 1, ['queue' => $message->queue]);
            $logger->warning('queue.job.failed', [
                'queue' => $message->queue,
                'job_id' => $message->id,
                'attempts' => $message->attempts + 1,
                'max_attempts' => $message->maxAttempts,
            ]);
        }
    } catch (\Throwable $loopException) {
        $errorTracker->captureException($loopException, ['phase' => 'loop']);
        $logger->error('worker.loop_error', ['reason' => $loopException->getMessage()]);
        sleep(1);
    } finally {
        CorrelationIdContext::clear();
    }
}

$logger->info('worker.stopped', []);

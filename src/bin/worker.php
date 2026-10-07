<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Communication\CommunicationJobHandler;
use CentralVet\Communication\CommunicationScheduler;
use CentralVet\Communication\EmailProviderFactory;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Observability\ErrorTracking\ErrorTrackerFactory;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Observability\Metrics\MetricsFactory;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Queue\QueueMessage;
use CentralVet\Queue\QueueWorkerLoop;
use CentralVet\Queue\RedisQueue;

const HEARTBEAT_FILE = '/tmp/centralvet-worker.heartbeat';
const POP_TIMEOUT_SECONDS = 1;

$logger = JsonLogger::fromEnvironment('worker');

/*
 * Modes: continuous (default, the docker `worker` service) or one-shot for
 * cron on shared hosting: `php bin/worker.php --once [--max-jobs=N]
 * [--max-seconds=N]` drains the queue until it is empty or a limit is hit
 * and exits 0 (no heartbeat, no scheduler tick: cron runs
 * bin/communication-scheduler.php on its own).
 */
try {
    $options = QueueWorkerLoop::parseOptions(array_values($argv ?? []));
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}
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

$envInt = static function (string $name, int $default): int {
    $value = getenv($name);

    return ($value === false || trim($value) === '' || !is_numeric(trim($value))) ? $default : (int) trim($value);
};

$communicationSystemUserId = $envInt('COMMUNICATION_SYSTEM_USER_ID', 1);
$schedulerIntervalSeconds = max(0, $envInt('COMMUNICATION_SCHEDULER_INTERVAL_SECONDS', 3600));
$receivableReminderDays = max(0, $envInt('COMMUNICATION_RECEIVABLE_REMINDER_DAYS', 7));

/**
 * Communication job handler (Fase 7A): built lazily so a bad e-mail driver
 * configuration fails the communication jobs (retry / dead-letter) instead
 * of the whole worker. A new PDO is opened per job.
 */
$communicationHandler = null;
$communicationHandlerFor = static function () use (&$communicationHandler, $logger, $communicationSystemUserId): CommunicationJobHandler {
    return $communicationHandler ??= CommunicationJobHandler::forEnvironment(
        EmailProviderFactory::fromEnvironment($logger),
        $logger,
        $communicationSystemUserId,
    );
};

/**
 * Dispatches a job by its payload `type`: `communication.message.send` goes
 * to the communication handler; any other type keeps the generic log of the
 * Fase 0 pipeline (dequeue, ack/retry/dead-letter, logging, metrics).
 */
$handle = static function (QueueMessage $message) use ($logger, $communicationHandlerFor): void {
    $logger->info('queue.job.processing', [
        'queue' => $message->queue,
        'job_id' => $message->id,
        'tenant_id' => $message->tenantId,
        'attempts' => $message->attempts,
        'type' => is_string($message->payload['type'] ?? null) ? $message->payload['type'] : null,
    ]);

    if (($message->payload['type'] ?? null) === MessageQueuePublisher::JOB_TYPE) {
        $communicationHandlerFor()->handle($message);
    }
};

$logger->info('worker.started', ['queues' => $queueNames, 'mode' => $options['once'] ? 'once' : 'continuous']);

if ($options['once']) {
    // Overlapping cron runs are skipped: the conditional claim already
    // prevents a double send, but on shared hosting a slow run plus the next
    // cron would pile up PHP processes and Redis/MySQL connections.
    $lockHandle = fopen(sys_get_temp_dir() . '/centralvet-worker-once-' . md5(__DIR__) . '.lock', 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $logger->info('worker.once_skipped_locked', []);
        exit(0);
    }

    try {
        $queue = RedisQueue::fromEnvironment();
        $loop = new QueueWorkerLoop($queue, $queueNames, $handle, $logger, $metrics, $errorTracker, null, POP_TIMEOUT_SECONDS);
        $processed = $loop->drain(
            $options['max_jobs'],
            $options['max_seconds'],
            static function () use (&$running): bool {
                return $running;
            },
        );
    } catch (\Throwable $exception) {
        $errorTracker->captureException($exception, ['phase' => 'once']);
        $logger->error('worker.loop_error', ['exception' => $exception::class]);
        exit(1);
    } finally {
        CorrelationIdContext::clear();
    }

    $logger->info('worker.stopped', ['mode' => 'once', 'processed' => $processed]);
    exit(0);
}

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

/**
 * Communication scheduler tick (Fase 7A): every
 * COMMUNICATION_SCHEDULER_INTERVAL_SECONDS (0 disables), on a PDO opened for
 * the tick. A failure is captured and never stops the loop.
 */
$schedulerNextRunAt = time();
$runSchedulerTick = static function () use (&$schedulerNextRunAt, $schedulerIntervalSeconds, $queue, $logger, $errorTracker, $receivableReminderDays, $communicationSystemUserId): void {
    if ($schedulerIntervalSeconds <= 0 || time() < $schedulerNextRunAt) {
        return;
    }

    $schedulerNextRunAt = time() + $schedulerIntervalSeconds;

    try {
        CommunicationScheduler::forConnection(
            PdoConnectionFactory::fromEnvironment(),
            new MessageQueuePublisher($queue),
            $logger,
            $receivableReminderDays,
            $communicationSystemUserId,
        )->runOnce();
    } catch (\Throwable $schedulerException) {
        $errorTracker->captureException($schedulerException, ['phase' => 'communication_scheduler']);
        $logger->error('communication.scheduler.failed', ['exception' => $schedulerException::class]);
    }
};

$loop = new QueueWorkerLoop($queue, $queueNames, $handle, $logger, $metrics, $errorTracker, null, POP_TIMEOUT_SECONDS);

while ($running) {
    touch(HEARTBEAT_FILE);
    CorrelationIdContext::start();

    try {
        $runSchedulerTick();
        $loop->processNext();
    } catch (\Throwable $loopException) {
        $errorTracker->captureException($loopException, ['phase' => 'loop']);
        $logger->error('worker.loop_error', ['reason' => $loopException->getMessage()]);
        sleep(1);
    } finally {
        CorrelationIdContext::clear();
    }
}

$logger->info('worker.stopped', []);

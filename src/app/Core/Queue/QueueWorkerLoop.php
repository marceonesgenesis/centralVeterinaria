<?php

declare(strict_types=1);

namespace CentralVet\Queue;

use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Observability\ErrorTracking\ErrorTrackerInterface;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Observability\Metrics\MetricsInterface;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * One consumption step of src/bin/worker.php (recover due retries, pop one
 * job, handle, ack or fail), shared by the continuous mode and the one-shot
 * mode (`--once`) used by cron on shared hosting (Fase 7A, T-15).
 *
 * The failure path forwards only the exception message to the queue (the
 * communication handler throws MessageDeliveryFailed, whose message is the
 * error code) and logs only ids and counters.
 */
final class QueueWorkerLoop
{
    /** Default time budget of `--once`, under a one-minute cron. */
    public const DEFAULT_ONCE_MAX_SECONDS = 50;

    private readonly Closure $clock;

    /**
     * @param list<string> $queueNames
     * @param Closure(QueueMessage): void $handle
     * @param (Closure(): int)|null $clock seconds, defaults to time()
     */
    public function __construct(
        private readonly QueueInterface $queue,
        private readonly array $queueNames,
        private readonly Closure $handle,
        private readonly LoggerInterface $logger,
        private readonly MetricsInterface $metrics,
        private readonly ErrorTrackerInterface $errorTracker,
        ?Closure $clock = null,
        private readonly int $popTimeoutSeconds = 1,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * `bin/worker.php` (continuous, default) or
     * `bin/worker.php --once [--max-jobs=N] [--max-seconds=N]`; 0 means no
     * limit, and `--once` without `--max-seconds` uses
     * {@see DEFAULT_ONCE_MAX_SECONDS}.
     *
     * @param list<string> $argv
     * @return array{once: bool, max_jobs: int, max_seconds: int}
     */
    public static function parseOptions(array $argv): array
    {
        $once = false;
        $maxJobs = 0;
        $maxSeconds = null;

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--once') {
                $once = true;
            } elseif (preg_match('/^--max-jobs=(\d+)$/', $argument, $match) === 1) {
                $maxJobs = (int) $match[1];
            } elseif (preg_match('/^--max-seconds=(\d+)$/', $argument, $match) === 1) {
                $maxSeconds = (int) $match[1];
            } else {
                throw new InvalidArgumentException('Unknown worker option: ' . $argument);
            }
        }

        return [
            'once' => $once,
            'max_jobs' => $maxJobs,
            'max_seconds' => $maxSeconds ?? ($once ? self::DEFAULT_ONCE_MAX_SECONDS : 0),
        ];
    }

    /**
     * Processes jobs until every queue is empty or a limit is reached
     * (0 = no limit). The time limit is checked before each pop, so a job
     * already started always finishes. Returns the number of jobs handled.
     *
     * @param (Closure(): bool)|null $keepRunning false stops (e.g. SIGTERM)
     */
    public function drain(int $maxJobs, int $maxSeconds, ?Closure $keepRunning = null): int
    {
        $startedAt = ($this->clock)();
        $processed = 0;

        while (true) {
            if ($keepRunning !== null && !$keepRunning()) {
                break;
            }

            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            if ($maxSeconds > 0 && ($this->clock)() - $startedAt >= $maxSeconds) {
                break;
            }

            if (!$this->processNext()) {
                break;
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * Recovers due retries, pops at most one job and handles it. Returns
     * false when every queue was empty. Errors of the job itself are
     * handled here (fail + log); queue errors (Redis down) propagate.
     */
    public function processNext(): bool
    {
        foreach ($this->queueNames as $queueName) {
            $recovered = $this->queue->recoverDue($queueName);
            if ($recovered > 0) {
                $this->logger->debug('queue.recovered_due', ['queue' => $queueName, 'count' => $recovered]);
            }
        }

        $message = null;
        foreach ($this->queueNames as $queueName) {
            $message = $this->queue->pop($queueName, $this->popTimeoutSeconds);
            if ($message !== null) {
                break;
            }
        }

        if ($message === null) {
            return false;
        }

        CorrelationIdContext::start($message->correlationId);
        $this->metrics->increment('queue.job.received', 1, ['queue' => $message->queue]);

        try {
            ($this->handle)($message);
            $this->queue->ack($message);
            $this->metrics->increment('queue.job.completed', 1, ['queue' => $message->queue]);
            $this->logger->info('queue.job.completed', ['queue' => $message->queue, 'job_id' => $message->id]);
        } catch (Throwable $jobException) {
            $this->errorTracker->captureException($jobException, [
                'queue' => $message->queue,
                'job_id' => $message->id,
                'attempts' => $message->attempts + 1,
            ]);
            $this->queue->fail($message, $jobException->getMessage());
            $this->metrics->increment('queue.job.failed', 1, ['queue' => $message->queue]);
            $this->logger->warning('queue.job.failed', [
                'queue' => $message->queue,
                'job_id' => $message->id,
                'attempts' => $message->attempts + 1,
                'max_attempts' => $message->maxAttempts,
            ]);
        }

        return true;
    }
}

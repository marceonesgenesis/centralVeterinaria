<?php

declare(strict_types=1);

namespace CentralVet\Queue;

use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Queue\Exception\QueueException;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Redis\RedisConnectionFactory;

/**
 * Reliable Redis queue with retry (exponential backoff via a delayed ZSET)
 * and a dead-letter list, built only from primitives available in the
 * project's own Redis instance (lists, sorted sets, BRPOPLPUSH) — no
 * external broker or SaaS provider required.
 *
 * Layout per queue name, all environment-scoped (see KeyNamespace::key):
 *   queue:{name}            pending list  (LPUSH by push()/recoverDue())
 *   queue:{name}:processing in-flight list (populated by BRPOPLPUSH in pop())
 *   queue:{name}:delayed    ZSET scored by availability timestamp (retries + delayed jobs)
 *   queue:{name}:dead       dead-letter list (exhausted retries)
 *
 * Tenant isolation is carried as a `tenant_id` field inside each message
 * rather than as separate physical queues per tenant, mirroring this
 * project's "shared storage + tenant_id column" convention (see plan.md);
 * a single worker process can then service every tenant.
 */
final class RedisQueue implements QueueInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly KeyNamespace $keys,
        private readonly int $baseBackoffSeconds = 5,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            RedisConnectionFactory::fromEnvironment(),
            KeyNamespace::fromEnvironmentVariable(),
            (int) (getenv('QUEUE_BACKOFF_SECONDS') ?: 5),
        );
    }

    public function push(
        string $queue,
        array $payload,
        ?int $tenantId = null,
        ?string $correlationId = null,
        int $delaySeconds = 0,
        int $maxAttempts = 5,
    ): string {
        $id = bin2hex(random_bytes(12));

        $envelope = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'correlation_id' => $correlationId ?? (CorrelationIdContext::current() ?? CorrelationIdContext::generate()),
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'enqueued_at' => microtime(true),
        ];

        $json = $this->encode($envelope);

        if ($delaySeconds > 0) {
            $this->redis->zAdd($this->delayedKey($queue), microtime(true) + $delaySeconds, $json);
        } else {
            $this->redis->lPush($this->pendingKey($queue), $json);
        }

        return $id;
    }

    public function recoverDue(string $queue): int
    {
        $due = $this->redis->zRangeByScore($this->delayedKey($queue), '-inf', (string) microtime(true));
        $moved = 0;

        foreach ($due as $json) {
            $this->redis->multi()
                ->zRem($this->delayedKey($queue), $json)
                ->lPush($this->pendingKey($queue), $json)
                ->exec();
            $moved++;
        }

        return $moved;
    }

    public function pop(string $queue, int $timeoutSeconds = 5): ?QueueMessage
    {
        $json = $this->redis->brPoplpush($this->pendingKey($queue), $this->processingKey($queue), $timeoutSeconds);

        if ($json === false || $json === null || $json === '') {
            return null;
        }

        return $this->toMessage($queue, $json);
    }

    public function ack(QueueMessage $message): void
    {
        $this->redis->lRem($this->processingKey($message->queue), $message->rawEnvelope(), 0);
    }

    public function fail(QueueMessage $message, ?string $reason = null): void
    {
        $this->redis->lRem($this->processingKey($message->queue), $message->rawEnvelope(), 0);

        $attempts = $message->attempts + 1;
        $envelope = $this->decode($message->rawEnvelope());
        $envelope['attempts'] = $attempts;
        $envelope['last_error'] = $reason;
        $json = $this->encode($envelope);

        if ($attempts >= $message->maxAttempts) {
            $this->redis->lPush($this->deadKey($message->queue), $json);

            return;
        }

        $delay = $this->baseBackoffSeconds * (2 ** ($attempts - 1));
        $this->redis->zAdd($this->delayedKey($message->queue), microtime(true) + $delay, $json);
    }

    public function deadLetterCount(string $queue): int
    {
        return (int) $this->redis->lLen($this->deadKey($queue));
    }

    private function toMessage(string $queue, string $json): QueueMessage
    {
        $data = $this->decode($json);

        return new QueueMessage(
            id: (string) $data['id'],
            queue: $queue,
            payload: (array) $data['payload'],
            tenantId: $data['tenant_id'] !== null ? (int) $data['tenant_id'] : null,
            correlationId: (string) $data['correlation_id'],
            attempts: (int) $data['attempts'],
            maxAttempts: (int) $data['max_attempts'],
            rawEnvelope: $json,
        );
    }

    private function encode(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new QueueException('Unable to encode queue message: ' . json_last_error_msg());
        }

        return $json;
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new QueueException('Unable to decode queue message envelope');
        }

        return $data;
    }

    private function pendingKey(string $queue): string
    {
        return $this->keys->key('queue:' . $queue, 'pending');
    }

    private function processingKey(string $queue): string
    {
        return $this->keys->key('queue:' . $queue, 'processing');
    }

    private function delayedKey(string $queue): string
    {
        return $this->keys->key('queue:' . $queue, 'delayed');
    }

    private function deadKey(string $queue): string
    {
        return $this->keys->key('queue:' . $queue, 'dead');
    }
}

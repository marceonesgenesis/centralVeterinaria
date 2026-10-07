<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Queue\RedisQueue;
use CentralVet\Redis\KeyNamespace;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\RedisIntegrationTestCase;

/**
 * Integration test against the real Redis service from docker-compose.yml.
 * Covers push/pop/ack plus the retry -> dead-letter path described in
 * RedisQueue's class docblock.
 */
final class RedisQueueIntegrationTest extends RedisIntegrationTestCase
{
    /**
     * Unique per test instance (not a constant): parallel suite runs used
     * to share one fixed 't11-queue' and pop each other's messages.
     */
    private string $queue;

    private KeyNamespace $keys;

    public function setUp(): void
    {
        $this->queue = 't11-queue-' . bin2hex(random_bytes(4));
        parent::setUp();
        $this->keys = new KeyNamespace('testing');
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        foreach (['pending', 'processing', 'delayed', 'dead'] as $suffix) {
            $this->redis->del($this->keys->key('queue:' . $this->queue, $suffix));
        }
    }

    public function testPushPopAckRoundTrip(): void
    {
        $queue = new RedisQueue($this->redis, $this->keys, baseBackoffSeconds: 1);

        $id = $queue->push($this->queue, ['action' => 'send-reminder'], tenantId: 101, maxAttempts: 3);

        $message = $queue->pop($this->queue, timeoutSeconds: 2);

        Assert::notNull($message);
        Assert::same($id, $message->id);
        Assert::same(['action' => 'send-reminder'], $message->payload);
        Assert::same(101, $message->tenantId);
        Assert::same(0, $message->attempts);

        $queue->ack($message);

        Assert::null($queue->pop($this->queue, timeoutSeconds: 1), 'Acked message must not be redelivered');
        Assert::same(0, $queue->deadLetterCount($this->queue));
    }

    public function testFailedMessageRetriesThenLandsInDeadLetterAfterMaxAttempts(): void
    {
        // baseBackoffSeconds: 0 keeps the retry delay effectively immediate
        // so the test does not need to sleep for the exponential backoff.
        $queue = new RedisQueue($this->redis, $this->keys, baseBackoffSeconds: 0);

        $queue->push($this->queue, ['action' => 'charge-invoice'], tenantId: 202, maxAttempts: 2);

        // Attempt 1: fails, goes to the delayed set (1 < max_attempts 2).
        $message = $queue->pop($this->queue, timeoutSeconds: 2);
        Assert::notNull($message);
        $queue->fail($message, 'gateway timeout');
        Assert::same(0, $queue->deadLetterCount($this->queue));

        $moved = $queue->recoverDue($this->queue);
        Assert::same(1, $moved, 'The delayed retry must become due immediately with a zero backoff');

        // Attempt 2: fails again, attempts (2) now reaches max_attempts (2)
        // -> dead letter.
        $retried = $queue->pop($this->queue, timeoutSeconds: 2);
        Assert::notNull($retried);
        Assert::same(1, $retried->attempts);
        $queue->fail($retried, 'gateway timeout again');

        Assert::same(1, $queue->deadLetterCount($this->queue));
        Assert::null($queue->pop($this->queue, timeoutSeconds: 1));
    }
}

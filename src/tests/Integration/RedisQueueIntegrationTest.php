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
    private const QUEUE = 't11-queue';

    private KeyNamespace $keys;

    public function setUp(): void
    {
        parent::setUp();
        $this->keys = new KeyNamespace('testing');
    }

    public function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        foreach (['pending', 'processing', 'delayed', 'dead'] as $suffix) {
            $this->redis->del($this->keys->key('queue:' . self::QUEUE, $suffix));
        }
    }

    public function testPushPopAckRoundTrip(): void
    {
        $queue = new RedisQueue($this->redis, $this->keys, baseBackoffSeconds: 1);

        $id = $queue->push(self::QUEUE, ['action' => 'send-reminder'], tenantId: 101, maxAttempts: 3);

        $message = $queue->pop(self::QUEUE, timeoutSeconds: 2);

        Assert::notNull($message);
        Assert::same($id, $message->id);
        Assert::same(['action' => 'send-reminder'], $message->payload);
        Assert::same(101, $message->tenantId);
        Assert::same(0, $message->attempts);

        $queue->ack($message);

        Assert::null($queue->pop(self::QUEUE, timeoutSeconds: 1), 'Acked message must not be redelivered');
        Assert::same(0, $queue->deadLetterCount(self::QUEUE));
    }

    public function testFailedMessageRetriesThenLandsInDeadLetterAfterMaxAttempts(): void
    {
        // baseBackoffSeconds: 0 keeps the retry delay effectively immediate
        // so the test does not need to sleep for the exponential backoff.
        $queue = new RedisQueue($this->redis, $this->keys, baseBackoffSeconds: 0);

        $queue->push(self::QUEUE, ['action' => 'charge-invoice'], tenantId: 202, maxAttempts: 2);

        // Attempt 1: fails, goes to the delayed set (1 < max_attempts 2).
        $message = $queue->pop(self::QUEUE, timeoutSeconds: 2);
        Assert::notNull($message);
        $queue->fail($message, 'gateway timeout');
        Assert::same(0, $queue->deadLetterCount(self::QUEUE));

        $moved = $queue->recoverDue(self::QUEUE);
        Assert::same(1, $moved, 'The delayed retry must become due immediately with a zero backoff');

        // Attempt 2: fails again, attempts (2) now reaches max_attempts (2)
        // -> dead letter.
        $retried = $queue->pop(self::QUEUE, timeoutSeconds: 2);
        Assert::notNull($retried);
        Assert::same(1, $retried->attempts);
        $queue->fail($retried, 'gateway timeout again');

        Assert::same(1, $queue->deadLetterCount(self::QUEUE));
        Assert::null($queue->pop(self::QUEUE, timeoutSeconds: 1));
    }
}

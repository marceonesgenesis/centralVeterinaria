<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Tests\Support\Assert;

final class RedisConnectionFactoryTest
{
    public function testSelectFailureClosesTheConnectionBeforeThrowing(): void
    {
        $fake = $this->fakeClient(authResult: true, selectResult: false);

        $message = $this->captureRuntimeException(
            static fn () => RedisConnectionFactory::connect('redis', 6379, 3, null, 1.0, $fake)
        );

        Assert::same('Unable to select Redis database 3', $message);
        Assert::true($fake->closed, 'Connection must be closed before throwing on SELECT failure');
    }

    public function testAuthFailureClosesTheConnectionBeforeThrowing(): void
    {
        $fake = $this->fakeClient(authResult: false, selectResult: true);

        $message = $this->captureRuntimeException(
            static fn () => RedisConnectionFactory::connect('redis', 6379, 3, 'x', 1.0, $fake)
        );

        Assert::same('Unable to authenticate with the Redis backend', $message);
        Assert::true($fake->closed, 'Connection must be closed before throwing on AUTH failure');
    }

    public function testInjectedClientIsReturnedWhenEverythingSucceeds(): void
    {
        $fake = $this->fakeClient(authResult: true, selectResult: true);

        $redis = RedisConnectionFactory::connect('redis', 6379, 3, 'x', 1.0, $fake);

        Assert::true($redis === $fake, 'Injected client must be the returned connection');
        Assert::false($fake->closed, 'Successful connection must stay open');
    }

    private function captureRuntimeException(callable $callback): ?string
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        throw new \RuntimeException('Expected RuntimeException was not thrown');
    }

    private function fakeClient(bool $authResult, bool $selectResult): \Redis
    {
        return new class ($authResult, $selectResult) extends \Redis {
            public bool $closed = false;

            public function __construct(private bool $authResult, private bool $selectResult)
            {
                parent::__construct();
            }

            public function connect(
                string $host,
                int $port = 6379,
                float $timeout = 0,
                ?string $persistent_id = null,
                int $retry_interval = 0,
                float $read_timeout = 0,
                ?array $context = null
            ): bool {
                return true;
            }

            public function auth(mixed $credentials): \Redis|bool
            {
                return $this->authResult;
            }

            public function select(int $db): \Redis|bool
            {
                return $this->selectResult;
            }

            public function close(): bool
            {
                $this->closed = true;

                return true;
            }
        };
    }
}

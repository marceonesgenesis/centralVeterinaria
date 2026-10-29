<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeClientInterface;

/**
 * Double for StripeClientInterface (Etapa 3, T-08): responses (or failures)
 * are queued per "<METHOD> <path>" and replayed in FIFO order; every call is
 * recorded in $calls. A call with nothing queued throws LogicException.
 */
final class FakeStripeClient implements StripeClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, idempotencyKey: ?string}> */
    public array $calls = [];

    /** @var array<string, list<array<string, mixed>|StripeApiException>> */
    private array $queue = [];

    /** @param array<string, mixed> $response */
    public function queue(string $method, string $path, array $response): void
    {
        $this->queue[self::key($method, $path)][] = $response;
    }

    public function fail(string $method, string $path, StripeApiException $e): void
    {
        $this->queue[self::key($method, $path)][] = $e;
    }

    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params, 'idempotencyKey' => $idempotencyKey];

        $key = self::key($method, $path);
        if (($this->queue[$key] ?? []) === []) {
            throw new \LogicException('Unexpected Stripe call ' . $key);
        }

        $next = array_shift($this->queue[$key]);
        if ($next instanceof StripeApiException) {
            throw $next;
        }

        return $next;
    }

    private static function key(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }
}

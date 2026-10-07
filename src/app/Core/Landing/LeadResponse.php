<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Resposta JSON de `POST /lead.php`: status HTTP, corpo (serializado pelo
 * entrypoint) e headers. Toda resposta leva Content-Type JSON e
 * Cache-Control no-store.
 */
final class LeadResponse
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $extraHeaders
     */
    public static function json(int $status, array $body, array $extraHeaders = []): self
    {
        return new self($status, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ] + $extraHeaders);
    }

    public static function accepted(int $status): self
    {
        return self::json($status, ['accepted' => true]);
    }

    /**
     * @param array<string, mixed>  $extra
     * @param array<string, string> $extraHeaders
     */
    public static function error(int $status, string $error, array $extra = [], array $extraHeaders = []): self
    {
        return self::json($status, ['accepted' => false, 'error' => $error] + $extra, $extraHeaders);
    }
}

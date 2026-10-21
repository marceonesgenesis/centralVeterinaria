<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Absolute public base URL of the application (APP_PUBLIC_URL).
 *
 * Links sent by e-mail (password reset) must never be built from the
 * request's Host header, which an attacker controls; they are built from
 * this configured value instead.
 */
final class PublicUrl
{
    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $baseUrl = trim($baseUrl);
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        if ($baseUrl === '' || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('APP_PUBLIC_URL must be an absolute http(s) URL');
        }

        $this->baseUrl = $baseUrl;
    }

    public static function fromEnvironment(): self
    {
        return new self((string) (getenv('APP_PUBLIC_URL') ?: ''));
    }

    public function to(string $pathAndQuery): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($pathAndQuery, '/');
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Communication;

/**
 * SMTP settings from environment variables (.env.example / docker-compose).
 */
final class SmtpConfig
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $encryption,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly int $timeoutSeconds,
    ) {
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('Invalid SMTP encryption');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            trim(self::env('SMTP_HOST', '')),
            (int) self::env('SMTP_PORT', '587'),
            self::env('SMTP_USERNAME', ''),
            self::env('SMTP_PASSWORD', ''),
            strtolower(trim(self::env('SMTP_ENCRYPTION', 'tls'))),
            trim(self::env('SMTP_FROM_ADDRESS', 'no-reply@example.invalid')),
            self::env('SMTP_FROM_NAME', 'Central Vet'),
            max(1, (int) self::env('SMTP_TIMEOUT_SECONDS', '10')),
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? $default : $value;
    }
}

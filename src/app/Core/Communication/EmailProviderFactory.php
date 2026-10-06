<?php

declare(strict_types=1);

namespace CentralVet\Communication;

use CentralVet\Observability\Logging\LoggerInterface;

/**
 * Picks the e-mail provider by COMMUNICATION_EMAIL_DRIVER ("log" by default,
 * "smtp" for real delivery).
 */
final class EmailProviderFactory
{
    public static function fromEnvironment(LoggerInterface $logger): MessageChannelProviderInterface
    {
        $driver = getenv('COMMUNICATION_EMAIL_DRIVER');
        $driver = ($driver === false || trim($driver) === '') ? 'log' : strtolower(trim($driver));

        return match ($driver) {
            'log' => new LogEmailProvider($logger),
            'smtp' => new SmtpEmailProvider(SmtpConfig::fromEnvironment()),
            default => throw new \InvalidArgumentException('Unknown communication e-mail driver'),
        };
    }
}

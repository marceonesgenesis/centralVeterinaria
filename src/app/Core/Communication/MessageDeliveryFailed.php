<?php

declare(strict_types=1);

namespace CentralVet\Communication;

/**
 * Delivery failure carrying only an error code. The message is fixed
 * ("Message delivery failed: <code>") because src/bin/worker.php forwards
 * getMessage() to the Redis dead-letter: it must never hold personal data,
 * and no previous exception is chained (its message could).
 */
final class MessageDeliveryFailed extends \RuntimeException
{
    public function __construct(private readonly string $errorCode)
    {
        parent::__construct('Message delivery failed: ' . $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

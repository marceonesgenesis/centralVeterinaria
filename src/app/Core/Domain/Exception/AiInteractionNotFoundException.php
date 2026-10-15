<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * The AI suggestion does not exist, belongs to another tenant or unit, or
 * was already decided (or failed). One message for every case, so the
 * decision endpoint is not an oracle.
 */
final class AiInteractionNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AI suggestion not found');
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * The document does not exist, belongs to another tenant or unit, or is
 * not ready yet (Fase 7B). One single message for every case, so the
 * download cannot be used as an oracle of other units' documents.
 */
final class DocumentNotAvailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Document not found');
    }
}

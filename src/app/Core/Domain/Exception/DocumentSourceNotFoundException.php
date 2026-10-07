<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * The patient, prescription or surgery a document is requested for does
 * not exist in the current tenant or unit (Fase 7B). No ids or names in
 * the message.
 */
final class DocumentSourceNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Document source not found');
    }
}

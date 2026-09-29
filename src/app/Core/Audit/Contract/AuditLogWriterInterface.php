<?php

declare(strict_types=1);

namespace CentralVet\Audit\Contract;

use CentralVet\Audit\AuditEvent;

/**
 * Persistence boundary for the business audit trail. Kept separate from
 * CentralVet\Domain\Contract\RepositoryInterface because an audit row is
 * append-only and never looked up/updated/removed through this port.
 */
interface AuditLogWriterInterface
{
    public function record(AuditEvent $event): void;
}

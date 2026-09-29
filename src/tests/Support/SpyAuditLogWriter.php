<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Audit\AuditEvent;
use CentralVet\Audit\Contract\AuditLogWriterInterface;

/**
 * In-memory double for AuditLogWriterInterface: keeps every recorded event
 * so tests can assert on what RbacAuthorizationService audited, without
 * touching the (not yet applied) `audit_log` table/migration.
 */
final class SpyAuditLogWriter implements AuditLogWriterInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

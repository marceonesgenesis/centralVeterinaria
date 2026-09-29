<?php

declare(strict_types=1);

namespace CentralVet\Audit;

use CentralVet\Audit\Contract\AuditLogWriterInterface;

/**
 * No-op writer: the safe default until the foundation migration
 * (src/app/database/migrations/20260920_0001_foundation_multitenancy.sql,
 * ADR 0003) has explicit SQL execution approval and has actually been
 * applied, and until a real PDO connection is wired for the runtime. Bind
 * this implementation wherever AuditLogWriterInterface is needed today;
 * swapping to PdoAuditLogWriter later is a wiring/config change only, since
 * both implement the same interface.
 */
final class NullAuditLogWriter implements AuditLogWriterInterface
{
    public function record(AuditEvent $event): void
    {
        // Intentionally empty — see class docblock.
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\PendingItem;
use DateTimeImmutable;

/**
 * Read boundary of the Central de Pendências (Fase 7A): reads the open items
 * of the 8 sources of {@see PendingItem::TYPES} for one unit of the current
 * tenant, filling `dueAt` by the rules documented in {@see PendingItem}.
 */
interface PendingItemQueryInterface
{
    /**
     * At most `$limitPerType` items per type, within the current tenant.
     *
     * @return list<PendingItem>
     */
    public function listForUnit(int $systemUnitId, DateTimeImmutable $now, int $limitPerType): array;
}

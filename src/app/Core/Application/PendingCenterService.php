<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\Contract\PendingItemQueryInterface;
use CentralVet\Domain\PendingItem;
use CentralVet\Domain\PendingItemPriority;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;

/**
 * Central de Pendências (T-13, PRD §8.23): reads the pending items of the
 * active unit, filters them by type and by "only mine" and orders them by
 * priority rank, then dueAt ascending, then type (PendingItem::TYPES order)
 * and sourceId.
 *
 * Authorization always runs against the active unit
 * (`TenantContext::requireUnitId()`) before the query is called.
 */
final class PendingCenterService
{
    private const ENTITY_TYPE = 'pending_center';
    private const LIMIT_PER_TYPE = 200;

    private readonly Closure $clock;

    public function __construct(
        private readonly PendingItemQueryInterface $items,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * @param string|null $type a PendingItem::TYPES value; anything else lists all types.
     * @return list<PendingItem>
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function list(?string $type, bool $onlyMine, string $action): array
    {
        $now = $this->now();
        $items = $this->load($now, $action);

        if ($type !== null && in_array($type, PendingItem::TYPES, true)) {
            $items = array_filter($items, static fn (PendingItem $i): bool => $i->type() === $type);
        }

        if ($onlyMine) {
            $userId = $this->context->userId();
            $items = array_filter($items, static fn (PendingItem $i): bool => $i->responsibleSystemUserId() === $userId);
        }

        $items = array_values($items);
        $typeOrder = array_flip(PendingItem::TYPES);

        usort($items, static fn (PendingItem $a, PendingItem $b): int => [
            PendingItemPriority::rank($a->priority($now)),
            $a->dueAt(),
            $typeOrder[$a->type()],
            $a->sourceId(),
        ] <=> [
            PendingItemPriority::rank($b->priority($now)),
            $b->dueAt(),
            $typeOrder[$b->type()],
            $b->sourceId(),
        ]);

        return $items;
    }

    /**
     * Total per type over the unfiltered list, with all 8 types present.
     *
     * @return array<string, int>
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function countsByType(string $action): array
    {
        $counts = array_fill_keys(PendingItem::TYPES, 0);

        foreach ($this->load($this->now(), $action) as $item) {
            $counts[$item->type()]++;
        }

        return $counts;
    }

    /** @return list<PendingItem> */
    private function load(DateTimeImmutable $now, string $action): array
    {
        $unitId = $this->context->requireUnitId();

        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceUnitId: $unitId,
            entityType: self::ENTITY_TYPE,
        ))->assertAllowed();

        return $this->items->listForUnit($unitId, $now, self::LIMIT_PER_TYPE);
    }
}

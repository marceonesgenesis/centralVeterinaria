<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\MessageTemplate;
use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the MessageTemplate aggregate
 * (`message_template`), always within the current tenant.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface MessageTemplateRepositoryInterface extends TenantRepositoryInterface
{
    /** @return TEntity|null */
    public function findById(int|string $id): ?object;

    /**
     * Every template of the tenant (any status), ordered by purpose,
     * channel and name.
     *
     * @return list<TEntity>
     */
    public function listAll(): array;

    /** The active template for the purpose and channel, or null. */
    public function findActiveFor(string $purpose, string $channel): ?MessageTemplate;

    /**
     * Number of active templates for the purpose and channel, ignoring
     * `$exceptTemplateId` (the template being edited).
     */
    public function countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int;

    /** @param TEntity $entity @return TEntity */
    public function save(object $entity): object;
}

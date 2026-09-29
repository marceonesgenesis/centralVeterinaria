<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Persistence boundary. Domain/application code must not depend on TRecord.
 *
 * @template TEntity of object
 */
interface RepositoryInterface
{
    /** @return TEntity|null */
    public function findById(int|string $id): ?object;

    /** @param TEntity $entity @return TEntity */
    public function save(object $entity): object;

    /** @param TEntity $entity */
    public function remove(object $entity): void;
}

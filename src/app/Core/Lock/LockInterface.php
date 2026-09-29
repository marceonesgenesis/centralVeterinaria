<?php

declare(strict_types=1);

namespace CentralVet\Lock;

interface LockInterface
{
    /** @return bool true when the lock was acquired by the caller. */
    public function acquire(string $name, int $ttlSeconds = 30): bool;

    /** Releases the lock only if it is still owned by the caller. */
    public function release(string $name): void;

    /**
     * Runs $callback while holding the lock, releasing it afterwards.
     *
     * @throws \CentralVet\Lock\Exception\LockAcquisitionException when the lock cannot be acquired.
     */
    public function withLock(string $name, int $ttlSeconds, callable $callback): mixed;
}

<?php

declare(strict_types=1);

namespace CentralVet\Storage;

use Closure;
use Throwable;

/**
 * Writes go to the primary storage; reads, existence checks and deletes of a
 * key the primary does not hold fall back to the secondary (e.g. legacy
 * patient photos still on S3 after switching to the local driver).
 *
 * The secondary is resolved once, on first use. A failure resolving or
 * querying it counts as "not found" for exists().
 */
final class FallbackReadStorage implements StorageInterface
{
    private ?StorageInterface $secondary = null;

    private ?Throwable $secondaryFailure = null;

    /** @param Closure(): StorageInterface $secondaryFactory */
    public function __construct(
        private readonly StorageInterface $primary,
        private readonly Closure $secondaryFactory,
    ) {
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
    {
        return $this->primary->put($key, $contents, $contentType);
    }

    public function get(string $key): string
    {
        if ($this->primary->exists($key)) {
            return $this->primary->get($key);
        }

        return $this->secondary()->get($key);
    }

    public function exists(string $key): bool
    {
        if ($this->primary->exists($key)) {
            return true;
        }

        try {
            return $this->secondary()->exists($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $key): void
    {
        if ($this->primary->exists($key)) {
            $this->primary->delete($key);

            return;
        }

        $this->secondary()->delete($key);
    }

    public function presignedUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->primary->presignedUrl($key, $ttlSeconds);
    }

    private function secondary(): StorageInterface
    {
        if ($this->secondary !== null) {
            return $this->secondary;
        }

        if ($this->secondaryFailure !== null) {
            throw $this->secondaryFailure;
        }

        try {
            return $this->secondary = ($this->secondaryFactory)();
        } catch (Throwable $exception) {
            $this->secondaryFailure = $exception;

            throw $exception;
        }
    }
}

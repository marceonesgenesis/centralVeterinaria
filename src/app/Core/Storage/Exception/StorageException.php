<?php

declare(strict_types=1);

namespace CentralVet\Storage\Exception;

final class StorageException extends \RuntimeException
{
    public static function fromResponse(string $method, string $key, int $status): self
    {
        return new self(sprintf('S3 %s %s failed with HTTP %d', $method, $key, $status));
    }

    public static function notFound(string $key): self
    {
        return new self(sprintf('Object not found: %s', $key));
    }

    public static function transport(string $method, string $key, string $error): self
    {
        return new self(sprintf('S3 %s %s transport error: %s', $method, $key, $error));
    }
}

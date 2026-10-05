<?php
declare(strict_types=1);

namespace CentralVet\Security;

/** Explicit single-server backend for shared hosting without Redis. */
final class FileRateLimitBackend
{
    public function __construct(private readonly string $directory, private readonly int $decaySeconds)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create rate limit storage');
        }
    }

    public function incr(string $key): int
    {
        return $this->access(function (array &$buckets) use ($key): int {
            $buckets[$key] ??= ['attempts' => 0, 'expires' => time() + $this->decaySeconds];
            return ++$buckets[$key]['attempts'];
        });
    }

    public function expire(string $key, int $seconds): bool
    {
        // incr atomically installs the expiration with the first attempt.
        return true;
    }

    public function get(string $key): int|false
    {
        return $this->access(static fn (array &$buckets): int|false => $buckets[$key]['attempts'] ?? false);
    }

    public function del(string $key): int
    {
        return $this->access(static function (array &$buckets) use ($key): int {
            $existed = isset($buckets[$key]);
            unset($buckets[$key]);
            return (int) $existed;
        });
    }

    public function ttl(string $key): int
    {
        return $this->access(static fn (array &$buckets): int => isset($buckets[$key]) ? max(0, $buckets[$key]['expires'] - time()) : -2);
    }

    private function access(callable $operation): mixed
    {
        $path = $this->directory . '/buckets.json';
        $file = fopen($path, 'c+');
        if ($file === false) {
            throw new \RuntimeException('Unable to open rate limit storage');
        }
        try {
            if (!chmod($path, 0600) || !flock($file, LOCK_EX)) {
                throw new \RuntimeException('Unable to secure rate limit storage');
            }
            $contents = stream_get_contents($file);
            $buckets = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($buckets)) {
                throw new \RuntimeException('Invalid rate limit storage');
            }
            $now = time();
            $buckets = array_filter($buckets, static fn (array $bucket): bool => $bucket['expires'] > $now);
            $result = $operation($buckets);
            $encoded = json_encode($buckets, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
                throw new \RuntimeException('Unable to persist rate limit storage');
            }
            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}

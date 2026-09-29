<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * In-memory double for phpredis's \Redis, used only to test
 * CentralVet\Lock\RedisLock's contract/logic without a real Redis backend.
 *
 * It extends the real \Redis class (required by RedisLock's constructor
 * type hint) rather than implementing an interface, because phpredis ships
 * a concrete class with no extracted interface; both \Redis and its `set`/
 * `eval` methods are non-final, so overriding them here is safe. This
 * requires the redis extension to be loaded (it is loaded in the project's
 * own PHP runtime, see docker/php/Dockerfile), even though no network I/O
 * ever happens: everything below is a plain in-memory array.
 *
 * eval() does not run real Lua. It hard-codes the exact semantics of
 * RedisLock::RELEASE_SCRIPT (compare-and-delete: delete KEYS[1] only if its
 * current value equals ARGV[1]), which is the one and only script RedisLock
 * ever sends — this is a deliberate, documented simplification, not a
 * general-purpose Lua interpreter.
 */
final class FakeRedis extends \Redis
{
    /** @var array<string, string> */
    private array $store = [];

    public function __construct()
    {
        // Deliberately do not call parent::__construct(): this double never
        // opens a real connection.
    }

    /** @param mixed $value @param mixed $options */
    public function set($key, $value, $options = null): \Redis|string|bool
    {
        $nx = false;

        if (is_array($options)) {
            foreach ($options as $optionKey => $optionValue) {
                $flag = is_string($optionKey) ? $optionKey : (string) $optionValue;

                if (strtoupper($flag) === 'NX') {
                    $nx = true;
                }
            }
        }

        if ($nx && array_key_exists($key, $this->store)) {
            return false;
        }

        $this->store[$key] = (string) $value;

        return true;
    }

    public function get($key): mixed
    {
        return $this->store[$key] ?? false;
    }

    /** @param mixed $script @param mixed $args */
    public function eval($script, $args = [], $num_keys = 0): mixed
    {
        $key = $args[0] ?? null;
        $expectedToken = $args[1] ?? null;

        if ($key !== null && array_key_exists($key, $this->store) && $this->store[$key] === $expectedToken) {
            unset($this->store[$key]);

            return 1;
        }

        return 0;
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function rawValue(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function forceSet(string $key, string $value): void
    {
        $this->store[$key] = $value;
    }
}

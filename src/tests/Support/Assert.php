<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

/**
 * Minimal, dependency-free assertion library used by this suite instead of
 * PHPUnit (not installed — see composer.json/composer.lock: phpunit only
 * appears as a require-dev of third-party packages, never as a root
 * dependency of this project).
 */
final class Assert
{
    private function __construct()
    {
    }

    public static function true(bool $condition, string $message = 'Failed asserting that condition is true'): void
    {
        if (!$condition) {
            throw new AssertionFailedException($message);
        }
    }

    public static function false(bool $condition, string $message = 'Failed asserting that condition is false'): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailedException($message !== '' ? $message : sprintf(
                'Failed asserting that %s is identical to expected %s',
                var_export($actual, true),
                var_export($expected, true),
            ));
        }
    }

    public static function null(mixed $actual, string $message = ''): void
    {
        self::same(null, $actual, $message !== '' ? $message : 'Failed asserting that value is null');
    }

    public static function notNull(mixed $actual, string $message = ''): void
    {
        self::true($actual !== null, $message !== '' ? $message : 'Failed asserting that value is not null');
    }

    public static function instanceOf(string $class, mixed $actual, string $message = ''): void
    {
        self::true($actual instanceof $class, $message !== '' ? $message : "Failed asserting that value is instance of {$class}");
    }

    public static function count(int $expected, \Countable|array $actual, string $message = ''): void
    {
        self::same($expected, count($actual), $message);
    }

    public static function stringContains(string $needle, string $haystack, string $message = ''): void
    {
        self::true(str_contains($haystack, $needle), $message !== '' ? $message : "Failed asserting that '{$haystack}' contains '{$needle}'");
    }

    /** @param class-string<\Throwable> $exceptionClass */
    public static function throws(string $exceptionClass, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }

            throw new AssertionFailedException($message !== '' ? $message : sprintf(
                'Expected exception %s, got %s: %s',
                $exceptionClass,
                $e::class,
                $e->getMessage(),
            ));
        }

        throw new AssertionFailedException($message !== '' ? $message : "Expected exception {$exceptionClass} was not thrown");
    }
}

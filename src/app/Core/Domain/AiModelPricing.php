<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Price table in US$ per million tokens, which equals micro-USD per token.
 * Unknown models have no cost (null) instead of a guessed one.
 */
final class AiModelPricing
{
    /** @var array<string, array{input: float, output: float, cache_read: float, cache_write: float}> */
    private const PRICES = [
        'claude-opus-5-5' => ['input' => 4.0, 'output' => 20.0, 'cache_read' => 0.20, 'cache_write' => 5.0],
        'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.20, 'cache_write' => 2.50],
        'claude-sonnet-5' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.20, 'cache_write' => 2.50],
        'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0, 'cache_read' => 0.10, 'cache_write' => 1.25],
        'claude-opus-5' => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.50, 'cache_write' => 6.25],
        'claude-opus-4-8' => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.50, 'cache_write' => 6.25],
        'stub' => ['input' => 0.0, 'output' => 0.0, 'cache_read' => 0.0, 'cache_write' => 0.0],
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $model): bool
    {
        return isset(self::PRICES[$model]);
    }

    public static function costMicroUsd(string $model, AiUsage $usage): ?int
    {
        $price = self::PRICES[$model] ?? null;

        if ($price === null) {
            return null;
        }

        return (int) round(
            $usage->inputTokens * $price['input']
            + $usage->outputTokens * $price['output']
            + $usage->cacheReadTokens * $price['cache_read']
            + $usage->cacheWriteTokens * $price['cache_write']
        );
    }
}

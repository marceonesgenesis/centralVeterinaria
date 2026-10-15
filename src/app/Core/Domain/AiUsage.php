<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/** Token usage reported by the provider for one call (value object). */
final class AiUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $cacheReadTokens < 0 || $cacheWriteTokens < 0) {
            throw new InvalidArgumentException('Token counts must not be negative');
        }
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}

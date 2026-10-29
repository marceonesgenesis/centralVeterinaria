<?php

declare(strict_types=1);

namespace CentralVet\Application;

/**
 * Outcome of one DocumentGenerationService::generate() call (Fase 7B):
 * `ready` (with the ids of the `document_ready` e-mails queued for the
 * worker), `skipped` (missing, not queued or claimed by another worker) or
 * `failed` (marked failed; no retry follows).
 */
final class DocumentGenerationResult
{
    public const READY = 'ready';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** @param list<int> $emailMessageIds */
    private function __construct(
        private readonly string $status,
        private readonly array $emailMessageIds,
    ) {
    }

    /** @param list<int> $emailMessageIds */
    public static function ready(array $emailMessageIds): self
    {
        return new self(self::READY, array_values($emailMessageIds));
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED, []);
    }

    public static function failed(): self
    {
        return new self(self::FAILED, []);
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<int> */
    public function emailMessageIds(): array
    {
        return $this->emailMessageIds;
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Application;

/**
 * Outcome of {@see EncounterService::autosave()} (Fase 8A, T-24).
 *
 * `$version` is always the draft version stored at the end of the call:
 * the new one when `$saved`, the current one on a conflict (another window
 * changed the draft since the version the tab sent, or the tab sent none).
 * `$invalidFields` (T-28, correção 1) lists the numeric fields the tab sent
 * with a value that is not a number: they were left out (the stored value
 * stays) and the other fields went through the version guard as usual.
 * `$closed` (T-32) is true when the encounter was already finished under
 * the row lock: nothing was written, `$saved` is false and `$version` is
 * the locked read.
 */
final class DraftSaveResult
{
    public function __construct(
        public readonly bool $saved,
        public readonly string $version,
        /** @var list<string> */
        public readonly array $invalidFields = [],
        public readonly bool $closed = false,
    ) {
    }
}

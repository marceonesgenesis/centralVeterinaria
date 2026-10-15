<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\AiInteraction;
use DateTimeImmutable;

/**
 * Persistence of `ai_interaction`, always scoped to the current tenant.
 */
interface AiInteractionRepositoryInterface
{
    /**
     * Inserts the interaction and returns it with the assigned id.
     *
     * Only an interaction with an outcome can be inserted: call
     * `AiInteraction::recordSuggestion()` or `recordFailure()` first. A
     * `pending_review` row without `suggestion_text` violates
     * `ai_interaction_failed_ck` (migration 0014), as does a `failed` row
     * without `error_code`.
     */
    public function insert(AiInteraction $interaction): AiInteraction;

    /**
     * Conditional UPDATE of the decision (only while `pending_review`).
     *
     * @return bool false when another request decided first or the row is gone.
     */
    public function saveDecision(AiInteraction $interaction): bool;

    public function find(int $id): ?AiInteraction;

    /** Sum of all tokens of the tenant since `$monthStart` (UTC). */
    public function monthlyTokenUsage(DateTimeImmutable $monthStart): int;

    /** @return list<array{purpose: string, requests: int, tokens: int, cost_micro_usd: int}> */
    public function monthlySummary(DateTimeImmutable $monthStart): array;

    /** Most recent `accepted`/`edited` interaction of the subject for the purpose. */
    public function latestDecidedFor(string $purpose, int $subjectId): ?AiInteraction;

    /** @return list<AiInteraction> most recent first */
    public function listRecent(int $limit): array;
}

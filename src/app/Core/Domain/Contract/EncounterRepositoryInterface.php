<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\Encounter;
use CentralVet\Persistence\TenantRepositoryInterface;

/**
 * Persistence boundary for the Encounter aggregate.
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface EncounterRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Lists in-progress encounters for a patient within the current tenant.
     *
     * @return list<TEntity>
     */
    public function listInProgressByPatient(int $patientId): array;

    /**
     * Reads the encounter with a row lock (`SELECT ... FOR UPDATE`, Fase 8A
     * T-28), tenant scoped: the read sees the latest committed row and no
     * other write reaches it until the caller's transaction ends.
     *
     * @throws \LogicException when no transaction is open.
     */
    public function lockForDraft(int $id): ?Encounter;

    /**
     * Writes only the clinical draft columns of $encounter (anamnesis,
     * vitals, exam, diagnosis, plan). save() of an existing encounter never
     * writes them: the draft is written only here.
     */
    public function updateDraft(Encounter $encounter): void;

    /**
     * Writes only `ai_summary_text` and `ai_summary_accepted_at` of
     * $encounter (T-32, correção 1): status, finished_at, pause and draft
     * are never rewritten from the entity, so an accept read before a
     * concurrent finish cannot reopen the encounter.
     */
    public function updateAiSummary(Encounter $encounter): void;
}

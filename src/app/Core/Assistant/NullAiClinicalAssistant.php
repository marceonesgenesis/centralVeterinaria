<?php

declare(strict_types=1);

namespace CentralVet\Assistant;

use CentralVet\Assistant\Contract\AiClinicalAssistantInterface;

/**
 * No-op clinical assistant: the safe default for Phase 2, until a real AI
 * provider adapter is wired in a future phase (Fase 8+). Makes no network
 * call and depends on no external AI provider — every method is a pure,
 * side-effect-free placeholder that always succeeds.
 *
 * Bind this implementation wherever AiClinicalAssistantInterface is needed
 * today (e.g. EncounterView, T-06); swapping to a real adapter later is a
 * wiring/config change only, since both implement the same interface and no
 * consumer depends on this class directly.
 */
final class NullAiClinicalAssistant implements AiClinicalAssistantInterface
{
    public function summarizePatientHistory(int $patientId): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function suggestNextSteps(int $encounterId): array
    {
        return [];
    }
}

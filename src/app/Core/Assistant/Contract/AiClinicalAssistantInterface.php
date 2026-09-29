<?php

declare(strict_types=1);

namespace CentralVet\Assistant\Contract;

/**
 * Port for an AI-backed clinical assistant. No real provider is called in
 * Phase 2 (see NullAiClinicalAssistant); a real adapter can implement this
 * port later without changing EncounterView. Stateless, tenant-agnostic:
 * does not depend on TenantContext or any Adianti class.
 */
interface AiClinicalAssistantInterface
{
    public function summarizePatientHistory(int $patientId): ?string;

    /** @return list<string> */
    public function suggestNextSteps(int $encounterId): array;
}

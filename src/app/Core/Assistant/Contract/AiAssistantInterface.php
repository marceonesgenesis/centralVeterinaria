<?php

declare(strict_types=1);

namespace CentralVet\Assistant\Contract;

use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiSuggestion;
use CentralVet\Domain\Exception\AiInteractionNotFoundException;
use CentralVet\Domain\Exception\AiSubjectNotFoundException;
use CentralVet\Domain\Exception\AiUnavailableException;

/**
 * AI clinical assistant of Fase 8A (implemented by
 * `CentralVet\Application\AiAssistService`). Replaces the Fase 2 no-op
 * assistant port. Every suggestion goes through human review
 * (`decide`) before anything is persisted on the clinical record.
 */
interface AiAssistantInterface
{
    /** @throws AiUnavailableException|AiSubjectNotFoundException */
    public function summarizePatient(int $encounterId): AiSuggestion;

    /** @throws AiUnavailableException|AiSubjectNotFoundException */
    public function organizeAnamnesis(int $encounterId, string $freeText): AiSuggestion;

    /** @throws AiUnavailableException|AiSubjectNotFoundException */
    public function summarizeExamResult(int $examResultId): AiSuggestion;

    /**
     * @param string $decision `accepted`, `edited` or `discarded`
     * @throws AiInteractionNotFoundException
     */
    public function decide(int $interactionId, string $decision, ?string $finalText): AiInteraction;

    public function latestDecided(string $purpose, int $subjectId): ?AiInteraction;

    /** Flag on, terms accepted, provider configured and quota left. */
    public function isAvailable(): bool;
}

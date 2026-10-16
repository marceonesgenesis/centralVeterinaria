<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Closed list of AI use cases. `ALL` holds the three assistant purposes of
 * Fase 8A (suggestion + human decision). `COPILOT` holds the read-only
 * copilot purposes of Fase 8B: their subject is the conversation, they are
 * answered without stored text and they have no decision action.
 * Each purpose has a subject type (the record it reads) and the
 * `Class::method` program actions that authorize the generation and the
 * human decision, so the AI follows the same RBAC as the host screen.
 */
final class AiPurpose
{
    public const PATIENT_SUMMARY = 'patient_summary';
    public const ANAMNESIS_ORGANIZE = 'anamnesis_organize';
    public const EXAM_SUMMARY = 'exam_summary';

    public const ALL = [
        self::PATIENT_SUMMARY,
        self::ANAMNESIS_ORGANIZE,
        self::EXAM_SUMMARY,
    ];

    public const COPILOT_CLINICAL = 'copilot_clinical';
    public const COPILOT_MANAGEMENT = 'copilot_management';

    /** Copilot purposes, kept out of `ALL` (the assistant's exhaustive matches depend on it). */
    public const COPILOT = [
        self::COPILOT_CLINICAL,
        self::COPILOT_MANAGEMENT,
    ];

    public const SUBJECT_ENCOUNTER = 'encounter';
    public const SUBJECT_EXAM_RESULT = 'exam_result';
    public const SUBJECT_CONVERSATION = 'ai_conversation';

    private function __construct()
    {
    }

    public static function assertValid(string $purpose): void
    {
        if (!in_array($purpose, self::ALL, true) && !self::isCopilot($purpose)) {
            throw new InvalidArgumentException('Unknown AI purpose');
        }
    }

    public static function isCopilot(string $purpose): bool
    {
        return in_array($purpose, self::COPILOT, true);
    }

    public static function subjectType(string $purpose): string
    {
        self::assertValid($purpose);

        if (self::isCopilot($purpose)) {
            return self::SUBJECT_CONVERSATION;
        }

        return $purpose === self::EXAM_SUMMARY ? self::SUBJECT_EXAM_RESULT : self::SUBJECT_ENCOUNTER;
    }

    /** `Class::method` authorized before generating a suggestion. */
    public static function hostAction(string $purpose): string
    {
        self::assertValid($purpose);

        return match ($purpose) {
            self::PATIENT_SUMMARY => 'EncounterView::onAiPatientSummary',
            self::ANAMNESIS_ORGANIZE => 'EncounterView::onAiOrganizeAnamnesis',
            self::EXAM_SUMMARY => 'ExamResultForm::onAiExamSummary',
            self::COPILOT_CLINICAL, self::COPILOT_MANAGEMENT => 'AiCopilotPanel::onAsk',
        };
    }

    /**
     * `Class::method` authorized before accepting, editing or discarding a suggestion.
     *
     * @throws InvalidArgumentException for a copilot purpose (answers are not decided).
     */
    public static function decideAction(string $purpose): string
    {
        self::assertValid($purpose);

        if (self::isCopilot($purpose)) {
            throw new InvalidArgumentException('AI purpose has no decision');
        }

        return $purpose === self::EXAM_SUMMARY ? 'ExamResultForm::onAiDecide' : 'EncounterView::onAiDecide';
    }
}

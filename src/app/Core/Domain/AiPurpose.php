<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Closed list of AI use cases of Fase 8A. Each purpose has a subject type
 * (the record it reads) and the `Class::method` program actions that authorize the
 * generation and the human decision, so the AI follows the same RBAC as
 * the host screen.
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

    public const SUBJECT_ENCOUNTER = 'encounter';
    public const SUBJECT_EXAM_RESULT = 'exam_result';

    private function __construct()
    {
    }

    public static function assertValid(string $purpose): void
    {
        if (!in_array($purpose, self::ALL, true)) {
            throw new InvalidArgumentException('Unknown AI purpose');
        }
    }

    public static function subjectType(string $purpose): string
    {
        self::assertValid($purpose);

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
        };
    }

    /** `Class::method` authorized before accepting, editing or discarding a suggestion. */
    public static function decideAction(string $purpose): string
    {
        self::assertValid($purpose);

        return $purpose === self::EXAM_SUMMARY ? 'ExamResultForm::onAiDecide' : 'EncounterView::onAiDecide';
    }
}

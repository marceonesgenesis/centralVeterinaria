<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Fixed surgical safety checklist catalog (Phase 6B): three phases with
 * their item codes and English labels. No database — the codes are the
 * contract with the checklist screen and the translations.
 */
final class SurgeryChecklist
{
    public const PHASE_SIGN_IN = 'sign_in';
    public const PHASE_TIME_OUT = 'time_out';
    public const PHASE_SIGN_OUT = 'sign_out';

    public const PHASES = [
        self::PHASE_SIGN_IN,
        self::PHASE_TIME_OUT,
        self::PHASE_SIGN_OUT,
    ];

    private const PHASE_LABELS = [
        self::PHASE_SIGN_IN => 'Before induction',
        self::PHASE_TIME_OUT => 'Before incision',
        self::PHASE_SIGN_OUT => 'Before leaving the room',
    ];

    /** @var array<string, array<string, string>> phase => [item code => label] */
    private const ITEMS = [
        self::PHASE_SIGN_IN => [
            'patient_identity_confirmed' => 'Patient identity confirmed',
            'consent_confirmed' => 'Consent confirmed',
            'fasting_confirmed' => 'Fasting confirmed',
            'anesthesia_equipment_checked' => 'Anesthesia equipment checked',
            'allergies_reviewed' => 'Allergies reviewed',
        ],
        self::PHASE_TIME_OUT => [
            'team_introduced' => 'Team introduced',
            'procedure_and_site_confirmed' => 'Procedure and site confirmed',
            'antibiotic_prophylaxis_reviewed' => 'Antibiotic prophylaxis reviewed',
            'critical_steps_reviewed' => 'Critical steps reviewed',
        ],
        self::PHASE_SIGN_OUT => [
            'procedure_recorded' => 'Procedure recorded',
            'instrument_count_correct' => 'Instrument count correct',
            'specimens_labeled' => 'Specimens labeled',
            'recovery_plan_defined' => 'Recovery plan defined',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Item codes of a phase, in display order.
     *
     * @return list<string>
     */
    public static function items(string $phase): array
    {
        self::assertPhase($phase);

        return array_keys(self::ITEMS[$phase]);
    }

    public static function label(string $itemCode): string
    {
        foreach (self::ITEMS as $items) {
            if (isset($items[$itemCode])) {
                return $items[$itemCode];
            }
        }

        throw new InvalidArgumentException("Unknown checklist item \"{$itemCode}\"");
    }

    public static function phaseLabel(string $phase): string
    {
        self::assertPhase($phase);

        return self::PHASE_LABELS[$phase];
    }

    /**
     * Ensures every item of the phase is checked and no foreign code is present.
     *
     * @param array<int, string> $checkedItemCodes
     */
    public static function assertComplete(string $phase, array $checkedItemCodes): void
    {
        self::assertPhase($phase);

        foreach ($checkedItemCodes as $code) {
            self::assertItemOfPhase($phase, (string) $code);
        }

        foreach (array_keys(self::ITEMS[$phase]) as $required) {
            if (!in_array($required, $checkedItemCodes, true)) {
                throw new InvalidArgumentException("All checklist items of phase \"{$phase}\" must be checked");
            }
        }
    }

    /**
     * Ensures the phase exists and the item code belongs to it.
     */
    public static function assertItemOfPhase(string $phase, string $itemCode): void
    {
        self::assertPhase($phase);

        if (!isset(self::ITEMS[$phase][$itemCode])) {
            throw new InvalidArgumentException("Unknown checklist item \"{$itemCode}\"");
        }
    }

    private static function assertPhase(string $phase): void
    {
        if (!isset(self::ITEMS[$phase])) {
            throw new InvalidArgumentException("Unknown checklist phase \"{$phase}\"");
        }
    }
}

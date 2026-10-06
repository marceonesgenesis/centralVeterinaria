<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\SurgeryChecklist;
use CentralVet\Domain\SurgeryChecklistItem;
use CentralVet\Domain\SurgeryEvent;
use CentralVet\Domain\SurgeryMaterial;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Unit tests for the Phase 6B surgery checklist Domain (T-03): the fixed
 * 3-phase checklist catalog, SurgeryChecklistItem, SurgeryEvent and
 * SurgeryMaterial. Pure Domain, no database.
 */
final class SurgeryChecklistDomainTest
{
    private function assertThrowsMessage(string $expectedMessage, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same($expectedMessage, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected InvalidArgumentException \"{$expectedMessage}\" was not thrown");
    }

    public function testPhasesAreInContractOrder(): void
    {
        Assert::same(['sign_in', 'time_out', 'sign_out'], SurgeryChecklist::PHASES);
        Assert::same('sign_in', SurgeryChecklist::PHASE_SIGN_IN);
        Assert::same('time_out', SurgeryChecklist::PHASE_TIME_OUT);
        Assert::same('sign_out', SurgeryChecklist::PHASE_SIGN_OUT);
    }

    public function testItemsOfEachPhaseInContractOrder(): void
    {
        Assert::same([
            'patient_identity_confirmed',
            'consent_confirmed',
            'fasting_confirmed',
            'anesthesia_equipment_checked',
            'allergies_reviewed',
        ], SurgeryChecklist::items('sign_in'));
        Assert::same([
            'team_introduced',
            'procedure_and_site_confirmed',
            'antibiotic_prophylaxis_reviewed',
            'critical_steps_reviewed',
        ], SurgeryChecklist::items('time_out'));
        Assert::same([
            'procedure_recorded',
            'instrument_count_correct',
            'specimens_labeled',
            'recovery_plan_defined',
        ], SurgeryChecklist::items('sign_out'));
    }

    public function testUnknownPhaseIsRejected(): void
    {
        $this->assertThrowsMessage('Unknown checklist phase "bogus"', fn () => SurgeryChecklist::items('bogus'));
        $this->assertThrowsMessage('Unknown checklist phase "bogus"', fn () => SurgeryChecklist::phaseLabel('bogus'));
    }

    public function testLabels(): void
    {
        Assert::same('Instrument count correct', SurgeryChecklist::label('instrument_count_correct'));
        Assert::same('Patient identity confirmed', SurgeryChecklist::label('patient_identity_confirmed'));
        Assert::same('Recovery plan defined', SurgeryChecklist::label('recovery_plan_defined'));
        Assert::same('Before induction', SurgeryChecklist::phaseLabel('sign_in'));
        Assert::same('Before incision', SurgeryChecklist::phaseLabel('time_out'));
        Assert::same('Before leaving the room', SurgeryChecklist::phaseLabel('sign_out'));
        $this->assertThrowsMessage('Unknown checklist item "bogus"', fn () => SurgeryChecklist::label('bogus'));

        foreach (SurgeryChecklist::PHASES as $phase) {
            foreach (SurgeryChecklist::items($phase) as $code) {
                Assert::true(SurgeryChecklist::label($code) !== '', "Missing label for {$code}");
            }
        }
    }

    public function testAssertCompleteRequiresEveryItemOfThePhase(): void
    {
        $this->assertThrowsMessage(
            'All checklist items of phase "sign_in" must be checked',
            fn () => SurgeryChecklist::assertComplete('sign_in', ['patient_identity_confirmed']),
        );
        $this->assertThrowsMessage(
            'Unknown checklist item "team_introduced"',
            fn () => SurgeryChecklist::assertComplete('sign_in', [...SurgeryChecklist::items('sign_in'), 'team_introduced']),
        );
        $this->assertThrowsMessage(
            'Unknown checklist phase "bogus"',
            fn () => SurgeryChecklist::assertComplete('bogus', []),
        );

        SurgeryChecklist::assertComplete('time_out', array_reverse(SurgeryChecklist::items('time_out')));
        Assert::true(true);
    }

    public function testChecklistItemCheck(): void
    {
        $at = new DateTimeImmutable('2026-10-05 09:00:00');
        $item = SurgeryChecklistItem::check(1, 7, 'time_out', 'team_introduced', 10, $at);

        Assert::null($item->id());
        Assert::same(1, $item->tenantId());
        Assert::same(7, $item->surgeryId());
        Assert::same('time_out', $item->phase());
        Assert::same('team_introduced', $item->itemCode());
        Assert::same(10, $item->checkedBySystemUserId());
        Assert::same($at, $item->checkedAt());

        $item->assignId(3);
        Assert::same(3, $item->id());

        $this->assertThrowsMessage('Unknown checklist item "team_introduced"', fn () => SurgeryChecklistItem::check(1, 7, 'sign_in', 'team_introduced', 10, $at));
        $this->assertThrowsMessage('Unknown checklist phase "bogus"', fn () => SurgeryChecklistItem::check(1, 7, 'bogus', 'team_introduced', 10, $at));

        $rebuilt = SurgeryChecklistItem::reconstitute([
            'id' => 4,
            'tenant_id' => 1,
            'surgery_id' => 7,
            'phase' => 'sign_out',
            'item_code' => 'specimens_labeled',
            'checked_by_system_user_id' => 10,
            'checked_at' => '2026-10-05 10:00:00.000000',
        ]);
        Assert::same(4, $rebuilt->id());
        Assert::same('specimens_labeled', $rebuilt->itemCode());
    }

    public function testEventRecordValidatesTypeAndNotes(): void
    {
        $at = new DateTimeImmutable('2026-10-05 09:00:00');

        $this->assertThrowsMessage('notes_text is required', fn () => SurgeryEvent::record(1, 7, 'complication', 10, $at, ''));
        $this->assertThrowsMessage('notes_text is required', fn () => SurgeryEvent::record(1, 7, 'anesthesia', 10, $at, null));
        $this->assertThrowsMessage('notes_text must have at most 5000 characters', fn () => SurgeryEvent::record(1, 7, 'intra_op', 10, $at, str_repeat('a', 5001)));
        $this->assertThrowsMessage('Unknown surgery event type "bogus"', fn () => SurgeryEvent::record(1, 7, 'bogus', 10, $at, 'x'));

        Assert::same(['pre_op', 'anesthesia', 'intra_op', 'complication', 'post_op'], SurgeryEvent::CLINICAL_TYPES);

        $event = SurgeryEvent::record(1, 7, SurgeryEvent::TYPE_STATUS, 10, $at, null);
        Assert::null($event->notesText());
        Assert::same('status', $event->eventType());
        Assert::same(7, $event->surgeryId());
        Assert::same(10, $event->recordedBySystemUserId());
        Assert::same($at, $event->recordedAt());

        $clinical = SurgeryEvent::record(1, 7, SurgeryEvent::TYPE_POST_OP, 10, $at, '  Recuperação tranquila  ');
        Assert::same('Recuperação tranquila', $clinical->notesText());

        $long = SurgeryEvent::record(1, 7, SurgeryEvent::TYPE_PRE_OP, 10, $at, str_repeat('é', 5000));
        Assert::same(5000, mb_strlen((string) $long->notesText()));
    }

    public function testMaterialRecordValidatesQuantity(): void
    {
        $at = new DateTimeImmutable('2026-10-05 09:00:00');

        $this->assertThrowsMessage('quantity must be between 1 and 9999', fn () => SurgeryMaterial::record(1, 7, 2, 0, 10, $at));
        $this->assertThrowsMessage('quantity must be between 1 and 9999', fn () => SurgeryMaterial::record(1, 7, 2, 10000, 10, $at));

        $material = SurgeryMaterial::record(1, 7, 2, 9999, 10, $at);
        Assert::null($material->id());
        Assert::same(7, $material->surgeryId());
        Assert::same(2, $material->productId());
        Assert::same(9999, $material->quantity());
        Assert::same($at, $material->recordedAt());

        $material->assignId(5);
        Assert::same(5, $material->id());
    }
}

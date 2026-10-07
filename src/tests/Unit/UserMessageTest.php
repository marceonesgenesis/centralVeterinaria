<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Rodada 2, T-28: catálogo de mensagens de domínio (inglês, cru) → chave _t
 * com parâmetros ^1/^2, usado por CvFormat::userError/userMessage.
 */
final class UserMessageTest
{
    public function testStaticMessageResolvesToItselfWithoutParams(): void
    {
        $resolved = UserMessage::resolve('Patient sex must be one of M, F, U');

        Assert::same(['key' => 'Patient sex must be one of M, F, U', 'params' => []], $resolved);
    }

    public function testPatternCapturesTheEncounterId(): void
    {
        $resolved = UserMessage::resolve('Encounter 3408 is finished and cannot be paused');

        Assert::same(['key' => 'Encounter ^1 is finished and cannot be paused', 'params' => ['3408']], $resolved);
    }

    public function testPatternKeepsTheRawNameForLaterEscaping(): void
    {
        $resolved = UserMessage::resolve('A bank account named "<b>x</b>" already exists for this unit');

        Assert::same(['key' => 'A bank account named "^1" already exists for this unit', 'params' => ['<b>x</b>']], $resolved);
    }

    public function testPatternWithoutCaptureDropsTheId(): void
    {
        Assert::same(['key' => 'Bank account not found', 'params' => []], UserMessage::resolve('Bank account 12 not found for this tenant'));
        // A regex do contrato captura o id, mas a chave não usa ^1: o id não aparece no texto.
        Assert::same(['key' => 'This appointment is already in the queue', 'params' => ['7']], UserMessage::resolve('Appointment 7 is already in the queue'));
    }

    public function testPatternWithTwoCaptures(): void
    {
        $resolved = UserMessage::resolve('Appointment 5 cannot be rescheduled from status finished');

        Assert::same(['key' => 'Appointment ^1 cannot be rescheduled from status ^2', 'params' => ['5', 'finished']], $resolved);
    }

    public function testSchedulingConflictDropsSlotAndProfessional(): void
    {
        $resolved = UserMessage::resolve('Requested slot 2026-09-30 14:00-14:30 conflicts with an existing appointment for professional_system_user_id 1');

        Assert::same(['key' => 'Requested slot conflicts with an existing appointment', 'params' => []], $resolved);
    }

    public function testInvalidDateAndTimeIsCatalogued(): void
    {
        Assert::same(['key' => 'Invalid date and time', 'params' => []], UserMessage::resolve('Invalid date and time'));
    }

    public function testInvalidFileIsCatalogued(): void
    {
        Assert::same(['key' => 'Invalid file', 'params' => []], UserMessage::resolve('Invalid file'));
    }

    public function testExamRequestTransitionDropsIdAndStatus(): void
    {
        $key = ['key' => 'This exam request cannot move to this status', 'params' => []];

        Assert::same($key, UserMessage::resolve('Exam request 312 cannot move to "result_available" from status "result_available"'));
        Assert::same($key, UserMessage::resolve('Exam request (new) cannot move to "result_available" from status "cancelled"'));
    }

    public function testUnknownMessageResolvesToNull(): void
    {
        Assert::null(UserMessage::resolve('qualquer outra'));
        Assert::null(UserMessage::resolve('Encounter 3408 is finished and cannot be paused!'));
    }

    public function testPatternsRejectTrailingNewline(): void
    {
        // Rodada 3, T-01: sem /D, `$` aceita um "\n" final e a mensagem cairia no catálogo.
        Assert::null(UserMessage::resolve("Encounter 5 is not paused\n"));
    }

    public function testDomainMessagesOutsideTheCatalogResolve(): void
    {
        Assert::same(['key' => 'Record not found', 'params' => []], UserMessage::resolve('Patient 12 not found for this tenant'));
        Assert::same(['key' => '^1 is required', 'params' => ['scheduled_at']], UserMessage::resolve('scheduled_at is required'));
        Assert::same(['key' => 'Encounter ^1 is already paused', 'params' => ['(new)']], UserMessage::resolve('Encounter (new) is already paused'));
        Assert::same(['key' => 'Fill in ^1 on every item', 'params' => ['dosage']], UserMessage::resolve('items[].dosage is required'));
        Assert::same(['key' => 'Bank account must belong to the current unit', 'params' => []], UserMessage::resolve('Bank account must belong to the current unit 3'));
    }

    public function testNameMarkupMessageIsCatalogued(): void
    {
        // Rodada 3, T-14: NameText::MARKUP_MESSAGE.
        Assert::same(['key' => 'Name must not contain < or >', 'params' => []], UserMessage::resolve('Name must not contain < or >'));
    }

    public function testMoneyAndStockMessagesResolveWithoutCentsOrColumnNames(): void
    {
        // Rodada 3, T-18 correção 1: mensagens reais vistas no PaymentForm, EncounterAccountForm e SaleForm.
        Assert::same(
            ['key' => 'The payment exceeds the open balance', 'params' => []],
            UserMessage::resolve('Payment of 9999900 cent(s) would raise paid_cents to 10000000, exceeding total_cents of 4500 cent(s)'),
        );
        Assert::same(
            ['key' => 'The discount cannot be greater than the subtotal', 'params' => []],
            UserMessage::resolve('Discount of 99900 cent(s) exceeds subtotal of 7000 cent(s)'),
        );
        Assert::same(
            ['key' => 'Insufficient stock: ^1 unit(s) missing', 'params' => ['9999']],
            UserMessage::resolve('Insufficient stock for product_id 8545: short by 9999 unit(s)'),
        );
    }

    public function testCatalogHasExactlyTheContractEntries(): void
    {
        Assert::count(47, UserMessage::STATIC);
        Assert::count(64, UserMessage::PATTERNS);

        foreach (UserMessage::STATIC as $message => $key) {
            Assert::same($message, $key);
        }
    }

    public function testHospitalizationDomainMessagesResolve(): void
    {
        // Fase 6A, T-18: mensagens de leito, internação, prescrição e administração.
        Assert::same(['key' => 'This bed is not available', 'params' => []], UserMessage::resolve('Bed 7 is not available'));
        Assert::same(['key' => 'This bed is occupied and cannot be deactivated', 'params' => []], UserMessage::resolve('Bed 7 is occupied and cannot be deactivated'));
        Assert::same(['key' => 'This administration is no longer pending', 'params' => []], UserMessage::resolve('Administration 3 is not pending'));
        Assert::same(['key' => 'This prescription is no longer active', 'params' => []], UserMessage::resolve('Order 4 is not active'));
        Assert::same(['key' => 'This hospitalization is no longer active', 'params' => []], UserMessage::resolve('Hospitalization 9 is not admitted'));
        Assert::same(['key' => 'This patient is already hospitalized', 'params' => []], UserMessage::resolve('Patient 1452 already has an active hospitalization'));
        Assert::same(['key' => 'The bed is not occupied by this hospitalization', 'params' => []], UserMessage::resolve('Bed 2 is not occupied by hospitalization 9'));
        Assert::same(['key' => 'The encounter account is not open', 'params' => []], UserMessage::resolve('Encounter account 46 cannot be modified: status is "closed", not "open"'));
        Assert::same(
            ['key' => 'A bed with code "^1" already exists in this unit', 'params' => ['<b>L1</b>']],
            UserMessage::resolve('A bed with code "<b>L1</b>" already exists in this unit'),
        );
    }

    public function testHospitalizationRecordsNotFoundResolve(): void
    {
        $notFound = ['key' => 'Record not found', 'params' => []];

        foreach (['Bed 7', 'Hospitalization 9', 'Administration 3', 'Order 4'] as $record) {
            Assert::same($notFound, UserMessage::resolve("{$record} not found for this tenant"));
        }
        Assert::same($notFound, UserMessage::resolve('hospitalization_id 9 was not found for the authenticated tenant'));
        Assert::same($notFound, UserMessage::resolve('product_id 8545 was not found among the active products of the authenticated tenant'));
    }

    public function testHospitalizationValidationMessagesResolve(): void
    {
        foreach ([
            'ends_at must be after starts_at',
            'Prescription period cannot exceed 30 days',
            'frequency_hours must be between 1 and 168',
            'quantity_per_administration is required when a product is selected',
            'At least one vital sign is required',
            'pain_score must be between 0 and 10',
            'responsible_system_user_id must be an active user of this tenant',
        ] as $message) {
            Assert::same(['key' => $message, 'params' => []], UserMessage::resolve($message));
        }
        Assert::same(['key' => '^1 must be a positive integer', 'params' => ['frequency_hours']], UserMessage::resolve('frequency_hours must be a positive integer'));
        Assert::same(['key' => '^1 must be a whole number', 'params' => ['pain_score']], UserMessage::resolve('pain_score must be a whole number'));
        Assert::same(['key' => '^1 must be a number', 'params' => ['weight_kg']], UserMessage::resolve('weight_kg must be a number'));
    }

    public function testHospitalizationReachableMessagesResolveWithoutFieldNames(): void
    {
        // T-18 correção 1: o form de parâmetros aceita "-" e o domínio recusa negativo; recusas restantes da varredura.
        foreach ([
            'temperature_c cannot be negative',
            'heart_rate_bpm cannot be negative',
            'respiratory_rate_rpm cannot be negative',
            'weight_kg cannot be negative',
            'from_bed_id and to_bed_id are required for a transfer',
            'bed_id must be positive',
            'responsible_system_user_id must be positive',
        ] as $message) {
            Assert::same(['key' => $message, 'params' => []], UserMessage::resolve($message));
        }
        Assert::same(['key' => 'Invalid route', 'params' => []], UserMessage::resolve('Unknown route "nasal"'));
        Assert::same(['key' => 'Invalid prescription type', 'params' => []], UserMessage::resolve('Unknown order_type "x"'));
    }

    public function testSurgeryDomainMessagesResolveWithoutInternalIds(): void
    {
        // Fase 6B, T-19: sala, status da cirurgia, checklist, equipe e materiais.
        $english = self::translatedKeys();
        $cases = [
            'Surgery room 3 is already booked for this period' => 'This surgery room is already booked for this period',
            'Surgery room 3 is not active' => 'This surgery room is not active',
            'Surgery room 3 belongs to another unit' => 'This surgery room belongs to another unit',
            'Surgery 9 is not scheduled' => 'This surgery is not scheduled',
            'Surgery 9 is not in pre-op' => 'This surgery is not in pre-op',
            'Surgery 9 is not in progress' => 'This surgery is not in progress',
            'Surgery 9 is not completed' => 'This surgery is not completed',
            'Surgery 9 is cancelled' => 'This surgery is cancelled',
            'Surgery 9 has no recorded consent' => 'This surgery has no recorded consent',
            'Surgery 9 is not open for pre-operative changes' => 'This surgery is no longer open for pre-operative changes',
            'Surgery 9 cannot be cancelled in its current status' => 'This surgery cannot be cancelled in its current status',
            'Surgery 9 changed status concurrently' => 'This surgery was changed by someone else; reload it and try again',
            'Surgery 9 already has a follow-up appointment' => 'This surgery already has a follow-up appointment',
            'Checklist phase "sign_in" is already confirmed for surgery 9' => 'This checklist phase is already confirmed',
            'Checklist phase "sign_in" is not confirmed for surgery 9' => 'The "Before induction" checklist phase is not confirmed',
            'Checklist phase "time_out" is not confirmed for surgery 9' => 'The "Before incision" checklist phase is not confirmed',
            'Checklist phase "sign_out" is not confirmed for surgery 9' => 'The "Before leaving the room" checklist phase is not confirmed',
            'Checklist phase "time_out" cannot be confirmed while surgery 9 is scheduled' => 'This checklist phase cannot be confirmed in the current surgery status',
            'All checklist items of phase "sign_out" must be checked' => 'All items of this checklist phase must be checked',
            'Team member 12 must be an active user of this tenant' => 'Every team member must be an active user of this tenant',
            'Material 4 was already removed' => 'This material was already removed',
            'Unknown surgery event type "x"' => 'Unknown surgery event type',
            'Unknown checklist phase "x"' => 'Unknown checklist phase',
            'Unknown checklist item "x"' => 'Unknown checklist item',
            'Unknown team role "x"' => 'Unknown team role',
        ];

        foreach ($cases as $message => $key) {
            $resolved = UserMessage::resolve($message);
            Assert::same($key, $resolved['key'] ?? null, "Wrong key for: {$message}");
            Assert::true(isset($english[$key]), "Missing translation for catalog key: {$key}");
        }

        Assert::same(
            ['key' => 'A surgery room with code "^1" already exists in this unit', 'params' => ['<b>S1</b>']],
            UserMessage::resolve('A surgery room with code "<b>S1</b>" already exists in this unit'),
        );
    }

    public function testSurgeryValidationMessagesAreCatalogued(): void
    {
        $translations = [];
        foreach (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true) as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach ([
            'Surgery duration cannot exceed 24 hours',
            'duration_minutes must be between 15 and 1440',
            'scheduled_end_at must be after scheduled_start_at',
            'quantity must be between 1 and 9999',
            'consent_signer_name is required',
            'consent_text is required',
            'cancellation_reason_text is required',
            'surgeon_system_user_id must be an active user of this tenant',
        ] as $message) {
            Assert::same(['key' => $message, 'params' => []], UserMessage::resolve($message));
            $pt = $translations[$message] ?? '';
            Assert::true($pt !== '' && !str_contains($pt, '_'), "Translation for {$message} must not show field names");
        }
    }

    public function testCatalogTranslationsDoNotShowTechnicalFieldNames(): void
    {
        $translations = [];
        foreach (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true) as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (['temperature_c', 'heart_rate_bpm', 'respiratory_rate_rpm', 'weight_kg'] as $field) {
            $pt = $translations["{$field} cannot be negative"] ?? '';
            Assert::true($pt !== '' && !str_contains($pt, '_'), "Translation for {$field} cannot be negative must name the field in Portuguese");
        }
    }

    public function testEveryCatalogKeyHasATranslation(): void
    {
        $english = self::translatedKeys();

        foreach (array_merge(array_values(UserMessage::STATIC), array_values(UserMessage::PATTERNS)) as $key) {
            Assert::true(isset($english[$key]), "Missing translation for catalog key: {$key}");
        }
    }

    public function testEveryHospitalizationScreenKeyHasATranslation(): void
    {
        // Rótulos de tela de T-12..T-17: _t('...') dos controllers, _t{...} do menu e rótulos de CvNav/PLAN_ACTIONS.
        self::assertScreenKeysTranslated(['Board', 'Beds', 'Hospitalization', 'Hospitalize'], ['Bed*.php', 'Hospitalization*.php'], 8);
    }

    public function testEverySurgeryScreenKeyHasATranslation(): void
    {
        // Fase 6B, T-19: _t('...') das telas Surgery*, SurgeryAgendaView, menu, CvNav, PLAN_ACTIONS,
        // badges de status, fases e itens do checklist e o texto padrão do consentimento.
        $keys = [
            'Surgeries', 'Surgery rooms', 'Rooms', 'Schedule surgery', 'Surgery consent default text',
            'Scheduled', 'Pre-op', 'In progress', 'Completed', 'Cancelled',
        ];
        foreach (\CentralVet\Domain\SurgeryChecklist::PHASES as $phase) {
            $keys[] = \CentralVet\Domain\SurgeryChecklist::phaseLabel($phase);
            foreach (\CentralVet\Domain\SurgeryChecklist::items($phase) as $code) {
                $keys[] = \CentralVet\Domain\SurgeryChecklist::label($code);
            }
        }
        self::assertScreenKeysTranslated($keys, ['Surgery*.php', '../../Core/Presentation/SurgeryAgendaView.php'], 10);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $patterns globs relative to app/control/clinic
     */
    private static function assertScreenKeysTranslated(array $keys, array $patterns, int $minFiles): void
    {
        $english = self::translatedKeys();
        $files = [];
        foreach ($patterns as $pattern) {
            $files = array_merge($files, glob(dirname(__DIR__, 2) . '/app/control/clinic/' . $pattern) ?: []);
        }
        Assert::true(count($files) >= $minFiles, 'Screen controllers not found');

        foreach ($files as $file) {
            preg_match_all("/_t\(\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $keys[] = stripslashes($key);
            }
        }

        foreach (array_unique($keys) as $key) {
            Assert::true(isset($english[$key]), "Missing translation for screen key: {$key}");
        }
    }

    /**
     * @return array<string, true>
     */
    private static function translatedKeys(): array
    {
        $entries = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($entries), 'translations.json must be a JSON list');

        $english = [];
        foreach ($entries as $entry) {
            $english[$entry['en']] = true;
        }

        return $english;
    }
}

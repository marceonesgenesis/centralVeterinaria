<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Encounter;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use CentralVet\Tests\Support\FakeEncounterRepository;
use CentralVet\Tests\Support\NullPdo;
use DateTimeImmutable;

/**
 * Unit tests for EncounterService (T-08), against FakeEncounterRepository —
 * no database involved, since the `encounter` table does not exist yet
 * (migration T-01 not applied) — and the same FakeAuthorizationPolicy double
 * Phase 1's AppointmentServiceTest/QueueEntryServiceTest already use.
 *
 * timeline() is intentionally NOT covered, documented instead of faked or
 * skipped silently: it is the one EncounterService method that bypasses
 * EncounterRepositoryInterface entirely and reads `audit_log` straight
 * through the constructor's raw PDO connection (see EncounterService's own
 * class docblock for why no Repository indirection exists for it). PDO is a
 * concrete class, not an interface/port, so there is no seam to substitute a
 * test double through without either (a) opening a real database connection
 * — turning this into a database-dependent Integration test, inconsistent
 * with every other scenario in this file, and requiring audit_log rows this
 * suite does not own or seed for a synthetic encounter id — or (b)
 * subclassing PDO to fabricate PDOStatement results, which would mostly test
 * the fake's own plumbing rather than timeline() itself. NullPdo below
 * covers only the narrow, legitimate need shared by every test in this file:
 * satisfying the constructor's PDO type without ever calling a method on it.
 */
final class EncounterServiceTest
{
    private const ACTION = 'test::action';

    public function testStartThrowsAuthorizationDeniedWhenPolicyDeniesAndPersistsNothing(): void
    {
        $repository = new FakeEncounterRepository(1);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        $service = $this->makeService($repository, $policy);

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->start([
                'patient_id' => 1,
                'professional_system_user_id' => 10,
                'system_unit_id' => 1,
            ], self::ACTION),
        );

        Assert::null($repository->findById(1));
    }

    /**
     * finish() takes no unit parameter at all — the only unit it can check
     * is the one already persisted on the loaded Encounter. Proven two ways:
     * (1) the denial still fires and nothing is mutated, and (2) the
     * AuthorizationRequest the fake policy recorded carries the encounter's
     * OWN system_unit_id (5), never the caller's active unit (1, from the
     * denying service's own TenantContext) — there is no other source
     * finish() could have read that value from.
     */
    public function testFinishThrowsAuthorizationDeniedWhenPolicyDeniesUsingEncounterRealUnit(): void
    {
        $repository = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $repository->save($encounter);
        $encounterId = $encounter->id();

        $policy = new FakeAuthorizationPolicy(allowed: false);
        // Caller's active unit (1) deliberately differs from the
        // encounter's own unit (5), so a check that mistakenly used the
        // caller's unit instead of the encounter's would send a different
        // resourceUnitId than the assertion below expects.
        $service = $this->makeService($repository, $policy, TenantContext::authenticated(1, 1, 1));

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->finish($encounterId, self::ACTION),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());

        $reloaded = $repository->findById($encounterId);
        Assert::same(Encounter::STATUS_IN_PROGRESS, $reloaded->status());
        Assert::null($reloaded->finishedAt());
    }

    public function testAutosaveUpdatesOnlyInformedFieldsPreservingOthers(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();

        $first = $service->autosave($id, [
            'diagnosis_text' => 'Otite externa',
            'weight_kg' => 12.5,
        ], $repository->findById($id)->draftVersion());

        $second = $service->autosave($id, [
            'anamnesis_text' => 'Cocando a orelha ha 3 dias',
        ], $first->version);
        Assert::true($first->saved && $second->saved);

        $updated = $repository->findById($id);

        Assert::same('Cocando a orelha ha 3 dias', $updated->anamnesisText());
        Assert::same('Otite externa', $updated->diagnosisText());
        Assert::same('12.50', $updated->weightKg(), 'weight is stored at the column scale');
        Assert::null($updated->physicalExamText());
        Assert::null($updated->clinicalPlanText());
        Assert::null($updated->mucousMembranes());
    }

    /**
     * Fase 8A, T-24: a tick with the version this tab holds is stored and
     * answers the new version; the encounter changes only in the posted
     * fields.
     */
    public function testAutosaveWithCurrentVersionSavesAndReturnsTheNewVersion(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();

        $result = $service->autosave($id, ['anamnesis_text' => 'Tosse ha 2 dias'], $base);

        Assert::true($result->saved);
        $stored = $repository->findById($id);
        Assert::same('Tosse ha 2 dias', $stored->anamnesisText());
        Assert::same($stored->draftVersion(), $result->version, 'the answered version is the stored one');
        Assert::true($result->version !== $base, 'a changed draft changes the version');
    }

    /** Review Focus (T-24, reprodução 1): resposta perdida não trava a aba. */
    public function testAutosaveAfterLostResponseSavesWhenPreviousDraftIsStored(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();
        $tick1 = ['anamnesis_text' => 'Tick 1', 'diagnosis_text' => 'Otite'];

        // Tick 1 is stored, but its response never reaches the tab.
        Assert::true($service->autosave($id, $tick1, $base)->saved);

        // Tick 2 still carries the old version, plus what tick 1 sent.
        $result = $service->autosave($id, ['anamnesis_text' => 'Tick 2', 'diagnosis_text' => 'Otite'], $base, $tick1);

        Assert::true($result->saved, 'a lost response must not turn the next tick into a conflict');
        Assert::same('Tick 2', $repository->findById($id)->anamnesisText());
        Assert::same($repository->findById($id)->draftVersion(), $result->version);
    }

    /** Review Focus (T-24, reprodução 2): aba desatualizada não grava nenhum campo. */
    public function testAutosaveFromStaleTabWritesNoDraftField(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();

        $other = [
            'anamnesis_text' => 'Anamnese da outra aba',
            'physical_exam_text' => 'Exame da outra aba',
            'diagnosis_text' => 'Diagnostico da outra aba',
            'clinical_plan_text' => 'Plano da outra aba',
            'weight_kg' => '12.50',
        ];
        Assert::true($service->autosave($id, $other, $base)->saved);
        $current = $repository->findById($id)->draftVersion();

        $result = $service->autosave($id, [
            'anamnesis_text' => 'Anamnese da aba velha',
            'physical_exam_text' => 'Exame da aba velha',
            'diagnosis_text' => 'Diagnostico da aba velha',
            'clinical_plan_text' => 'Plano da aba velha',
            'weight_kg' => '9',
        ], $base);

        Assert::false($result->saved);
        Assert::same($current, $result->version, 'a conflict answers the stored version');
        $stored = $repository->findById($id);
        Assert::same('Anamnese da outra aba', $stored->anamnesisText());
        Assert::same('Exame da outra aba', $stored->physicalExamText());
        Assert::same('Diagnostico da outra aba', $stored->diagnosisText());
        Assert::same('Plano da outra aba', $stored->clinicalPlanText());
        Assert::same('12.50', $stored->weightKg());
    }

    /**
     * T-28: the draft is read with the row lock (lockForDraft) and written
     * by updateDraft, only the draft columns; no conditional UPDATE by
     * `updated_at` and no second read.
     */
    public function testAutosaveLocksTheRowAndWritesOnlyTheDraft(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();

        $result = $service->autosave($id, ['anamnesis_text' => 'Texto da aba', 'diagnosis_text' => 'Diagnostico da aba'], $base);

        Assert::true($result->saved);
        Assert::same(1, $repository->locks, 'the draft is read with lockForDraft');
        Assert::same(1, $repository->draftUpdates, 'the write is updateDraft');
        $stored = $repository->findById($id);
        Assert::same('Texto da aba', $stored->anamnesisText());
        Assert::same($stored->draftVersion(), $result->version);
    }

    /** T-28 (c): "38,5" is stored as 38.5 and the next tick with the answered version is saved. */
    public function testAutosaveNormalizesDecimalCommaAndTheNextTickIsSaved(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();
        $vitals = ['temperature_c' => '38,5', 'weight_kg' => '12,5', 'capillary_refill_seconds' => '2', 'heart_rate_bpm' => '80'];

        $first = $service->autosave($id, $vitals, $base);

        Assert::true($first->saved);
        $stored = $repository->findById($id);
        Assert::same('38.5', $stored->temperatureC());
        Assert::same('12.50', $stored->weightKg());
        Assert::same('2.0', $stored->capillaryRefillSeconds());
        Assert::same(80, $stored->heartRateBpm());
        Assert::same($stored->draftVersion(), $first->version, 'the answered version matches the stored row');

        $second = $service->autosave($id, $vitals + ['anamnesis_text' => 'Febre'], $first->version);

        Assert::true($second->saved, 'the tick after "38,5" must not be a conflict');
        Assert::same('Febre', $repository->findById($id)->anamnesisText());
    }

    /** T-28 (c): a vital sign that is not a number is refused, never hashed raw. */
    public function testApplyDraftRefusesANonNumericVitalSign(): void
    {
        $encounter = Encounter::start(1, 1, 1, null, 10, new DateTimeImmutable('2026-10-15 09:00:00'));

        Assert::throws(\InvalidArgumentException::class, static fn () => $encounter->applyDraft(['temperature_c' => 'trinta e oito']));
        Assert::throws(\InvalidArgumentException::class, static fn () => $encounter->applyDraft(['weight_kg' => '1.234,5']));

        $encounter->applyDraft(['temperature_c' => ' ', 'weight_kg' => null]);
        Assert::null($encounter->temperatureC(), 'blank is null');
        Assert::null($encounter->weightKg());
    }

    /**
     * T-28, correção 1: an invalid number is not written (keeps the stored
     * value) and does not stop the other fields; the result names it.
     */
    public function testAutosaveSkipsAnInvalidNumberAndSavesTheOtherFields(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $first = $service->autosave($id, ['temperature_c' => '38,5'], $repository->findById($id)->draftVersion());
        Assert::true($first->saved);

        $tick = ['temperature_c' => 'abc', 'weight_kg' => '1.234,5', 'anamnesis_text' => 'Anamnese nova', 'heart_rate_bpm' => '90'];
        $result = $service->autosave($id, $tick, $first->version);

        Assert::true($result->saved, 'an invalid number must not stop the tick');
        $stored = $repository->findById($id);
        Assert::same('Anamnese nova', $stored->anamnesisText());
        Assert::same(90, $stored->heartRateBpm());
        Assert::same('38.5', $stored->temperatureC(), 'the invalid field keeps the stored value');
        Assert::null($stored->weightKg());
        Assert::same(['temperature_c', 'weight_kg'], $result->invalidFields);
        Assert::same($stored->draftVersion(), $result->version, 'the answered version is what was written');

        // The next tick still carries the invalid value, with the lost answer's tick as previous_draft.
        $next = $service->autosave($id, $tick + ['diagnosis_text' => 'Otite'], $first->version, $tick);
        Assert::false($next->saved, 'previous_draft does not vouch for diagnosis_text, which it did not carry');
        $next = $service->autosave($id, ['temperature_c' => 'abc', 'anamnesis_text' => 'Anamnese 2'], $first->version, $tick);
        Assert::true($next->saved, 'an invalid value in previous_draft is skipped the same way');
        Assert::same(['temperature_c'], $next->invalidFields);
        Assert::same('Anamnese 2', $repository->findById($id)->anamnesisText());
    }

    /**
     * T-28, correção 2: a number the column cannot hold (decimal(4,1),
     * decimal(6,2), int unsigned) is invalid like "abc": left out, the other
     * fields saved.
     */
    public function testAutosaveSkipsANumberOutOfTheColumnRange(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();

        $result = $service->autosave($id, [
            'temperature_c' => '3855',
            'heart_rate_bpm' => '-5',
            'respiratory_rate_mpm' => '4294967296',
            'weight_kg' => '12500',
            'capillary_refill_seconds' => '999,96',
            'anamnesis_text' => 'Anamnese nova',
        ], $base);

        Assert::true($result->saved);
        Assert::same(['temperature_c', 'heart_rate_bpm', 'respiratory_rate_mpm', 'weight_kg', 'capillary_refill_seconds'], $result->invalidFields);
        $stored = $repository->findById($id);
        Assert::same('Anamnese nova', $stored->anamnesisText());
        Assert::null($stored->temperatureC());
        Assert::null($stored->heartRateBpm());
        Assert::null($stored->weightKg());

        $edge = $service->autosave($id, ['temperature_c' => '999,9', 'weight_kg' => '9999.99', 'heart_rate_bpm' => '0'], $result->version);
        Assert::same([], $edge->invalidFields, 'the column limits themselves are valid');
        Assert::same('999.9', $repository->findById($id)->temperatureC());
        Assert::same('9999.99', $repository->findById($id)->weightKg());
    }

    /**
     * T-32 (1): `finish` in another tab, then a tick with the current
     * version: nothing of the draft is written and the answer is `closed`.
     */
    public function testAutosaveOnAFinishedEncounterIsClosedAndWritesNothing(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $first = $service->autosave($id, ['anamnesis_text' => 'Antes do fim', 'weight_kg' => '8'], $repository->findById($id)->draftVersion());
        Assert::true($first->saved);

        $service->finish($id, self::ACTION);
        $updates = $repository->draftUpdates;

        $result = $service->autosave($id, ['anamnesis_text' => 'Depois do fim', 'weight_kg' => '9', 'diagnosis_text' => 'Otite'], $first->version);

        $stored = $repository->findById($id);
        Assert::same('Antes do fim', $stored->anamnesisText(), 'a finished encounter takes no draft');
        Assert::same('8.00', $stored->weightKg());
        Assert::null($stored->diagnosisText());
        Assert::same($updates, $repository->draftUpdates, 'updateDraft is not called');
        Assert::false($result->saved);
        Assert::true($result->closed, 'the answer is closed');
        Assert::same($stored->draftVersion(), $result->version, 'closed carries the locked version');
    }

    /**
     * T-32, correção 1 (b): the summary accept reads the encounter under the
     * row lock and writes only the summary columns; a finished encounter
     * takes no summary.
     */
    public function testAcceptAiSummaryOnAFinishedEncounterIsRefusedAndWritesOnlyTheSummary(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $locks = $repository->locks;

        $service->acceptAiSummary($id, 'Resumo aceito');
        Assert::same($locks + 1, $repository->locks, 'the summary accept reads with the row lock');
        Assert::same('Resumo aceito', $repository->findById($id)->aiSummaryText());

        $service->finish($id, self::ACTION);

        Assert::throws(InvalidStatusTransitionException::class, static fn () => $service->acceptAiSummary($id, 'Outro resumo'));
        $stored = $repository->findById($id);
        Assert::same('Resumo aceito', $stored->aiSummaryText(), 'a finished encounter keeps its summary');
        Assert::true($stored->isFinished());
    }

    /** T-32 (4): negative vitals are invalid like "abc"; zero is accepted. */
    public function testAutosaveRefusesNegativeVitalsAndAcceptsZero(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();

        $result = $service->autosave($id, [
            'temperature_c' => '-1',
            'weight_kg' => '-3',
            'capillary_refill_seconds' => '-0,5',
            'anamnesis_text' => 'Anamnese',
        ], $repository->findById($id)->draftVersion());

        Assert::true($result->saved);
        Assert::same(['temperature_c', 'weight_kg', 'capillary_refill_seconds'], $result->invalidFields);
        $stored = $repository->findById($id);
        Assert::null($stored->temperatureC(), 'a negative temperature is not written');
        Assert::null($stored->weightKg(), 'a negative weight is not written');
        Assert::null($stored->capillaryRefillSeconds(), 'a negative refill time is not written');

        $zero = $service->autosave($id, ['temperature_c' => '0', 'weight_kg' => '0', 'capillary_refill_seconds' => '0'], $result->version);
        Assert::same([], $zero->invalidFields, 'zero is valid');
        Assert::same('0.00', $repository->findById($id)->weightKg());
    }

    /**
     * T-32 (5): tick 1 (unanswered) sent an invalid number; tick 2 fixes it
     * with the old version and tick 1 as previous_draft: saved.
     */
    public function testTickFixingTheInvalidNumberOfTheInFlightTickIsSaved(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();
        $tick1 = ['temperature_c' => 'abc', 'anamnesis_text' => 'Tick 1'];

        Assert::true($service->autosave($id, $tick1, $base)->saved);

        $result = $service->autosave($id, ['temperature_c' => '38,5', 'anamnesis_text' => 'Tick 1'], $base, $tick1);

        Assert::true($result->saved, 'fixing the in-flight invalid number must not be a conflict');
        Assert::same('38.5', $repository->findById($id)->temperatureC());
        Assert::same([], $result->invalidFields);
    }

    /** T-28 (d): pause, resume and the accepted summary between two ticks keep the draft version. */
    public function testLifecycleBetweenTicksKeepsTheDraftVersion(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $first = $service->autosave($id, ['anamnesis_text' => 'Tick 1', 'temperature_c' => '38,5'], $repository->findById($id)->draftVersion());
        Assert::true($first->saved);

        $service->pause($id, self::ACTION, new DateTimeImmutable('2026-10-15 10:00:00'));
        $service->acceptAiSummary($id, 'Resumo aceito');
        Assert::same($first->version, $repository->findById($id)->draftVersion(), 'pause and summary do not change the draft version');

        $second = $service->autosave($id, ['anamnesis_text' => 'Tick 2', 'temperature_c' => '38,5'], $first->version);

        Assert::true($second->saved, 'a tick after pause must not be a conflict');
        $stored = $repository->findById($id);
        Assert::same('Tick 2', $stored->anamnesisText());
        Assert::true($stored->isPaused(), 'the draft write keeps paused_at');
        Assert::same('Resumo aceito', $stored->aiSummaryText(), 'the draft write keeps the summary');
    }

    /** T-28 (e): pause() saved from a read older than the draft write keeps the new draft. */
    public function testLifecycleSaveWithAStaleReadKeepsTheDraft(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $stale = clone $repository->findById($id);

        Assert::true($service->autosave($id, ['diagnosis_text' => 'Diagnostico novo', 'weight_kg' => '8,4'], $stale->draftVersion())->saved);

        $stale->pause(new DateTimeImmutable('2026-10-15 10:00:00'));
        $repository->save($stale);

        $stored = $repository->findById($id);
        Assert::same('Diagnostico novo', $stored->diagnosisText(), 'pause must not write the draft it read before');
        Assert::same('8.40', $stored->weightKg());
        Assert::true($stored->isPaused());
    }

    /** T-24, reprodução 4: cliente sem versão é conflito. */
    public function testAutosaveWithoutVersionIsConflictAndWritesNothing(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $before = $repository->findById($id);

        $result = $service->autosave($id, ['anamnesis_text' => 'Aba antiga', 'diagnosis_text' => 'Sem versao'], null);

        Assert::false($result->saved);
        Assert::same($before->draftVersion(), $result->version);
        Assert::same(0, $repository->draftUpdates);
        Assert::null($repository->findById($id)->anamnesisText());
        Assert::null($repository->findById($id)->diagnosisText());
    }

    public function testAutosaveWithoutChangeAnswersSavedWithoutWriting(): void
    {
        [$service, $id, $repository] = $this->startedEncounterWithRepository();
        $base = $repository->findById($id)->draftVersion();

        $result = $service->autosave($id, ['anamnesis_text' => "  \r\n", 'weight_kg' => null], $base);

        Assert::true($result->saved);
        Assert::same($base, $result->version);
        Assert::same(0, $repository->draftUpdates);
    }

    /**
     * The version normalizes text (CRLF/CR -> LF, trim, empty -> null) and
     * numbers (as stored by the column scale), so the version the server
     * answers after a save matches the one it computes from the reloaded row.
     */
    public function testDraftVersionNormalizesTextAndNumbers(): void
    {
        $a = Encounter::start(1, 1, 1, null, 10, new DateTimeImmutable('2026-10-15 09:00:00'));
        $b = Encounter::start(1, 1, 1, null, 10, new DateTimeImmutable('2026-10-15 09:00:00'));

        $a->applyDraft(['anamnesis_text' => "Linha 1\r\nLinha 2  ", 'weight_kg' => 12.5, 'temperature_c' => '38.5', 'heart_rate_bpm' => '80', 'diagnosis_text' => '']);
        $b->applyDraft(['anamnesis_text' => "Linha 1\nLinha 2", 'weight_kg' => '12.50', 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'diagnosis_text' => null]);

        Assert::same($a->draftVersion(), $b->draftVersion());
        Assert::same(64, strlen($a->draftVersion()));

        $b->applyDraft(['diagnosis_text' => 'Otite']);
        Assert::true($a->draftVersion() !== $b->draftVersion(), 'a changed field changes the version');
    }

    public function testPauseMarksEncounterAsPaused(): void
    {
        [$service, $id] = $this->startedEncounter();
        $now = new DateTimeImmutable('2026-09-30 10:00:00');

        $paused = $service->pause($id, self::ACTION, $now);

        Assert::true($paused->isPaused());
        Assert::same($now->format('Y-m-d H:i:s'), $paused->pausedAt()?->format('Y-m-d H:i:s'));
        Assert::same(Encounter::STATUS_IN_PROGRESS, $paused->status());
        Assert::same(0, $paused->pausedSeconds());
    }

    public function testResumeAddsPausedIntervalInSecondsAndClearsPause(): void
    {
        [$service, $id] = $this->startedEncounter();
        $pausedAt = new DateTimeImmutable('2026-09-30 10:00:00');

        $service->pause($id, self::ACTION, $pausedAt);
        $resumed = $service->resume($id, self::ACTION, $pausedAt->modify('+90 seconds'));

        Assert::same(90, $resumed->pausedSeconds());
        Assert::false($resumed->isPaused());
        Assert::null($resumed->pausedAt());
    }

    /**
     * Rodada 2, T-37: clamp documented — a $now earlier than pausedAt (clock
     * skew, a stale request) adds 0 seconds instead of a negative stretch,
     * and still clears the pause.
     */
    public function testResumeWithNowBeforePausedAtAddsZeroSeconds(): void
    {
        [$service, $id] = $this->startedEncounter();
        $pausedAt = new DateTimeImmutable('2026-09-30 10:00:00');

        $service->pause($id, self::ACTION, $pausedAt);
        $resumed = $service->resume($id, self::ACTION, $pausedAt->modify('-5 minutes'));

        Assert::same(0, $resumed->pausedSeconds(), 'A negative interval is clamped to 0');
        Assert::false($resumed->isPaused());
        Assert::null($resumed->pausedAt());
    }

    public function testPauseTwiceThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();
        $now = new DateTimeImmutable('2026-09-30 10:00:00');

        $service->pause($id, self::ACTION, $now);

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->pause($id, self::ACTION, $now->modify('+1 minute')),
        );
    }

    public function testResumeWithoutPauseThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->resume($id, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testFinishPausedEncounterAccumulatesPauseAndClearsPausedAt(): void
    {
        [$service, $id] = $this->startedEncounter();

        $service->pause($id, self::ACTION, new DateTimeImmutable('-5 minutes'));
        $finished = $service->finish($id, self::ACTION);

        Assert::same(Encounter::STATUS_FINISHED, $finished->status());
        Assert::notNull($finished->finishedAt());
        Assert::null($finished->pausedAt());
        Assert::false($finished->isPaused());
        Assert::true($finished->pausedSeconds() > 0, 'pausedSeconds must be > 0 after finishing a paused encounter');
    }

    public function testPauseFinishedEncounterThrowsInvalidStatusTransition(): void
    {
        [$service, $id] = $this->startedEncounter();

        $service->finish($id, self::ACTION);

        Assert::throws(
            InvalidStatusTransitionException::class,
            static fn () => $service->pause($id, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testPauseUsesEncounterRealUnitAndDeniedPausePersistsNothing(): void
    {
        $repository = new FakeEncounterRepository(1);
        $encounter = Encounter::start(
            tenantId: 1,
            systemUnitId: 5,
            patientId: 1,
            appointmentId: null,
            professionalSystemUserId: 10,
            now: new DateTimeImmutable('-10 minutes'),
        );
        $repository->save($encounter);
        $policy = new FakeAuthorizationPolicy(allowed: false);
        $service = $this->makeService($repository, $policy, TenantContext::authenticated(1, 1, 1));

        Assert::throws(
            AuthorizationDenied::class,
            static fn () => $service->pause($encounter->id(), self::ACTION, new DateTimeImmutable()),
        );

        Assert::count(1, $policy->requests);
        Assert::same(5, $policy->requests[0]->resourceUnitId());
        Assert::false($repository->findById($encounter->id())->isPaused());
    }

    public function testPauseUnknownEncounterThrowsSameExceptionAsFinish(): void
    {
        $service = $this->makeService(new FakeEncounterRepository(1), new FakeAuthorizationPolicy(allowed: true));

        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->pause(999, self::ACTION, new DateTimeImmutable()),
        );
        Assert::throws(
            \InvalidArgumentException::class,
            static fn () => $service->resume(999, self::ACTION, new DateTimeImmutable()),
        );
    }

    public function testReconstituteReadsPauseColumnsWithDefaults(): void
    {
        $row = [
            'id' => 7, 'tenant_id' => 1, 'system_unit_id' => 1, 'patient_id' => 1,
            'appointment_id' => null, 'professional_system_user_id' => 10,
            'status' => Encounter::STATUS_IN_PROGRESS, 'started_at' => '2026-09-30 09:00:00',
            'finished_at' => null, 'anamnesis_text' => null, 'temperature_c' => null,
            'heart_rate_bpm' => null, 'respiratory_rate_mpm' => null, 'weight_kg' => null,
            'mucous_membranes' => null, 'capillary_refill_seconds' => null,
            'physical_exam_text' => null, 'diagnosis_text' => null, 'clinical_plan_text' => null,
            'ai_summary_text' => null, 'ai_summary_accepted_at' => null,
        ];

        $legacy = Encounter::reconstitute($row);
        Assert::false($legacy->isPaused());
        Assert::same(0, $legacy->pausedSeconds());

        $paused = Encounter::reconstitute([
            ...$row,
            'paused_at' => '2026-09-30 09:30:00.000000',
            'paused_seconds' => '45',
        ]);
        Assert::true($paused->isPaused());
        Assert::same('2026-09-30 09:30:00', $paused->pausedAt()?->format('Y-m-d H:i:s'));
        Assert::same(45, $paused->pausedSeconds());
    }

    /** @return array{0: EncounterService, 1: int} */
    private function startedEncounter(): array
    {
        $repository = new FakeEncounterRepository(1);
        $service = $this->makeService($repository, new FakeAuthorizationPolicy(allowed: true));

        $started = $service->start([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        return [$service, (int) $started->id()];
    }

    /** @return array{0: EncounterService, 1: int, 2: FakeEncounterRepository} */
    private function startedEncounterWithRepository(): array
    {
        $repository = new FakeEncounterRepository(1);
        $service = $this->makeService($repository, new FakeAuthorizationPolicy(allowed: true));

        $started = $service->start([
            'patient_id' => 1,
            'professional_system_user_id' => 10,
            'system_unit_id' => 1,
        ], self::ACTION);

        return [$service, (int) $started->id(), $repository];
    }

    private function makeService(
        FakeEncounterRepository $repository,
        FakeAuthorizationPolicy $policy,
        ?TenantContext $context = null,
    ): EncounterService {
        return new EncounterService(
            $repository,
            $policy,
            $context ?? TenantContext::authenticated(1, 1, 1),
            new NullPdo(),
        );
    }
}

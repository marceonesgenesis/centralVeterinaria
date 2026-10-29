<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiClinicalContextQueryInterface;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiClinicalContextQuery;
use CentralVet\Tests\Support\FakeAiGateway;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use CentralVet\Tests\Support\FakeAiTenantSettingRepository;
use CentralVet\Tests\Support\FakeFeatureFlagRepository;
use DateTimeImmutable;

/**
 * AI test doubles (T-05): the semantics the AI services rely on (recorded
 * gateway calls and injected failures, tenant isolation, conditional
 * decision, token usage, flags and clinical context).
 */
final class AiFakesTest
{
    private const TENANT_ID = 1;
    private const OTHER_TENANT_ID = 2;
    private const UNIT_ID = 5;
    private const USER_ID = 3;

    private static function interaction(int $tenantId = self::TENANT_ID, int $subjectId = 10, string $purpose = AiPurpose::PATIENT_SUMMARY): AiInteraction
    {
        return AiInteraction::start($tenantId, self::UNIT_ID, self::USER_ID, $purpose, $subjectId, 'v1', hash('sha256', 'entrada'), 'corr-1');
    }

    private static function suggested(int $tenantId = self::TENANT_ID, int $subjectId = 10): AiInteraction
    {
        $interaction = self::interaction($tenantId, $subjectId);
        $interaction->recordSuggestion((new FakeAiGateway())->complete(self::request()), 120);

        return $interaction;
    }

    private static function request(): AiCompletionRequest
    {
        return new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, 'v1', 'system', 'conteúdo');
    }

    public function testEveryFakeImplementsItsContract(): void
    {
        Assert::instanceOf(AiGatewayInterface::class, new FakeAiGateway());
        Assert::instanceOf(AiInteractionRepositoryInterface::class, new FakeAiInteractionRepository(self::TENANT_ID));
        Assert::instanceOf(AiTenantSettingRepositoryInterface::class, new FakeAiTenantSettingRepository());
        Assert::instanceOf(FeatureFlagRepositoryInterface::class, new FakeFeatureFlagRepository());
        Assert::instanceOf(AiClinicalContextQueryInterface::class, new FakeAiClinicalContextQuery());
    }

    public function testGatewayReturnsTheDefaultResultAndRecordsTheCall(): void
    {
        $gateway = new FakeAiGateway();
        $request = self::request();

        $result = $gateway->complete($request);

        Assert::same('Resumo de teste', $result->text);
        Assert::same('fake', $result->provider);
        Assert::same('claude-sonnet-5-5', $result->model);
        Assert::same(100, $result->usage->inputTokens);
        Assert::same(50, $result->usage->outputTokens);
        Assert::same(700, $result->costMicroUsd);
        Assert::same('end_turn', $result->stopReason);
        Assert::same([$request], $gateway->calls());
        Assert::true($gateway->isConfigured());
        Assert::same('fake', $gateway->providerName());
    }

    public function testGatewayRecordsTheCallAndThrowsTheConfiguredFailure(): void
    {
        $failure = new AiProviderException('ai_timeout');
        $gateway = new FakeAiGateway(null, $failure, false);
        $request = self::request();

        Assert::throws(AiProviderException::class, static fn () => $gateway->complete($request));
        Assert::same([$request], $gateway->calls());
        Assert::false($gateway->isConfigured());
    }

    public function testGatewayReturnsTheGivenResult(): void
    {
        $result = new AiCompletionResult('Outro', 'fake', 'claude-opus-5-5', new AiUsage(1, 2), 3, 'end_turn', 'req_1');

        Assert::same($result, (new FakeAiGateway($result))->complete(self::request()));
    }

    public function testInteractionRepositoryAssignsSequentialIds(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);

        Assert::same(1, $repo->insert(self::suggested())->id());
        Assert::same(2, $repo->insert(self::suggested())->id());
        Assert::count(2, $repo->all());
    }

    public function testFindReturnsNullForAnInteractionSeededFromAnotherTenant(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $foreign = self::suggested(self::OTHER_TENANT_ID);
        $repo->seed($foreign);

        Assert::notNull($foreign->id());
        Assert::null($repo->find((int) $foreign->id()));
        Assert::same(9, $repo->find($repo->insert(self::suggested(self::TENANT_ID, 9))->id() ?? 0)?->subjectId());
    }

    public function testSecondSaveDecisionReturnsFalse(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $id = (int) $repo->insert(self::suggested())->id();

        $first = $repo->find($id);
        $second = $repo->find($id);
        $first?->decide(AiInteraction::STATUS_ACCEPTED, null, self::USER_ID, new DateTimeImmutable());
        $second?->decide(AiInteraction::STATUS_DISCARDED, null, self::USER_ID, new DateTimeImmutable());

        Assert::true($repo->saveDecision($first));
        Assert::false($repo->saveDecision($second));
        Assert::same(AiInteraction::STATUS_ACCEPTED, $repo->find($id)?->status());
    }

    public function testLatestDecidedForIgnoresOtherTenantsAndPendingRows(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $foreign = self::suggested(self::OTHER_TENANT_ID);
        $foreign->decide(AiInteraction::STATUS_ACCEPTED, null, self::USER_ID, new DateTimeImmutable());
        $repo->seed($foreign);
        $repo->insert(self::suggested());

        Assert::null($repo->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, 10));

        $decided = self::suggested();
        $decided->decide(AiInteraction::STATUS_EDITED, 'Editado', self::USER_ID, new DateTimeImmutable());
        $repo->insert($decided);

        Assert::same('Editado', $repo->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, 10)?->finalText());
    }

    public function testMonthlyTokenUsageSumsInsertedRowsAndTheConfiguredUsage(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $repo->insert(self::suggested());
        $repo->seed(self::suggested(self::OTHER_TENANT_ID));
        $repo->setMonthlyTokenUsage(1000);

        Assert::same(1150, $repo->monthlyTokenUsage(new DateTimeImmutable('first day of this month 00:00')));
        Assert::same([['purpose' => AiPurpose::PATIENT_SUMMARY, 'requests' => 1, 'tokens' => 150, 'cost_micro_usd' => 700]], $repo->monthlySummary(new DateTimeImmutable('first day of this month 00:00')));
    }

    public function testTenantSettingRepositoryReturnsAndRecordsTheSetting(): void
    {
        $empty = new FakeAiTenantSettingRepository();
        Assert::null($empty->find());
        Assert::null($empty->saved());

        $setting = AiTenantSetting::defaults(self::TENANT_ID);
        $repo = new FakeAiTenantSettingRepository($setting);
        Assert::same($setting, $repo->find());

        $changed = AiTenantSetting::defaults(self::TENANT_ID);
        $changed->changeQuota(5000);
        $repo->save($changed, self::USER_ID);

        Assert::same($changed, $repo->saved());
        Assert::same(5000, $repo->find()?->monthlyTokenQuota());
    }

    public function testMissingFlagIsDisabledAndSetChangesIt(): void
    {
        $flags = new FakeFeatureFlagRepository();
        Assert::false($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));

        $flags->set(FeatureFlagRepositoryInterface::AI_ASSISTANT, true, self::USER_ID);
        Assert::true($flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));

        Assert::true((new FakeFeatureFlagRepository([FeatureFlagRepositoryInterface::AI_ASSISTANT]))->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT));
    }

    public function testClinicalContextQueryReturnsTheSeededData(): void
    {
        $encounter = ['encounter_id' => 7, 'system_unit_id' => self::UNIT_ID, 'patient_id' => 4, 'status' => 'open', 'anamnesis_text' => null, 'patient_name' => 'F8A teste Rex', 'tutor_name' => null, 'species' => 'canine', 'breed' => null, 'sex' => null, 'birth_date' => null, 'weight_kg' => null];
        $history = ['encounters' => [], 'prescriptions' => [], 'exam_results' => []];
        $query = new FakeAiClinicalContextQuery([7 => $encounter], [4 => $history], []);

        Assert::same($encounter, $query->encounterContext(7));
        Assert::null($query->encounterContext(8));
        Assert::same($history, $query->patientHistory(4, 7));
        Assert::same($history, $query->patientHistory(99, null));
        Assert::null($query->examResultContext(1));
    }
}

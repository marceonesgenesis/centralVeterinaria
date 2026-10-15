<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiModelPricing;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Exception\AiInteractionNotFoundException;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

final class AiDomainTest
{
    public function testPurposesMapSubjectHostAndDecideActions(): void
    {
        Assert::count(3, AiPurpose::ALL);

        Assert::same('encounter', AiPurpose::subjectType(AiPurpose::PATIENT_SUMMARY));
        Assert::same('encounter', AiPurpose::subjectType(AiPurpose::ANAMNESIS_ORGANIZE));
        Assert::same('exam_result', AiPurpose::subjectType(AiPurpose::EXAM_SUMMARY));

        Assert::same('EncounterView::onAiPatientSummary', AiPurpose::hostAction('patient_summary'));
        Assert::same('EncounterView::onAiOrganizeAnamnesis', AiPurpose::hostAction('anamnesis_organize'));
        Assert::same('ExamResultForm::onAiExamSummary', AiPurpose::hostAction('exam_summary'));

        Assert::same('EncounterView::onAiDecide', AiPurpose::decideAction('patient_summary'));
        Assert::same('EncounterView::onAiDecide', AiPurpose::decideAction('anamnesis_organize'));
        Assert::same('ExamResultForm::onAiDecide', AiPurpose::decideAction('exam_summary'));
    }

    public function testUnknownPurposeIsRejected(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => AiPurpose::subjectType('next_steps'));
        Assert::throws(InvalidArgumentException::class, fn () => AiPurpose::hostAction('next_steps'));
        Assert::throws(InvalidArgumentException::class, fn () => AiPurpose::decideAction('next_steps'));
    }

    public function testStartAssignsSubjectTypeAndPendingState(): void
    {
        $interaction = self::start(AiPurpose::EXAM_SUMMARY);

        Assert::null($interaction->id());
        Assert::same('exam_result', $interaction->subjectType());
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $interaction->status());
        Assert::null($interaction->suggestionText());
        Assert::same(0, $interaction->usage()->totalTokens());
    }

    public function testAcceptedDecisionCopiesSuggestionToFinalText(): void
    {
        $interaction = self::withSuggestion();
        $now = new DateTimeImmutable('2026-10-07 12:00:00');

        $interaction->decide('accepted', null, 9, $now);

        Assert::same(AiInteraction::STATUS_ACCEPTED, $interaction->status());
        Assert::same('Resumo sugerido', $interaction->finalText());
        Assert::same(9, $interaction->decidedBySystemUserId());
        Assert::same($now, $interaction->decidedAt());
    }

    public function testEditedDecisionStoresEditedText(): void
    {
        $interaction = self::withSuggestion();

        $interaction->decide('edited', '  Resumo revisado  ', 9, new DateTimeImmutable());

        Assert::same(AiInteraction::STATUS_EDITED, $interaction->status());
        Assert::same('Resumo revisado', $interaction->finalText());
    }

    public function testEditedDecisionRequiresNonBlankText(): void
    {
        $interaction = self::withSuggestion();

        Assert::throws(InvalidArgumentException::class, fn () => $interaction->decide('edited', '  ', 9, new DateTimeImmutable()));
        Assert::throws(InvalidArgumentException::class, fn () => $interaction->decide('edited', null, 9, new DateTimeImmutable()));
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $interaction->status());
    }

    public function testDiscardedDecisionLeavesFinalTextNull(): void
    {
        $interaction = self::withSuggestion();

        $interaction->decide('discarded', null, 9, new DateTimeImmutable());

        Assert::same(AiInteraction::STATUS_DISCARDED, $interaction->status());
        Assert::null($interaction->finalText());
        Assert::same('Resumo sugerido', $interaction->suggestionText());
    }

    public function testUnknownDecisionIsRejected(): void
    {
        $interaction = self::withSuggestion();

        Assert::throws(InvalidArgumentException::class, fn () => $interaction->decide('maybe', null, 9, new DateTimeImmutable()));
    }

    public function testSecondDecisionThrowsNotFound(): void
    {
        $interaction = self::withSuggestion();
        $interaction->decide('accepted', null, 9, new DateTimeImmutable());

        Assert::throws(AiInteractionNotFoundException::class, fn () => $interaction->decide('discarded', null, 9, new DateTimeImmutable()));
    }

    public function testFailedInteractionCannotBeDecided(): void
    {
        $interaction = self::start(AiPurpose::PATIENT_SUMMARY);
        $interaction->recordFailure('ai_timeout', 30000, null, null, 'anthropic');

        Assert::throws(AiInteractionNotFoundException::class, fn () => $interaction->decide('accepted', null, 9, new DateTimeImmutable()));
    }

    public function testRecordFailureWithPartialUsage(): void
    {
        $interaction = self::start(AiPurpose::ANAMNESIS_ORGANIZE);

        $interaction->recordFailure('ai_truncated', 1200, new AiUsage(800, 4096), 'claude-sonnet-5-5', 'anthropic');

        Assert::same(AiInteraction::STATUS_FAILED, $interaction->status());
        Assert::same('ai_truncated', $interaction->errorCode());
        Assert::same(4896, $interaction->usage()->totalTokens());
        Assert::same('claude-sonnet-5-5', $interaction->model());
        Assert::same('anthropic', $interaction->provider());
        Assert::same(1200, $interaction->latencyMs());
        Assert::same(AiModelPricing::costMicroUsd('claude-sonnet-5-5', new AiUsage(800, 4096)), $interaction->costMicroUsd());
        Assert::null($interaction->suggestionText());
    }

    public function testRecordSuggestionRequiresText(): void
    {
        $interaction = self::start(AiPurpose::PATIENT_SUMMARY);

        Assert::throws(InvalidArgumentException::class, fn () => $interaction->recordSuggestion(self::result('   '), 10));
    }

    public function testRowRoundTrip(): void
    {
        $interaction = self::withSuggestion();
        $interaction->assignId(42);

        $row = $interaction->toRow();
        Assert::same(42, $row['id']);
        Assert::same('patient_summary', $row['purpose']);
        Assert::same(1000, $row['input_tokens']);
        Assert::same(7000, $row['cost_micro_usd']);
        Assert::same('end_turn', $row['stop_reason']);
        Assert::same('req_test', $row['provider_request_id']);

        $copy = AiInteraction::reconstitute($row + ['created_at' => '2026-10-07 10:00:00']);
        Assert::same(42, $copy->id());
        Assert::same('Resumo sugerido', $copy->suggestionText());
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $copy->status());
        Assert::notNull($copy->createdAt());
    }

    public function testPricing(): void
    {
        Assert::same(14000, AiModelPricing::costMicroUsd('claude-opus-5-5', new AiUsage(1000, 500)));
        Assert::same(7000, AiModelPricing::costMicroUsd('claude-sonnet-5-5', new AiUsage(1000, 500)));
        Assert::same(7000, AiModelPricing::costMicroUsd('claude-sonnet-5', new AiUsage(1000, 500)));
        Assert::same(3500, AiModelPricing::costMicroUsd('claude-haiku-4-5', new AiUsage(1000, 500)));
        Assert::same(17500, AiModelPricing::costMicroUsd('claude-opus-4-8', new AiUsage(1000, 500)));
        Assert::same(0, AiModelPricing::costMicroUsd('stub', new AiUsage(1000, 500)));
        Assert::same(7, AiModelPricing::costMicroUsd('claude-sonnet-5-5', new AiUsage(0, 0, 10, 2)));
        Assert::null(AiModelPricing::costMicroUsd('gpt-unknown', new AiUsage(1000, 500)));
        Assert::true(AiModelPricing::isKnown('claude-opus-5'));
        Assert::false(AiModelPricing::isKnown('gpt-unknown'));
    }

    public function testTenantSettingDefaultsAndTerms(): void
    {
        $setting = AiTenantSetting::defaults(1);

        Assert::same(300000, $setting->monthlyTokenQuota());
        Assert::same(AiTenantSetting::DEFAULT_MONTHLY_TOKEN_QUOTA, $setting->monthlyTokenQuota());
        Assert::null($setting->termsVersion());
        Assert::null($setting->termsAcceptedAt());
        Assert::false($setting->hasAcceptedTerms('2026-10-v1'));

        $setting->acceptTerms(AiTerms::VERSION, 5, new DateTimeImmutable('2026-10-07 09:00:00'));

        Assert::true($setting->hasAcceptedTerms('2026-10-v1'));
        Assert::false($setting->hasAcceptedTerms('2027-01-v2'));
        Assert::same('2026-10-v1', $setting->termsVersion());

        $setting->changeQuota(50000);
        Assert::same(50000, $setting->monthlyTokenQuota());
        Assert::throws(InvalidArgumentException::class, fn () => $setting->changeQuota(-1));
    }

    public function testTermsTextPerLocale(): void
    {
        $pt = AiTerms::text('pt');
        $en = AiTerms::text('en');

        Assert::true($pt !== '' && $en !== '');
        Assert::true($pt !== $en);
        Assert::same($en, AiTerms::text('es'));
    }

    public function testExceptionsCarryOnlyReasonCodes(): void
    {
        $unavailable = new AiUnavailableException('ai_quota_exceeded');
        Assert::same('ai_quota_exceeded', $unavailable->reason());
        Assert::same('AI assistant is unavailable: ai_quota_exceeded', $unavailable->getMessage());
        Assert::count(13, AiUnavailableException::REASONS);
        Assert::throws(InvalidArgumentException::class, fn () => new AiUnavailableException('whatever'));

        $provider = new AiProviderException('ai_rate_limited', 429, new AiUsage(1, 2), 'claude-sonnet-5-5');
        Assert::same('ai_rate_limited', $provider->reason());
        Assert::same(3, $provider->usage()?->totalTokens());
        Assert::same('claude-sonnet-5-5', $provider->model());

        Assert::same('AI suggestion not found', (new AiInteractionNotFoundException())->getMessage());
    }

    private static function start(string $purpose): AiInteraction
    {
        return AiInteraction::start(1, 2, 3, $purpose, 77, 'v1', str_repeat('a', 64), 'corr-1');
    }

    private static function withSuggestion(): AiInteraction
    {
        $interaction = self::start(AiPurpose::PATIENT_SUMMARY);
        $interaction->recordSuggestion(self::result('Resumo sugerido'), 850);

        return $interaction;
    }

    private static function result(string $text): AiCompletionResult
    {
        return new AiCompletionResult($text, 'anthropic', 'claude-sonnet-5-5', new AiUsage(1000, 500), 7000, 'end_turn', 'req_test');
    }
}

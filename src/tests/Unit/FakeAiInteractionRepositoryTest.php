<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiGateway;
use CentralVet\Tests\Support\FakeAiInteractionRepository;
use DateTimeImmutable;

/**
 * Parity of FakeAiInteractionRepository with the PDO repository (T-11):
 * latestDecidedFor() orders by `created_at DESC, id DESC` and the monthly
 * reads keep only rows created in [month start, next month start).
 */
final class FakeAiInteractionRepositoryTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 3;
    private const SUBJECT_ID = 10;

    /** Suggested interaction (150 tokens) created at $createdAt, optionally decided at $decidedAt. */
    private static function stored(int $id, string $createdAt, ?string $decidedAt = null, string $finalText = 'Final'): AiInteraction
    {
        $interaction = AiInteraction::start(self::TENANT_ID, self::UNIT_ID, self::USER_ID, AiPurpose::PATIENT_SUMMARY, self::SUBJECT_ID, 'v1', hash('sha256', 'entrada'), 'corr-' . $id);
        $interaction->recordSuggestion(
            (new FakeAiGateway())->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, 'v1', 'system', 'conteúdo')),
            120,
        );

        if ($decidedAt !== null) {
            $interaction->decide(AiInteraction::STATUS_EDITED, $finalText, self::USER_ID, new DateTimeImmutable($decidedAt));
        }

        return AiInteraction::reconstitute(['id' => $id, 'created_at' => $createdAt] + $interaction->toRow());
    }

    public function testLatestDecidedForReturnsTheLastCreatedNotTheLastDecided(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $repo->seed(self::stored(1, '2026-10-01 10:00:00', '2026-10-05 10:00:00', 'Criada antes'));
        $repo->seed(self::stored(2, '2026-10-02 10:00:00', '2026-10-03 10:00:00', 'Criada depois'));

        Assert::same('Criada depois', $repo->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, self::SUBJECT_ID)?->finalText());
    }

    public function testLatestDecidedForBreaksCreatedAtTiesByHighestId(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $repo->seed(self::stored(4, '2026-10-02 10:00:00', '2026-10-03 10:00:00', 'Id maior'));
        $repo->seed(self::stored(3, '2026-10-02 10:00:00', '2026-10-09 10:00:00', 'Id menor'));

        Assert::same('Id maior', $repo->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, self::SUBJECT_ID)?->finalText());
    }

    public function testMonthlyReadsExcludeRowsCreatedInTheNextMonth(): void
    {
        $repo = new FakeAiInteractionRepository(self::TENANT_ID);
        $repo->seed(self::stored(1, '2026-09-30 23:59:59'));
        $repo->seed(self::stored(2, '2026-10-01 00:00:00'));
        $repo->seed(self::stored(3, '2026-10-31 23:59:59'));
        $repo->seed(self::stored(4, '2026-11-01 00:00:00'));
        $repo->seed(self::stored(5, '2026-11-15 12:00:00'));

        Assert::same(300, $repo->monthlyTokenUsage(new DateTimeImmutable('2026-10-01 00:00:00')));
        Assert::same(300, $repo->monthlyTokenUsage(new DateTimeImmutable('2026-10-15 13:45:00')));
        Assert::same(
            [['purpose' => AiPurpose::PATIENT_SUMMARY, 'requests' => 2, 'tokens' => 300, 'cost_micro_usd' => 1400]],
            $repo->monthlySummary(new DateTimeImmutable('2026-10-01 00:00:00')),
        );
    }
}

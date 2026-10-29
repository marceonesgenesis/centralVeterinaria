<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * The three AI repositories (migration 0014) against the real MySQL schema:
 * insert, tenant isolation, conditional decision, monthly usage and summary,
 * latest decided suggestion, and the upserts of the tenant setting and the
 * feature flag. Every row lives only inside the test transaction, rolled
 * back in tearDown() — nothing is committed.
 */
final class AiRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $unitId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'ai_interaction'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0014 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $this->tenantA = $this->createTenant('f8a-ai-a');
        $this->tenantB = $this->createTenant('f8a-ai-b');
    }

    public function testInsertReturnsIdAndFindIsolatesTenant(): void
    {
        $a = $this->interactions($this->tenantA);
        $b = $this->interactions($this->tenantB);

        $first = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 11, 100, 50));
        $second = $a->insert($this->failed($this->tenantA, AiPurpose::EXAM_SUMMARY, 12));
        $other = $b->insert($this->suggested($this->tenantB, AiPurpose::PATIENT_SUMMARY, 11, 7, 3));

        Assert::true((int) $first->id() > 0);
        Assert::true((int) $second->id() > (int) $first->id());
        Assert::true((int) $other->id() > 0);

        Assert::null($a->find((int) $other->id()), 'tenant A must not see tenant B rows');
        Assert::null($b->find((int) $first->id()), 'tenant B must not see tenant A rows');

        $stored = $a->find((int) $first->id());
        Assert::notNull($stored);
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $stored->status());
        Assert::same(AiPurpose::SUBJECT_ENCOUNTER, $stored->subjectType());
        Assert::same(11, $stored->subjectId());
        Assert::same('F8A teste sugestão', $stored->suggestionText());
        Assert::same('claude-sonnet-5-5', $stored->model());
        Assert::same(100, $stored->usage()->inputTokens);
        Assert::same(50, $stored->usage()->outputTokens);
        Assert::same(str_repeat('a', 64), $stored->inputSha256());
        Assert::notNull($stored->createdAt());

        $failure = $a->find((int) $second->id());
        Assert::same(AiInteraction::STATUS_FAILED, $failure?->status());
        Assert::same('ai_timeout', $failure?->errorCode());
        Assert::same(AiPurpose::SUBJECT_EXAM_RESULT, $failure?->subjectType());
    }

    public function testInsertOfAnotherTenantIsRejected(): void
    {
        $a = $this->interactions($this->tenantA);

        Assert::throws(
            TenantBoundaryViolation::class,
            fn () => $a->insert($this->suggested($this->tenantB, AiPurpose::PATIENT_SUMMARY, 11, 1, 1)),
        );
        Assert::same('0', $this->countInteractions($this->tenantB));
    }

    public function testSaveDecisionIsConditionalOnPendingReview(): void
    {
        $a = $this->interactions($this->tenantA);
        $interaction = $a->insert($this->suggested($this->tenantA, AiPurpose::ANAMNESIS_ORGANIZE, 21, 10, 5));
        $id = (int) $interaction->id();

        $first = $a->find($id);
        $second = $a->find($id);
        Assert::notNull($first);
        Assert::notNull($second);

        $first->decide(AiInteraction::STATUS_EDITED, 'F8A teste texto editado', $this->userId, new DateTimeImmutable('2031-10-05 10:00:00.123456'));
        $second->decide(AiInteraction::STATUS_DISCARDED, null, $this->userId, new DateTimeImmutable('2031-10-05 10:00:01'));

        Assert::true($a->saveDecision($first));
        Assert::false($a->saveDecision($second), 'second decision must lose');

        $stored = $a->find($id);
        Assert::same(AiInteraction::STATUS_EDITED, $stored?->status());
        Assert::same('F8A teste texto editado', $stored?->finalText());
        Assert::same($this->userId, $stored?->decidedBySystemUserId());
        Assert::same('2031-10-05 10:00:00.123456', $stored?->decidedAt()?->format('Y-m-d H:i:s.u'));

        // Another tenant cannot decide the row either.
        $foreign = $this->interactions($this->tenantB);
        $pending = $a->insert($this->suggested($this->tenantA, AiPurpose::ANAMNESIS_ORGANIZE, 22, 1, 1));
        $copy = $a->find((int) $pending->id());
        $copy?->decide(AiInteraction::STATUS_ACCEPTED, null, $this->userId, new DateTimeImmutable('2031-10-05 11:00:00'));
        Assert::false($foreign->saveDecision($copy));
        Assert::same(AiInteraction::STATUS_PENDING_REVIEW, $a->find((int) $pending->id())?->status());
    }

    public function testMonthlyTokenUsageIgnoresPreviousMonthAndOtherTenant(): void
    {
        $a = $this->interactions($this->tenantA);
        $b = $this->interactions($this->tenantB);
        $monthStart = new DateTimeImmutable('2031-10-01 00:00:00');

        Assert::same(0, $a->monthlyTokenUsage($monthStart), 'no rows means zero');

        $one = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 31, 100, 50, 7, 3));
        $two = $a->insert($this->suggested($this->tenantA, AiPurpose::EXAM_SUMMARY, 32, 200, 20));
        $previous = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 33, 5000, 5000));
        $next = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 34, 9000, 9000));
        $foreign = $b->insert($this->suggested($this->tenantB, AiPurpose::PATIENT_SUMMARY, 31, 8000, 8000));

        $this->setCreatedAt((int) $one->id(), '2031-10-01 00:00:00.000000');
        $this->setCreatedAt((int) $two->id(), '2031-10-31 23:59:59.999999');
        $this->setCreatedAt((int) $previous->id(), '2031-09-30 23:59:59.999999');
        $this->setCreatedAt((int) $next->id(), '2031-11-01 00:00:00.000000');
        $this->setCreatedAt((int) $foreign->id(), '2031-10-10 10:00:00.000000');

        Assert::same(160 + 220, $a->monthlyTokenUsage($monthStart));
        Assert::same(16000, $b->monthlyTokenUsage($monthStart));
    }

    public function testMonthlySummaryGroupsByPurposeWithCostSummed(): void
    {
        $a = $this->interactions($this->tenantA);
        $b = $this->interactions($this->tenantB);
        $monthStart = new DateTimeImmutable('2031-10-01 00:00:00');

        Assert::same([], $a->monthlySummary($monthStart));

        $rows = [
            $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 41, 1000000, 0)),
            $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 42, 0, 1000000)),
            $a->insert($this->failed($this->tenantA, AiPurpose::EXAM_SUMMARY, 43)),
            $a->insert($this->suggested($this->tenantA, AiPurpose::ANAMNESIS_ORGANIZE, 44, 10, 10)),
        ];
        $old = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 45, 5, 5));
        $foreign = $b->insert($this->suggested($this->tenantB, AiPurpose::PATIENT_SUMMARY, 41, 5, 5));

        foreach ($rows as $row) {
            $this->setCreatedAt((int) $row->id(), '2031-10-15 12:00:00.000000');
        }
        $this->setCreatedAt((int) $old->id(), '2031-09-15 12:00:00.000000');
        $this->setCreatedAt((int) $foreign->id(), '2031-10-15 12:00:00.000000');

        $summary = $a->monthlySummary($monthStart);

        Assert::same([
            ['purpose' => 'anamnesis_organize', 'requests' => 1, 'tokens' => 20, 'cost_micro_usd' => 120],
            ['purpose' => 'exam_summary', 'requests' => 1, 'tokens' => 0, 'cost_micro_usd' => 0],
            ['purpose' => 'patient_summary', 'requests' => 2, 'tokens' => 2000000, 'cost_micro_usd' => 12000000],
        ], $summary);
    }

    public function testLatestDecidedForIgnoresPendingFailedAndDiscarded(): void
    {
        $a = $this->interactions($this->tenantA);
        $subject = 51;

        Assert::null($a->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, $subject));

        $accepted = $this->decided($a, AiPurpose::PATIENT_SUMMARY, $subject, AiInteraction::STATUS_ACCEPTED);
        $this->setCreatedAt((int) $accepted->id(), '2031-10-01 10:00:00.000000');

        $edited = $this->decided($a, AiPurpose::PATIENT_SUMMARY, $subject, AiInteraction::STATUS_EDITED);
        $this->setCreatedAt((int) $edited->id(), '2031-10-02 10:00:00.000000');

        $discarded = $this->decided($a, AiPurpose::PATIENT_SUMMARY, $subject, AiInteraction::STATUS_DISCARDED);
        $this->setCreatedAt((int) $discarded->id(), '2031-10-03 10:00:00.000000');

        $pending = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, $subject, 1, 1));
        $this->setCreatedAt((int) $pending->id(), '2031-10-04 10:00:00.000000');

        $failed = $a->insert($this->failed($this->tenantA, AiPurpose::PATIENT_SUMMARY, $subject));
        $this->setCreatedAt((int) $failed->id(), '2031-10-05 10:00:00.000000');

        // Same subject id, other purpose: never returned for patient_summary.
        $otherPurpose = $this->decided($a, AiPurpose::ANAMNESIS_ORGANIZE, $subject, AiInteraction::STATUS_ACCEPTED);
        $this->setCreatedAt((int) $otherPurpose->id(), '2031-10-06 10:00:00.000000');

        $latest = $a->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, $subject);
        Assert::same((int) $edited->id(), $latest?->id());
        Assert::same('F8A teste editado', $latest?->finalText());

        Assert::null($this->interactions($this->tenantB)->latestDecidedFor(AiPurpose::PATIENT_SUMMARY, $subject));
        Assert::null($a->latestDecidedFor(AiPurpose::EXAM_SUMMARY, $subject));
    }

    public function testListRecentIsNewestFirstAndTenantScoped(): void
    {
        $a = $this->interactions($this->tenantA);
        $b = $this->interactions($this->tenantB);

        $old = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 61, 1, 1));
        $new = $a->insert($this->suggested($this->tenantA, AiPurpose::PATIENT_SUMMARY, 62, 1, 1));
        $b->insert($this->suggested($this->tenantB, AiPurpose::PATIENT_SUMMARY, 63, 1, 1));
        $this->setCreatedAt((int) $old->id(), '2031-10-01 10:00:00.000000');
        $this->setCreatedAt((int) $new->id(), '2031-10-02 10:00:00.000000');

        $recent = $a->listRecent(10);
        Assert::same([(int) $new->id(), (int) $old->id()], array_map(static fn (AiInteraction $i): ?int => $i->id(), $recent));
        Assert::count(1, $a->listRecent(1));
    }

    public function testTenantSettingUpsertKeepsOneRow(): void
    {
        $settings = new AiTenantSettingRepository($this->contextFor($this->tenantA), $this->pdo);
        $other = new AiTenantSettingRepository($this->contextFor($this->tenantB), $this->pdo);

        Assert::null($settings->find());

        $setting = AiTenantSetting::defaults($this->tenantA);
        $settings->save($setting, $this->userId);

        $stored = $settings->find();
        Assert::notNull($stored);
        Assert::same(AiTenantSetting::DEFAULT_MONTHLY_TOKEN_QUOTA, $stored->monthlyTokenQuota());
        Assert::null($stored->termsVersion());

        $stored->acceptTerms('2026-10-v1', $this->userId, new DateTimeImmutable('2031-10-05 09:00:00.500000'));
        $stored->changeQuota(120000);
        $settings->save($stored, $this->userId);

        $reloaded = $settings->find();
        Assert::same(120000, $reloaded?->monthlyTokenQuota());
        Assert::same('2026-10-v1', $reloaded?->termsVersion());
        Assert::same($this->userId, $reloaded?->termsAcceptedBySystemUserId());
        Assert::same('2031-10-05 09:00:00.500000', $reloaded?->termsAcceptedAt()?->format('Y-m-d H:i:s.u'));
        Assert::same('1', $this->count('ai_tenant_setting', $this->tenantA));
        Assert::same((string) $this->userId, $this->scalar('SELECT updated_by_system_user_id FROM ai_tenant_setting WHERE tenant_id = ?', [$this->tenantA]));

        Assert::null($other->find());
        Assert::throws(TenantBoundaryViolation::class, fn () => $other->save(AiTenantSetting::defaults($this->tenantA), $this->userId));
    }

    public function testFeatureFlagUpsertKeepsOneRowAndIsTenantScoped(): void
    {
        $flags = new FeatureFlagRepository($this->contextFor($this->tenantA), $this->pdo);
        $other = new FeatureFlagRepository($this->contextFor($this->tenantB), $this->pdo);
        $key = FeatureFlagRepositoryInterface::AI_ASSISTANT;

        Assert::false($flags->isEnabled($key), 'missing row means off');

        $flags->set($key, true, $this->userId);
        Assert::true($flags->isEnabled($key));
        Assert::false($other->isEnabled($key));

        $flags->set($key, false, $this->userId);
        Assert::false($flags->isEnabled($key));
        Assert::same('1', $this->count('feature_flag', $this->tenantA));
        Assert::same((string) $this->userId, $this->scalar('SELECT updated_by_system_user_id FROM feature_flag WHERE tenant_id = ?', [$this->tenantA]));

        $flags->set($key, true, $this->userId);
        Assert::true($flags->isEnabled($key));
        Assert::same('1', $this->count('feature_flag', $this->tenantA));
        Assert::same('0', $this->count('feature_flag', $this->tenantB));
    }

    private function interactions(int $tenantId): AiInteractionRepository
    {
        return new AiInteractionRepository($this->contextFor($tenantId), $this->pdo);
    }

    private function started(int $tenantId, string $purpose, int $subjectId): AiInteraction
    {
        return AiInteraction::start(
            $tenantId,
            $this->unitId,
            $this->userId,
            $purpose,
            $subjectId,
            'f8a-test-v1',
            str_repeat('a', 64),
            'f8a-test-correlation',
        );
    }

    private function suggested(
        int $tenantId,
        string $purpose,
        int $subjectId,
        int $input,
        int $output,
        int $cacheRead = 0,
        int $cacheWrite = 0,
    ): AiInteraction {
        $interaction = $this->started($tenantId, $purpose, $subjectId);
        $usage = new AiUsage($input, $output, $cacheRead, $cacheWrite);
        $interaction->recordSuggestion(
            new AiCompletionResult(
                'F8A teste sugestão',
                'anthropic',
                'claude-sonnet-5-5',
                $usage,
                // US$ 2 / 10 per MTok: micro-USD = 2 * input + 10 * output.
                2 * $input + 10 * $output,
                'end_turn',
                'req_f8a_test',
            ),
            120,
        );

        return $interaction;
    }

    private function failed(int $tenantId, string $purpose, int $subjectId): AiInteraction
    {
        $interaction = $this->started($tenantId, $purpose, $subjectId);
        $interaction->recordFailure('ai_timeout', 30000, null, null, 'anthropic');

        return $interaction;
    }

    private function decided(AiInteractionRepository $repository, string $purpose, int $subjectId, string $decision): AiInteraction
    {
        $interaction = $repository->insert($this->suggested($this->tenantA, $purpose, $subjectId, 1, 1));
        $interaction->decide(
            $decision,
            $decision === AiInteraction::STATUS_EDITED ? 'F8A teste editado' : null,
            $this->userId,
            new DateTimeImmutable('2031-10-05 12:00:00'),
        );
        Assert::true($repository->saveDecision($interaction));

        return $interaction;
    }

    private function setCreatedAt(int $id, string $createdAt): void
    {
        $this->pdo->prepare('UPDATE ai_interaction SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);
    }

    private function countInteractions(int $tenantId): string
    {
        return $this->count('ai_interaction', $tenantId);
    }

    private function count(string $table, int $tenantId): string
    {
        return (string) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ?", [$tenantId]);
    }

    /** @param list<int> $parameters */
    private function scalar(string $sql, array $parameters): ?string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function contextFor(int $tenantId): TenantContext
    {
        return TenantContext::authenticated($tenantId, $this->userId, $this->unitId);
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F8A teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}

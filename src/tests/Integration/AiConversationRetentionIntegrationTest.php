<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AiConversationRetention;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 90-day retention of the copilot conversations (Fase 8B, T-12) against the
 * real schema of migration 0015: with a fixed clock, a conversation idle for
 * 91 days is deleted (its messages fall by cascade) and one idle for 89 days
 * stays; the audit (`mcp_tool_call`, `ai_interaction`) is never touched.
 * Injected batch size and cap drive the loop; `updated_at`, not
 * `created_at`, decides; the bin skips when another run holds the lock.
 * Everything lives inside the test transaction, rolled back in tearDown().
 */
final class AiConversationRetentionIntegrationTest extends MysqlIntegrationTestCase
{
    private const NOW = '2031-06-15 12:00:00.000000';

    /**
     * Clock of the batch tests: old enough that no row outside this test is
     * past the cutoff, so the exact `{deleted, batches}` is only ours.
     */
    private const PAST_NOW = '2001-06-15 12:00:00.000000';

    private int $userId;
    private int $unitId;
    private int $tenantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'ai_conversation'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0015 must be applied to the test database');

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->unitId = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitId > 0, 'Fixture requires at least one existing system_unit row');

        $slug = 'f8b-ret-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        )->execute(['slug' => $slug, 'legal_name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);
        $this->tenantId = (int) $this->pdo->lastInsertId();
    }

    public function testDeletesConversationsOlderThanNinetyDaysAndKeepsTheAudit(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $old = $this->conversation($now->modify('-91 days'));
        $recent = $this->conversation($now->modify('-89 days'));

        $this->message($old, 'user');
        $this->message($old, 'assistant');
        $this->message($recent, 'user');
        $this->toolCall($old);
        $this->interaction($old);

        $toolCallsBefore = $this->count('SELECT COUNT(*) FROM mcp_tool_call');
        $interactionsBefore = $this->count('SELECT COUNT(*) FROM ai_interaction');

        $result = AiConversationRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now)->runOnce();

        Assert::true($result['deleted'] >= 1, 'the 91-day conversation must be counted');
        Assert::true($result['batches'] >= 1);
        Assert::same(0, $this->count('SELECT COUNT(*) FROM ai_conversation WHERE id = ' . $old));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM ai_conversation WHERE id = ' . $recent));
        Assert::same(0, $this->count('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ' . $old));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ' . $recent));
        Assert::same($toolCallsBefore, $this->count('SELECT COUNT(*) FROM mcp_tool_call'));
        Assert::same(1, $this->count('SELECT COUNT(*) FROM mcp_tool_call WHERE conversation_id = ' . $old));
        Assert::same($interactionsBefore, $this->count('SELECT COUNT(*) FROM ai_interaction'));
        Assert::same(1, $this->count("SELECT COUNT(*) FROM ai_interaction WHERE subject_type = 'ai_conversation' AND subject_id = " . $old));
    }

    public function testInjectedBatchSizeAndCapStopAtTheCapAndKeepTheAudit(): void
    {
        $now = new DateTimeImmutable(self::PAST_NOW);
        $this->assertNothingElseIsPastTheCutoff($now);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->conversation($now->modify('-120 days'));
        }
        $this->toolCall($ids[0]);
        $this->interaction($ids[0]);
        $toolCallsBefore = $this->count('SELECT COUNT(*) FROM mcp_tool_call');
        $interactionsBefore = $this->count('SELECT COUNT(*) FROM ai_interaction');

        $result = AiConversationRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now, 2, 2)->runOnce();

        Assert::same(['deleted' => 4, 'batches' => 2], $result);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM ai_conversation WHERE id IN (' . implode(',', $ids) . ')'));
        Assert::same($toolCallsBefore, $this->count('SELECT COUNT(*) FROM mcp_tool_call'));
        Assert::same($interactionsBefore, $this->count('SELECT COUNT(*) FROM ai_interaction'));
    }

    public function testShortBatchStopsTheLoop(): void
    {
        $now = new DateTimeImmutable(self::PAST_NOW);
        $this->assertNothingElseIsPastTheCutoff($now);
        for ($i = 0; $i < 3; $i++) {
            $this->conversation($now->modify('-120 days'));
        }

        $result = AiConversationRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now, 2, 10)->runOnce();

        Assert::same(['deleted' => 3, 'batches' => 2], $result);
        Assert::same(0, $this->count('SELECT COUNT(*) FROM ai_conversation WHERE tenant_id = ' . $this->tenantId));
    }

    public function testUpdatedAtDecidesNotCreatedAt(): void
    {
        $now = new DateTimeImmutable(self::PAST_NOW);
        $this->assertNothingElseIsPastTheCutoff($now);
        $active = $this->conversation($now->modify('-10 days'), $now->modify('-120 days'));

        $result = AiConversationRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now, 2, 2)->runOnce();

        Assert::same(['deleted' => 0, 'batches' => 0], $result);
        Assert::same(1, $this->count('SELECT COUNT(*) FROM ai_conversation WHERE id = ' . $active));
    }

    public function testBatchSizeAndCapMustBePositive(): void
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable(self::PAST_NOW);

        Assert::throws(InvalidArgumentException::class, fn () => AiConversationRetention::forConnection($this->pdo, $clock, 0, 1), 'batch size');
        Assert::throws(InvalidArgumentException::class, fn () => AiConversationRetention::forConnection($this->pdo, $clock, 1, 0), 'cap');
    }

    public function testBinSkipsWhenAnotherRunHoldsTheLock(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/ai-conversation-retention.php';
        $lockFile = sys_get_temp_dir() . '/centralvet-ai-conversation-retention-' . md5(dirname($bin)) . '.lock';
        $handle = fopen($lockFile, 'c');
        Assert::true($handle !== false && flock($handle, LOCK_EX | LOCK_NB), 'test takes the lock');

        try {
            // Unreachable database: without the lock the bin fails (exit 1)
            // instead of deleting anything outside the test transaction.
            exec(
                'env DB_HOST=127.0.0.1 DB_PORT=1 DB_DATABASE=f8b_unreachable '
                . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' 2>/dev/null',
                $output,
                $exitCode,
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        Assert::same(0, $exitCode);
        Assert::same(['{"skipped":"locked"}'], $output);
    }

    public function testConstantsMatchTheContract(): void
    {
        Assert::same(90, AiConversationRetention::RETENTION_DAYS);
        Assert::same(500, AiConversationRetention::BATCH_SIZE);
        Assert::same(200, AiConversationRetention::MAX_BATCHES);
    }

    private function conversation(DateTimeImmutable $updatedAt, ?DateTimeImmutable $createdAt = null): int
    {
        $stamp = $updatedAt->format('Y-m-d H:i:s.u');
        $this->pdo->prepare(
            'INSERT INTO ai_conversation (tenant_id, system_unit_id, system_user_id, family, title, created_at, updated_at)'
            . " VALUES (:tenant, :unit, :user, 'management', 'F8B teste conversa', :created, :updated)",
        )->execute([
            'tenant' => $this->tenantId,
            'unit' => $this->unitId,
            'user' => $this->userId,
            'created' => $createdAt?->format('Y-m-d H:i:s.u') ?? $stamp,
            'updated' => $stamp,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function message(int $conversationId, string $role): void
    {
        $this->pdo->prepare(
            'INSERT INTO ai_conversation_message (tenant_id, conversation_id, role, content) VALUES (:tenant, :conversation, :role, :content)',
        )->execute([
            'tenant' => $this->tenantId,
            'conversation' => $conversationId,
            'role' => $role,
            'content' => 'F8B teste mensagem',
        ]);
    }

    private function toolCall(int $conversationId): void
    {
        $this->pdo->prepare(
            'INSERT INTO mcp_tool_call (tenant_id, system_unit_id, system_user_id, conversation_id, correlation_id, tool_name, input_minimized, decision)'
            . " VALUES (:tenant, :unit, :user, :conversation, 'f8b-ret-corr', 'consultar_estoque', '{}', 'allowed')",
        )->execute([
            'tenant' => $this->tenantId,
            'unit' => $this->unitId,
            'user' => $this->userId,
            'conversation' => $conversationId,
        ]);
    }

    private function interaction(int $conversationId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ai_interaction (tenant_id, system_unit_id, system_user_id, purpose, subject_type, subject_id,'
            . ' prompt_version, provider, status, input_sha256, correlation_id)'
            . " VALUES (:tenant, :unit, :user, 'copilot_management', 'ai_conversation', :conversation,"
            . " 'copilot_management.v1', 'stub', 'answered', :sha, 'f8b-ret-corr')",
        )->execute([
            'tenant' => $this->tenantId,
            'unit' => $this->unitId,
            'user' => $this->userId,
            'conversation' => $conversationId,
            'sha' => str_repeat('b', 64),
        ]);
    }

    private function assertNothingElseIsPastTheCutoff(DateTimeImmutable $now): void
    {
        $cutoff = $now->modify('-' . AiConversationRetention::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');
        Assert::same(0, $this->count("SELECT COUNT(*) FROM ai_conversation WHERE updated_at < '{$cutoff}'"), 'no foreign row past the cutoff');
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}

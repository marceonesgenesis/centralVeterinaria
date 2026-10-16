<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AiConversationRetention;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * 90-day retention of the copilot conversations (Fase 8B, T-12) against the
 * real schema of migration 0015: with a fixed clock, a conversation idle for
 * 91 days is deleted (its messages fall by cascade) and one idle for 89 days
 * stays; the audit (`mcp_tool_call`, `ai_interaction`) is never touched.
 * Everything lives inside the test transaction, rolled back in tearDown().
 */
final class AiConversationRetentionIntegrationTest extends MysqlIntegrationTestCase
{
    private const NOW = '2031-06-15 12:00:00.000000';

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

    public function testConstantsMatchTheContract(): void
    {
        Assert::same(90, AiConversationRetention::RETENTION_DAYS);
        Assert::same(500, AiConversationRetention::BATCH_SIZE);
        Assert::same(200, AiConversationRetention::MAX_BATCHES);
    }

    private function conversation(DateTimeImmutable $updatedAt): int
    {
        $stamp = $updatedAt->format('Y-m-d H:i:s.u');
        $this->pdo->prepare(
            'INSERT INTO ai_conversation (tenant_id, system_unit_id, system_user_id, family, title, created_at, updated_at)'
            . " VALUES (:tenant, :unit, :user, 'management', 'F8B teste conversa', :created, :updated)",
        )->execute([
            'tenant' => $this->tenantId,
            'unit' => $this->unitId,
            'user' => $this->userId,
            'created' => $stamp,
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

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\McpToolCall;
use CentralVet\Persistence\AiConversationRepository;
use CentralVet\Persistence\McpToolCallRepository;
use CentralVet\Tenancy\Exception\TenantBoundaryViolation;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Copilot repositories (migration 0015) against the real MySQL schema:
 * ownership of a conversation by tenant, active unit and user; cascade of
 * the messages on delete while the tool call audit stays; the tail of the
 * history in chronological order; and the tenant-scoped audit search.
 * Every row lives only inside the test transaction (rolled back).
 */
final class CopilotRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userU;
    private int $userV;
    private int $unitA;
    private int $unitB;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        foreach (['ai_conversation', 'ai_conversation_message', 'mcp_tool_call'] as $table) {
            $exists = (bool) $this->pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
            Assert::true($exists, "Migration 0015 must be applied to the test database ({$table})");
        }

        $this->tenantA = $this->createTenant('f8b-repo-a');
        $this->tenantB = $this->createTenant('f8b-repo-b');

        $this->userU = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userU > 0, 'Fixture requires at least one existing system_users row');
        $this->userV = (int) $this->pdo->query('SELECT MAX(id) + 1000 FROM system_users')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, :active)')
            ->execute(['id' => $this->userV, 'name' => 'F8B teste V', 'login' => 'f8b-teste-' . bin2hex(random_bytes(4)), 'active' => 'Y']);

        $this->unitA = (int) $this->pdo->query('SELECT MIN(id) FROM system_unit')->fetchColumn();
        Assert::true($this->unitA > 0, 'Fixture requires at least one existing system_unit row');
        $this->unitB = (int) $this->pdo->query('SELECT MAX(id) + 1000 FROM system_unit')->fetchColumn();
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (:id, :tenant_id, :name)')
            ->execute(['id' => $this->unitB, 'tenant_id' => $this->tenantA, 'name' => 'F8B teste unidade B']);
    }

    public function testFindOwnedIsolatesTenantUnitAndUser(): void
    {
        $repository = $this->conversations($this->tenantA, $this->userU, $this->unitA);
        $conversation = $repository->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B teste conversa'));
        $id = (int) $conversation->id();
        Assert::true($id > 0);

        $found = $repository->findOwned($id);
        Assert::notNull($found);
        Assert::same($id, $found->id());
        Assert::same('F8B teste conversa', $found->title());
        Assert::same(AiConversation::FAMILY_CLINICAL, $found->family());
        Assert::same($this->unitA, $found->systemUnitId());
        Assert::same($this->userU, $found->systemUserId());

        Assert::null($this->conversations($this->tenantA, $this->userU, $this->unitB)->findOwned($id), 'other unit');
        Assert::null($this->conversations($this->tenantA, $this->userV, $this->unitA)->findOwned($id), 'other user');
        Assert::null($this->conversations($this->tenantB, $this->userU, $this->unitA)->findOwned($id), 'other tenant');

        Assert::false($this->conversations($this->tenantA, $this->userV, $this->unitA)->deleteOwned($id), 'other user cannot delete');
        Assert::false($this->conversations($this->tenantB, $this->userU, $this->unitA)->deleteOwned($id), 'other tenant cannot delete');
        Assert::notNull($repository->findOwned($id), 'still there after foreign deletes');
    }

    public function testInsertOfAnotherTenantIsRejected(): void
    {
        $repository = $this->conversations($this->tenantA, $this->userU, $this->unitA);

        Assert::throws(TenantBoundaryViolation::class, fn () => $repository->insert(
            $this->conversation($this->tenantB, $this->unitA, $this->userU, 'F8B teste alheia'),
        ));
    }

    public function testDeleteOwnedCascadesMessagesAndKeepsToolCalls(): void
    {
        $repository = $this->conversations($this->tenantA, $this->userU, $this->unitA);
        $id = (int) $repository->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B teste apagar'))->id();
        $now = new DateTimeImmutable('2026-10-15 10:00:00');
        $repository->appendMessage(AiConversationMessage::user($id, 'F8B pergunta', $now));
        $repository->appendMessage(AiConversationMessage::assistant($id, 'F8B resposta', [['tool' => 'buscar_paciente', 'label' => 'Busca', 'count' => 1]], $now));

        $calls = $this->toolCalls($this->tenantA, $this->userU, $this->unitA);
        $calls->insert($this->call($this->tenantA, $this->unitA, $this->userU, $id, 'buscar_paciente', McpToolCall::DECISION_ALLOWED, null));

        Assert::same('2', $this->scalar('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ?', [$id]));
        $callsBefore = $this->scalar('SELECT COUNT(*) FROM mcp_tool_call WHERE conversation_id = ?', [$id]);
        Assert::same('1', $callsBefore);

        Assert::true($repository->deleteOwned($id));

        Assert::null($repository->findOwned($id));
        Assert::same('0', $this->scalar('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ?', [$id]));
        Assert::same($callsBefore, $this->scalar('SELECT COUNT(*) FROM mcp_tool_call WHERE conversation_id = ?', [$id]));
        Assert::false($repository->deleteOwned($id), 'second delete finds nothing');
    }

    public function testLastMessagesReturnsTailInChronologicalOrder(): void
    {
        $repository = $this->conversations($this->tenantA, $this->userU, $this->unitA);
        $id = (int) $repository->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B teste histórico'))->id();
        $start = new DateTimeImmutable('2026-10-15 08:00:00');

        for ($i = 1; $i <= 25; $i++) {
            $at = $start->modify("+{$i} minutes");
            $message = $i % 2 === 1
                ? AiConversationMessage::user($id, "F8B msg {$i}", $at)
                : AiConversationMessage::assistant($id, "F8B msg {$i}", [], $at);
            Assert::true((int) $repository->appendMessage($message)->id() > 0);
        }

        $tail = $repository->lastMessages($id, 20);
        Assert::count(20, $tail);
        Assert::same(array_map(static fn (int $i): string => "F8B msg {$i}", range(6, 25)), array_map(
            static fn (AiConversationMessage $message): string => $message->content(),
            $tail,
        ));
        Assert::same($id, $tail[0]->conversationId());

        Assert::same([], $this->conversations($this->tenantA, $this->userV, $this->unitA)->lastMessages($id, 20), 'other user');
        Assert::same([], $this->conversations($this->tenantA, $this->userU, $this->unitB)->lastMessages($id, 20), 'other unit');
        Assert::same(
            (string) $this->tenantA,
            $this->scalar('SELECT DISTINCT tenant_id FROM ai_conversation_message WHERE conversation_id = ?', [$id]),
        );
    }

    public function testAppendMessageOnlyToOwnedConversation(): void
    {
        $owner = $this->conversations($this->tenantA, $this->userU, $this->unitA);
        $id = (int) $owner->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B teste dono'))->id();
        $now = new DateTimeImmutable('2026-10-15 09:00:00');

        foreach ([
            'other user' => $this->conversations($this->tenantA, $this->userV, $this->unitA),
            'other unit' => $this->conversations($this->tenantA, $this->userU, $this->unitB),
            'other tenant' => $this->conversations($this->tenantB, $this->userU, $this->unitA),
        ] as $case => $repository) {
            Assert::throws(InvalidArgumentException::class, fn () => $repository->appendMessage(
                AiConversationMessage::user($id, 'F8B intrusa', $now),
            ), $case);
        }

        Assert::same('0', $this->scalar('SELECT COUNT(*) FROM ai_conversation_message WHERE conversation_id = ?', [$id]));

        $failed = $owner->appendMessage(AiConversationMessage::failed($id, 'ai_provider_error', $now));
        $stored = $owner->lastMessages($id, 5);
        Assert::count(1, $stored);
        Assert::same((int) $failed->id(), $stored[0]->id());
        Assert::true($stored[0]->isFailed());
        Assert::same(AiConversationMessage::ROLE_ASSISTANT, $stored[0]->role());
    }

    public function testListOwnedFiltersAndOrdersByUpdatedAtAndTouchPersists(): void
    {
        $repository = $this->conversations($this->tenantA, $this->userU, $this->unitA);
        $old = $repository->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B antiga', new DateTimeImmutable('2026-10-01 10:00:00')));
        $new = $repository->insert($this->conversation($this->tenantA, $this->unitA, $this->userU, 'F8B nova', new DateTimeImmutable('2026-10-10 10:00:00')));
        $management = $repository->insert(AiConversation::start(
            $this->tenantA,
            $this->unitA,
            $this->userU,
            AiConversation::FAMILY_MANAGEMENT,
            null,
            'F8B gestão',
            new DateTimeImmutable('2026-10-05 10:00:00'),
        ));
        $this->conversations($this->tenantA, $this->userV, $this->unitA)
            ->insert($this->conversation($this->tenantA, $this->unitA, $this->userV, 'F8B de V'));
        $this->conversations($this->tenantA, $this->userU, $this->unitB)
            ->insert($this->conversation($this->tenantA, $this->unitB, $this->userU, 'F8B outra unidade'));

        Assert::same([$new->id(), $management->id(), $old->id()], $this->ids($repository->listOwned(null, null, 10)));
        Assert::same([$new->id(), $old->id()], $this->ids($repository->listOwned(AiConversation::FAMILY_CLINICAL, null, 10)));
        Assert::same([$new->id()], $this->ids($repository->listOwned(null, null, 1)));

        $old->touch(new DateTimeImmutable('2026-10-12 10:00:00'));
        $repository->touch($old);
        Assert::same('2026-10-12 10:00:00', $repository->findOwned((int) $old->id())?->updatedAt()->format('Y-m-d H:i:s'));
        Assert::same([$old->id(), $new->id()], $this->ids($repository->listOwned(AiConversation::FAMILY_CLINICAL, null, 10)));

        $foreign = $this->conversations($this->tenantA, $this->userV, $this->unitA);
        $stolen = $repository->findOwned((int) $new->id());
        Assert::notNull($stolen);
        $stolen->touch(new DateTimeImmutable('2026-10-20 10:00:00'));
        $foreign->touch($stolen);
        Assert::same('2026-10-10 10:00:00', $repository->findOwned((int) $new->id())?->updatedAt()->format('Y-m-d H:i:s'), 'touch of another user is ignored');
    }

    public function testToolCallSearchIsTenantScopedAndFiltered(): void
    {
        $a = $this->toolCalls($this->tenantA, $this->userU, $this->unitA);
        $b = $this->toolCalls($this->tenantB, $this->userU, $this->unitA);

        $allowed = $a->insert($this->call($this->tenantA, $this->unitA, $this->userU, null, 'buscar_paciente', McpToolCall::DECISION_ALLOWED, null));
        $denied = $a->insert($this->call($this->tenantA, $this->unitA, $this->userU, null, 'buscar_paciente', McpToolCall::DECISION_DENIED, 'forbidden'));
        $a->insert($this->call($this->tenantA, $this->unitA, $this->userV, null, 'obter_paciente', McpToolCall::DECISION_DENIED, 'not_found'));
        $b->insert($this->call($this->tenantB, $this->unitA, $this->userU, null, 'buscar_paciente', McpToolCall::DECISION_DENIED, 'forbidden'));
        Assert::true((int) $allowed->id() > 0);

        $found = $a->search(null, 'buscar_paciente', 'denied', null, null, 50);
        Assert::count(1, $found);
        Assert::same($denied->id(), $found[0]->id());
        Assert::same($this->tenantA, $found[0]->tenantId());
        Assert::same('forbidden', $found[0]->denyReason());
        Assert::notNull($found[0]->createdAt());

        Assert::count(3, $a->search(null, null, null, null, null, 50));
        Assert::count(1, $a->search($this->userV, null, null, null, null, 50));
        Assert::count(2, $a->search(null, 'buscar_paciente', null, null, null, 50));
        Assert::count(1, $a->search(null, null, null, null, null, 1));

        $this->pdo->prepare('UPDATE mcp_tool_call SET created_at = :at WHERE id = :id')
            ->execute(['at' => '2026-09-01 10:00:00', 'id' => $allowed->id()]);
        $inSeptember = $a->search(null, null, null, new DateTimeImmutable('2026-09-01 00:00:00'), new DateTimeImmutable('2026-09-30 23:59:59'), 50);
        Assert::same([$allowed->id()], array_map(static fn (McpToolCall $call): ?int => $call->id(), $inSeptember));

        Assert::throws(TenantBoundaryViolation::class, fn () => $a->insert(
            $this->call($this->tenantB, $this->unitA, $this->userU, null, 'buscar_paciente', McpToolCall::DECISION_ALLOWED, null),
        ));
    }

    private function conversations(int $tenantId, int $userId, int $unitId): AiConversationRepository
    {
        return new AiConversationRepository(TenantContext::authenticated($tenantId, $userId, $unitId), $this->pdo);
    }

    private function toolCalls(int $tenantId, int $userId, int $unitId): McpToolCallRepository
    {
        return new McpToolCallRepository(TenantContext::authenticated($tenantId, $userId, $unitId), $this->pdo);
    }

    private function conversation(int $tenantId, int $unitId, int $userId, string $title, ?DateTimeImmutable $now = null): AiConversation
    {
        return AiConversation::start(
            $tenantId,
            $unitId,
            $userId,
            AiConversation::FAMILY_CLINICAL,
            null,
            $title,
            $now ?? new DateTimeImmutable('2026-10-15 07:00:00'),
        );
    }

    private function call(
        int $tenantId,
        int $unitId,
        int $userId,
        ?int $conversationId,
        string $tool,
        string $decision,
        ?string $denyReason,
    ): McpToolCall {
        return McpToolCall::record(
            $tenantId,
            $unitId,
            $userId,
            $conversationId,
            'f8b-corr-' . bin2hex(random_bytes(4)),
            $tool,
            '{"termo":"[min]"}',
            $decision,
            $denyReason,
            1,
            false,
            12,
        );
    }

    /**
     * @param list<AiConversation> $conversations
     * @return list<?int>
     */
    private function ids(array $conversations): array
    {
        return array_map(static fn (AiConversation $conversation): ?int => $conversation->id(), $conversations);
    }

    /** @param list<int|string> $parameters */
    private function scalar(string $sql, array $parameters): ?string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'F8B teste tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}

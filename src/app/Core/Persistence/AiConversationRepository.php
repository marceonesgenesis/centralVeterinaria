<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\Contract\AiConversationRepositoryInterface;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence of `ai_conversation` and `ai_conversation_message`
 * (migration 0015). Every query starts from TenantQuery::forTenant() with
 * the tenant of the context (ADR 0002); "owned" adds the active unit
 * (`requireUnitId()`) and the user of the context, so a conversation of
 * another tenant, unit or user behaves as missing. Messages carry the
 * tenant of the context and are only appended to an owned conversation;
 * deleting a conversation cascades to its messages (FK), while the tool
 * call audit (`mcp_tool_call`, no FK) stays.
 *
 * Titles and message contents are never put into exception messages.
 */
final class AiConversationRepository implements AiConversationRepositoryInterface
{
    public function __construct(private readonly TenantContext $context, private readonly PDO $connection)
    {
    }

    public function insert(AiConversation $conversation): AiConversation
    {
        if ($conversation->id() !== null) {
            throw new InvalidArgumentException('Conversation already has an id');
        }

        $this->context->assertTenant($conversation->tenantId());

        $row = $conversation->toRow();
        unset($row['id']);
        $row['tenant_id'] = $this->context->tenantId();

        $this->insertRow('ai_conversation', $row);
        $conversation->assignId((int) $this->connection->lastInsertId());

        return $conversation;
    }

    public function findOwned(int $id): ?AiConversation
    {
        $query = $this->owned()->andEquals('id', $id);

        $statement = $this->connection->prepare("SELECT * FROM ai_conversation WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : AiConversation::reconstitute($row);
    }

    public function touch(AiConversation $conversation): void
    {
        $id = $conversation->id();

        if ($id === null) {
            return;
        }

        $query = $this->owned()->andEquals('id', $id);

        $statement = $this->connection->prepare(
            "UPDATE ai_conversation SET updated_at = :updated_at WHERE {$query->whereSql()}",
        );
        $statement->execute([...$query->parameters(), ':updated_at' => $conversation->toRow()['updated_at']]);
    }

    public function listOwned(?string $family, ?int $anchorPatientId, int $limit): array
    {
        $query = $this->owned();

        if ($family !== null) {
            $query = $query->andEquals('family', $family);
        }

        if ($anchorPatientId !== null) {
            $query = $query->andEquals('anchor_patient_id', $anchorPatientId);
        }

        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            "SELECT * FROM ai_conversation WHERE {$query->whereSql()} ORDER BY updated_at DESC, id DESC LIMIT {$limit}",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): AiConversation => AiConversation::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function deleteOwned(int $id): bool
    {
        $query = $this->owned()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM ai_conversation WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());

        return $statement->rowCount() === 1;
    }

    public function appendMessage(AiConversationMessage $message): AiConversationMessage
    {
        if ($message->id() !== null) {
            throw new InvalidArgumentException('Message already has an id');
        }

        if ($this->findOwned($message->conversationId()) === null) {
            throw new InvalidArgumentException('Conversation not found');
        }

        $row = $message->toRow();
        unset($row['id']);
        $row = ['tenant_id' => $this->context->tenantId()] + $row;

        $this->insertRow('ai_conversation_message', $row);
        $message->assignId((int) $this->connection->lastInsertId());

        return $message;
    }

    public function lastMessages(int $conversationId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $owned = $this->owned('c')->andEquals('id', $conversationId, 'c');

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT tail.* FROM (
                SELECT m.* FROM ai_conversation_message m
                JOIN ai_conversation c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                WHERE {$owned->whereSql()}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT {$limit}
            ) tail
            ORDER BY tail.created_at, tail.id
            SQL
        );
        $statement->execute($owned->parameters());

        return array_map(
            static fn (array $row): AiConversationMessage => AiConversationMessage::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** Tenant, active unit and user of the context. */
    private function owned(?string $alias = null): TenantQuery
    {
        return TenantQuery::forTenant($this->context->tenantId(), $alias)
            ->andEquals('system_unit_id', $this->context->requireUnitId(), $alias)
            ->andEquals('system_user_id', $this->context->userId(), $alias);
    }

    /** @param array<string, mixed> $row */
    private function insertRow(string $table, array $row): void
    {
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = $this->connection->prepare(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
        );
        $statement->execute(array_combine($placeholders, array_values($row)));
    }
}

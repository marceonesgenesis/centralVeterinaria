<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\Contract\AiConversationRepositoryInterface;
use InvalidArgumentException;

/**
 * In-memory double for AiConversationRepositoryInterface (8B T-05). The
 * constructor fixes the request context (tenant, active unit, user);
 * insert() stores any conversation as it is (so tests can seed foreign
 * ones), while every read, touch, delete and append only sees conversations
 * owned by that context. Returns fresh reconstitute() copies, like PDO.
 * listOwned() orders by updated_at DESC, id DESC; lastMessages() returns the
 * tail ordered by created_at, id. deleteOwned() cascades to the messages.
 */
final class FakeAiConversationRepository implements AiConversationRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $conversations = [];

    /** @var array<int, array<string, mixed>> */
    private array $messageRows = [];
    private int $nextConversationId = 1;
    private int $nextMessageId = 1;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $systemUserId,
    ) {
    }

    public function insert(AiConversation $conversation): AiConversation
    {
        if ($conversation->id() !== null) {
            throw new InvalidArgumentException('Conversation already has an id');
        }

        $conversation->assignId($this->nextConversationId++);
        $this->conversations[(int) $conversation->id()] = $conversation->toRow();

        return $conversation;
    }

    public function findOwned(int $id): ?AiConversation
    {
        $row = $this->ownRow($id);

        return $row === null ? null : AiConversation::reconstitute($row);
    }

    public function touch(AiConversation $conversation): void
    {
        $id = $conversation->id();

        if ($id === null || $this->ownRow($id) === null) {
            return;
        }

        $this->conversations[$id]['updated_at'] = $conversation->toRow()['updated_at'];
    }

    public function listOwned(?string $family, ?int $anchorPatientId, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->conversations,
            fn (array $row): bool => $this->owns($row)
                && ($family === null || $row['family'] === $family)
                && ($anchorPatientId === null || $row['anchor_patient_id'] === $anchorPatientId),
        ));
        usort($rows, static fn (array $a, array $b): int => [$b['updated_at'], $b['id']] <=> [$a['updated_at'], $a['id']]);

        return array_map(
            static fn (array $row): AiConversation => AiConversation::reconstitute($row),
            array_slice($rows, 0, max(0, $limit)),
        );
    }

    public function deleteOwned(int $id): bool
    {
        if ($this->ownRow($id) === null) {
            return false;
        }

        unset($this->conversations[$id]);
        $this->messageRows = array_filter($this->messageRows, static fn (array $row): bool => $row['conversation_id'] !== $id);

        return true;
    }

    public function appendMessage(AiConversationMessage $message): AiConversationMessage
    {
        if ($this->ownRow($message->conversationId()) === null) {
            throw new InvalidArgumentException('Conversation not found');
        }

        if ($message->id() !== null) {
            throw new InvalidArgumentException('Message already has an id');
        }

        $message->assignId($this->nextMessageId++);
        $this->messageRows[(int) $message->id()] = ['tenant_id' => $this->tenantId] + $message->toRow();

        return $message;
    }

    public function lastMessages(int $conversationId, int $limit): array
    {
        if ($this->ownRow($conversationId) === null || $limit <= 0) {
            return [];
        }

        $rows = $this->sortedMessages($conversationId);

        return array_map(
            static fn (array $row): AiConversationMessage => AiConversationMessage::reconstitute($row),
            array_slice($rows, -$limit),
        );
    }

    /**
     * Every stored message (all conversations), ordered by created_at, id.
     *
     * @return list<AiConversationMessage>
     */
    public function messages(): array
    {
        return array_map(
            static fn (array $row): AiConversationMessage => AiConversationMessage::reconstitute($row),
            $this->sortedMessages(null),
        );
    }

    /** @return list<array<string, mixed>> */
    private function sortedMessages(?int $conversationId): array
    {
        $rows = array_values(array_filter(
            $this->messageRows,
            static fn (array $row): bool => $conversationId === null || $row['conversation_id'] === $conversationId,
        ));
        usort($rows, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function ownRow(int $id): ?array
    {
        $row = $this->conversations[$id] ?? null;

        return $row !== null && $this->owns($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function owns(array $row): bool
    {
        return $row['tenant_id'] === $this->tenantId
            && $row['system_unit_id'] === $this->systemUnitId
            && $row['system_user_id'] === $this->systemUserId;
    }
}

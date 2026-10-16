<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;

/**
 * Persistence of `ai_conversation` and `ai_conversation_message`.
 *
 * "Owned" = same tenant, active unit and user of the request context; any
 * other conversation behaves as missing (`null`/`false`).
 */
interface AiConversationRepositoryInterface
{
    /** Inserts the conversation and returns it with the assigned id. */
    public function insert(AiConversation $conversation): AiConversation;

    public function findOwned(int $id): ?AiConversation;

    /** Persists `updated_at` of an owned conversation. */
    public function touch(AiConversation $conversation): void;

    /**
     * Owned conversations, most recent `updated_at` first.
     *
     * @return list<AiConversation>
     */
    public function listOwned(?string $family, ?int $anchorPatientId, int $limit): array;

    /** Deletes an owned conversation (messages cascade); false when not owned. */
    public function deleteOwned(int $id): bool;

    /** Inserts the message (tenant from the context) and returns it with the id. */
    public function appendMessage(AiConversationMessage $message): AiConversationMessage;

    /**
     * The last `$limit` messages of an owned conversation, in chronological order.
     *
     * @return list<AiConversationMessage>
     */
    public function lastMessages(int $conversationId, int $limit): array;
}

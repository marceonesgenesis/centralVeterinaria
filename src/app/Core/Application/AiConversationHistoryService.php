<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\Contract\AiConversationRepositoryInterface;
use CentralVet\Persistence\AiConversationRepository;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\TenantContext;
use PDO;

/**
 * Copilot history of the author ("Minhas conversas", Fase 8B, T-15).
 *
 * Lists, opens and deletes the conversations owned by the context user in
 * the active unit, with the same RBAC as AiCopilotService
 * (`AiCopilotPanel::onOpen` / `AiCopilotPanel::onDelete`, unit scope). It
 * never touches the gateway, the tool limiter, flags, terms or quota: the
 * author can always see and delete their own history.
 */
final class AiConversationHistoryService
{
    /** Messages shown when a conversation is opened. */
    public const CONVERSATION_VIEW_LIMIT = 500;

    private const ACTION_OPEN = 'AiCopilotPanel::onOpen';
    private const ACTION_DELETE = 'AiCopilotPanel::onDelete';
    private const ENTITY_TYPE = 'ai_conversation';

    public function __construct(
        private readonly AiConversationRepositoryInterface $conversations,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
    ) {
    }

    public static function forContext(TenantContext $context, PDO $connection): self
    {
        return new self(
            new AiConversationRepository($context, $connection),
            new RbacAuthorizationService(
                new AdiantiProgramPermissionProvider(new AdiantiSessionContextSource()),
                new PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * @param string|null $family only conversations of this family (null: all)
     * @param int|null $anchorPatientId only conversations anchored to this patient (null: any)
     * @return list<AiConversation> owned conversations of the active unit, most recent first
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiCopilotPanel::onOpen`.
     */
    public function conversations(int $limit, ?string $family = null, ?int $anchorPatientId = null): array
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_OPEN, null, ['list' => true]);

        return $this->conversations->listOwned($family, $anchorPatientId, $limit);
    }

    /**
     * The conversation of the author in the active unit with its last
     * CONVERSATION_VIEW_LIMIT messages; null when it is not owned (another
     * user, unit or tenant) or does not exist.
     *
     * @return array{conversation: AiConversation, messages: list<AiConversationMessage>}|null
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiCopilotPanel::onOpen`.
     */
    public function conversation(int $id): ?array
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_OPEN, $id, []);

        $conversation = $this->conversations->findOwned($id);

        if ($conversation === null) {
            return null;
        }

        return [
            'conversation' => $conversation,
            'messages' => $this->conversations->lastMessages($id, self::CONVERSATION_VIEW_LIMIT),
        ];
    }

    /**
     * @return bool false when the conversation is not owned in the active unit
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied without `AiCopilotPanel::onDelete`.
     */
    public function deleteConversation(int $id): bool
    {
        $this->context->requireUnitId();
        $this->authorize(self::ACTION_DELETE, $id, []);

        return $this->conversations->deleteOwned($id);
    }

    /** @param array<string, scalar|null> $metadata ids and codes only */
    private function authorize(string $action, ?int $conversationId, array $metadata): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: true,
            resourceTenantId: $this->context->tenantId(),
            resourceUnitId: $this->context->unitId(),
            entityType: self::ENTITY_TYPE,
            entityId: $conversationId,
            metadata: $metadata,
        ))->assertAllowed();
    }
}

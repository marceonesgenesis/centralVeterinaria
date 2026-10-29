<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\AiConversationHistoryService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiConversationRepository;
use CentralVet\Tests\Support\FakeAuthorizationPolicy;
use DateTimeImmutable;

/**
 * T-28: reading an existing conversation depends only on the history
 * (tenant, active unit and author) and RBAC `AiCopilotPanel::onOpen`. The
 * service has no flag, terms, quota, gateway or Redis dependency, so the
 * author reads it even with the AI unavailable; another user, unit or
 * tenant gets null.
 */
final class AiConversationHistoryServiceTest
{
    private const TENANT_ID = 1;
    private const UNIT_ID = 5;
    private const USER_ID = 3;

    private FakeAiConversationRepository $conversations;
    private FakeAuthorizationPolicy $authorization;

    public function setUp(): void
    {
        $this->conversations = new FakeAiConversationRepository(self::TENANT_ID, self::UNIT_ID, self::USER_ID);
        $this->authorization = new FakeAuthorizationPolicy(true);
    }

    public function testConversationReturnsTheMessagesOfTheAuthorWithoutAnyAiDependency(): void
    {
        $id = $this->seed(self::TENANT_ID, self::UNIT_ID, self::USER_ID);
        $now = new DateTimeImmutable('2026-10-15 10:00:00');
        $this->conversations->appendMessage(AiConversationMessage::user($id, 'Pergunta', $now));
        $this->conversations->appendMessage(AiConversationMessage::assistant($id, 'Resposta', [], $now->modify('+1 second')));

        $found = $this->service()->conversation($id);

        Assert::notNull($found);
        Assert::same($id, $found['conversation']->id());
        Assert::count(2, $found['messages']);
        Assert::same('Pergunta', $found['messages'][0]->content());
        Assert::same('Resposta', $found['messages'][1]->content());

        $request = $this->authorization->requests[0];
        Assert::same('AiCopilotPanel::onOpen', $request->action());
        Assert::same($id, $request->entityId());
    }

    public function testConversationOfAnotherUserUnitOrTenantIsNull(): void
    {
        $otherUser = $this->seed(self::TENANT_ID, self::UNIT_ID, 99);
        $otherUnit = $this->seed(self::TENANT_ID, 6, self::USER_ID);
        $otherTenant = $this->seed(2, self::UNIT_ID, self::USER_ID);

        Assert::null($this->service()->conversation($otherUser), 'other user');
        Assert::null($this->service()->conversation($otherUnit), 'other unit');
        Assert::null($this->service()->conversation($otherTenant), 'other tenant');
        Assert::null($this->service()->conversation(9999), 'missing');
    }

    public function testConversationIsGuardedByThePanelPermission(): void
    {
        $id = $this->seed(self::TENANT_ID, self::UNIT_ID, self::USER_ID);
        $this->authorization->setAllowed(false);

        Assert::throws(AuthorizationDenied::class, fn () => $this->service()->conversation($id));
    }

    public function testConversationsFiltersByFamilyAndAnchor(): void
    {
        $clinical = $this->seed(self::TENANT_ID, self::UNIT_ID, self::USER_ID, 'clinical', 20);
        $this->seed(self::TENANT_ID, self::UNIT_ID, self::USER_ID, 'clinical', 21);
        $management = $this->seed(self::TENANT_ID, self::UNIT_ID, self::USER_ID, 'management', null);

        $anchored = $this->service()->conversations(10, 'clinical', 20);
        $managementOnly = $this->service()->conversations(10, 'management');

        Assert::same([$clinical], array_map(static fn (AiConversation $c): ?int => $c->id(), $anchored));
        Assert::same([$management], array_map(static fn (AiConversation $c): ?int => $c->id(), $managementOnly));
        Assert::count(3, $this->service()->conversations(10));
    }

    private function service(): AiConversationHistoryService
    {
        return new AiConversationHistoryService(
            $this->conversations,
            $this->authorization,
            TenantContext::authenticated(self::TENANT_ID, self::USER_ID, self::UNIT_ID),
        );
    }

    private function seed(int $tenantId, int $unitId, int $userId, string $family = 'clinical', ?int $anchor = null): int
    {
        $conversation = $this->conversations->insert(AiConversation::start(
            $tenantId,
            $unitId,
            $userId,
            $family,
            $anchor,
            'Conversa',
            new DateTimeImmutable('2026-10-15 09:00:00'),
        ));

        return (int) $conversation->id();
    }
}

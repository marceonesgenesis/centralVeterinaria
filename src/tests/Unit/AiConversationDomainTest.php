<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\AiConversation;
use CentralVet\Domain\AiConversationMessage;
use CentralVet\Domain\McpToolCall;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAiConversationRepository;
use CentralVet\Tests\Support\FakeMcpToolCallRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class AiConversationDomainTest
{
    public function testStartCutsTitleAtEightyCharacters(): void
    {
        $conversation = AiConversation::start(1, 2, 3, 'clinical', 7, str_repeat('a', 120), self::now());

        Assert::same(80, mb_strlen($conversation->title()));
        Assert::same(AiConversation::TITLE_MAX, mb_strlen($conversation->title()));
        Assert::null($conversation->id());
        Assert::same(7, $conversation->anchorPatientId());
        Assert::same('clinical', $conversation->family());

        $multibyte = AiConversation::start(1, 2, 3, 'clinical', null, str_repeat('ç', 100), self::now());
        Assert::same(80, mb_strlen($multibyte->title()));
        Assert::true(mb_check_encoding($multibyte->title(), 'UTF-8'));
    }

    public function testFamilyAndAnchorInvariants(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => AiConversation::start(1, 2, 3, 'management', 7, 'Estoque', self::now()));
        Assert::throws(InvalidArgumentException::class, fn () => AiConversation::start(1, 2, 3, 'finance', null, 'X', self::now()));
        Assert::throws(InvalidArgumentException::class, fn () => AiConversation::start(1, 2, 3, 'clinical', null, '   ', self::now()));
        Assert::throws(InvalidArgumentException::class, fn () => AiConversation::start(0, 2, 3, 'clinical', null, 'X', self::now()));

        $management = AiConversation::start(1, 2, 3, 'management', null, 'Estoque', self::now());
        Assert::null($management->anchorPatientId());
    }

    public function testConversationRoundTripsThroughRow(): void
    {
        $conversation = AiConversation::start(1, 2, 3, 'clinical', 7, 'Rex', self::now());
        $conversation->assignId(10);
        $later = self::now()->modify('+5 minutes');
        $conversation->touch($later);

        $copy = AiConversation::reconstitute($conversation->toRow());

        Assert::same(10, $copy->id());
        Assert::same(1, $copy->tenantId());
        Assert::same(2, $copy->systemUnitId());
        Assert::same(3, $copy->systemUserId());
        Assert::same('Rex', $copy->title());
        Assert::same($later->format('Y-m-d H:i:s'), $copy->updatedAt()->format('Y-m-d H:i:s'));
        Assert::throws(InvalidArgumentException::class, fn () => $conversation->assignId(11));
    }

    public function testMessagesAndFailedOnlyOnAssistant(): void
    {
        $user = AiConversationMessage::user(10, 'Qual o peso?', self::now());
        Assert::same('user', $user->role());
        Assert::false($user->failed());
        Assert::same([], $user->toolSummary());

        $summary = [['tool' => 'obter_paciente', 'label' => 'Paciente', 'count' => 1]];
        $assistant = AiConversationMessage::assistant(10, '12 kg', $summary, self::now());
        Assert::same($summary, $assistant->toolSummary());
        Assert::same($summary, AiConversationMessage::reconstitute($assistant->toRow() + ['id' => 5])->toolSummary());

        $failed = AiConversationMessage::failed(10, 'ai_timeout', self::now());
        Assert::same('assistant', $failed->role());
        Assert::true($failed->failed());
        Assert::same('ai_timeout', $failed->content());
        Assert::throws(InvalidArgumentException::class, fn () => AiConversationMessage::failed(10, 'texto livre', self::now()));

        Assert::throws(InvalidArgumentException::class, fn () => AiConversationMessage::reconstitute([
            'id' => 1, 'conversation_id' => 10, 'role' => 'user', 'content' => 'x', 'tool_summary' => null, 'failed' => 1,
            'created_at' => '2026-10-15 10:00:00.000000',
        ]));

        $big = array_fill(0, 200, ['tool' => 'obter_prontuario', 'label' => 'Prontuário', 'count' => 1]);
        $row = AiConversationMessage::assistant(10, 'ok', $big, self::now())->toRow();
        Assert::true(strlen((string) $row['tool_summary']) <= 2000);
        Assert::notNull(json_decode((string) $row['tool_summary'], true));
    }

    public function testToolCallDecisionInvariants(): void
    {
        Assert::throws(InvalidArgumentException::class, fn () => self::call('denied', null));
        Assert::throws(InvalidArgumentException::class, fn () => self::call('denied', 'unknown_reason'));
        Assert::throws(InvalidArgumentException::class, fn () => self::call('allowed', 'forbidden'));
        Assert::throws(InvalidArgumentException::class, fn () => self::call('maybe', null));

        $allowed = self::call(McpToolCall::DECISION_ALLOWED, null);
        Assert::null($allowed->denyReason());
        $denied = self::call(McpToolCall::DECISION_DENIED, 'rate_limited');
        Assert::same('rate_limited', $denied->denyReason());
        Assert::count(7, McpToolCall::DENY_REASONS);

        $copy = McpToolCall::reconstitute($denied->toRow() + ['id' => 3, 'created_at' => '2026-10-15 10:00:00.000000']);
        Assert::same(3, $copy->id());
        Assert::same('denied', $copy->decision());
        Assert::same('buscar_paciente', $copy->toolName());
    }

    public function testFakeConversationRepositoryScopesToOwner(): void
    {
        $repo = new FakeAiConversationRepository(1, 2, 3);
        $mine = $repo->insert(AiConversation::start(1, 2, 3, 'clinical', 7, 'Minha', self::now()));
        $other = $repo->insert(AiConversation::start(1, 2, 4, 'clinical', 7, 'Do usuário 4', self::now()));
        $otherUnit = $repo->insert(AiConversation::start(1, 9, 3, 'clinical', 7, 'Outra unidade', self::now()));
        $otherTenant = $repo->insert(AiConversation::start(5, 2, 3, 'clinical', 7, 'Outro tenant', self::now()));

        Assert::notNull($repo->findOwned((int) $mine->id()));
        Assert::null($repo->findOwned((int) $other->id()));
        Assert::null($repo->findOwned((int) $otherUnit->id()));
        Assert::null($repo->findOwned((int) $otherTenant->id()));

        $repo->insert(AiConversation::start(1, 2, 3, 'management', null, 'Gestão', self::now()->modify('+1 minute')));
        $listed = $repo->listOwned(null, null, 10);
        Assert::count(2, $listed);
        Assert::same('Gestão', $listed[0]->title());
        Assert::count(1, $repo->listOwned('clinical', 7, 10));
        Assert::count(0, $repo->listOwned('clinical', 8, 10));

        Assert::false($repo->deleteOwned((int) $other->id()));
        Assert::true($repo->deleteOwned((int) $mine->id()));
        Assert::null($repo->findOwned((int) $mine->id()));
    }

    public function testFakeLastMessagesReturnsTailInChronologicalOrder(): void
    {
        $repo = new FakeAiConversationRepository(1, 2, 3);
        $conversation = $repo->insert(AiConversation::start(1, 2, 3, 'clinical', null, 'Chat', self::now()));
        $id = (int) $conversation->id();

        for ($i = 1; $i <= 25; $i++) {
            $repo->appendMessage(AiConversationMessage::user($id, "m{$i}", self::now()->modify("+{$i} seconds")));
        }

        $last = $repo->lastMessages($id, 20);
        Assert::count(20, $last);
        Assert::same('m6', $last[0]->content());
        Assert::same('m25', $last[19]->content());
        Assert::count(25, $repo->messages());
    }

    public function testFakeToolCallSearchFilters(): void
    {
        $repo = new FakeMcpToolCallRepository(1);
        $repo->insert(self::call('allowed', null));
        $repo->insert(McpToolCall::record(1, 2, 4, null, 'corr-2', 'obter_exames', '{}', 'denied', 'forbidden', 0, false, 3));
        $repo->insert(McpToolCall::record(9, 2, 3, null, 'corr-3', 'buscar_paciente', '{}', 'allowed', null, 1, false, 3));

        Assert::count(2, $repo->search(null, null, null, null, null, 50));
        Assert::count(1, $repo->search(4, null, null, null, null, 50));
        Assert::count(1, $repo->search(null, 'buscar_paciente', null, null, null, 50));
        Assert::count(1, $repo->search(null, null, 'denied', null, null, 50));
        Assert::count(0, $repo->search(null, null, null, new DateTimeImmutable('+1 day'), null, 50));
        Assert::count(2, $repo->search(null, null, null, new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), 50));
        Assert::count(0, $repo->search(null, null, null, null, new DateTimeImmutable('-1 day'), 50));
        Assert::count(3, $repo->all());
    }

    private static function call(string $decision, ?string $denyReason): McpToolCall
    {
        return McpToolCall::record(1, 2, 3, 10, 'corr-1', 'buscar_paciente', '{"q":"[nome]"}', $decision, $denyReason, 2, false, 15);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-15 10:00:00');
    }
}

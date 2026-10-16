<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\StubAiGateway;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Tests\Support\Assert;

/**
 * Fase 8B (T-02). Scripted stub for the tool-use loop tests and AiUsage::plus.
 */
final class StubAiGatewayScriptTest
{
    public function testScriptedStepsAreReturnedInOrderAndRequestsAreRecorded(): void
    {
        $toolStep = new AiCompletionResult(
            '',
            'stub',
            'stub',
            new AiUsage(10, 2),
            0,
            'tool_use',
            null,
            [['id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Thor']]],
            [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Thor']]],
        );
        $finalStep = new AiCompletionResult('Thor tem 3 anos.', 'stub', 'stub', new AiUsage(20, 5), 0, 'end_turn', null);
        $gateway = StubAiGateway::scripted([$toolStep, $finalStep]);

        $first = $gateway->complete($this->request('primeira'));
        $second = $gateway->complete($this->request('segunda'));

        Assert::same($toolStep, $first);
        Assert::same($finalStep, $second);
        Assert::same('buscar_paciente', $first->toolUses[0]['name']);

        $requests = $gateway->requests();
        Assert::count(2, $requests);
        Assert::same('primeira', $requests[0]->userContent);
        Assert::same('segunda', $requests[1]->userContent);
    }

    public function testScriptedExceptionIsThrownAtItsTurn(): void
    {
        $gateway = StubAiGateway::scripted([new AiProviderException('ai_rate_limited', 429)]);

        try {
            $gateway->complete($this->request('x'));
            throw new \RuntimeException('Expected AiProviderException');
        } catch (AiProviderException $exception) {
            Assert::same('ai_rate_limited', $exception->reason());
        }

        Assert::count(1, $gateway->requests());
    }

    public function testExtraStepIsProviderError(): void
    {
        $gateway = StubAiGateway::scripted([new AiCompletionResult('ok', 'stub', 'stub', AiUsage::none(), 0, 'end_turn', null)]);
        $gateway->complete($this->request('a'));

        try {
            $gateway->complete($this->request('b'));
            throw new \RuntimeException('Expected AiProviderException');
        } catch (AiProviderException $exception) {
            Assert::same('ai_provider_error', $exception->reason());
        }

        Assert::count(2, $gateway->requests());
    }

    public function testUnscriptedStubEchoesTheLastUserMessage(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'pergunta antiga'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'resposta']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'pergunta nova']]],
        ];
        $gateway = new StubAiGateway();

        $result = $gateway->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '2', 'system', 'ignorado', $messages));

        Assert::same('[stub] patient_summary v2: pergunta nova', $result->text);
        Assert::same('end_turn', $result->stopReason);
        Assert::same([], $result->toolUses);

        $plain = $gateway->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system', 'texto simples', [['role' => 'user', 'content' => 'só string']]));
        Assert::same('[stub] patient_summary v1: só string', $plain->text);
        Assert::count(2, $gateway->requests());
    }

    public function testRequestCarriesMessagesAndToolsWithDefaults(): void
    {
        $plain = new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system', 'texto');
        Assert::same([], $plain->messages);
        Assert::same([], $plain->tools);

        $tools = [['name' => 'buscar_paciente', 'description' => 'd', 'input_schema' => ['type' => 'object']]];
        $request = new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system', '', [['role' => 'user', 'content' => 'oi']], $tools);
        Assert::same($tools, $request->tools);
        Assert::same('oi', $request->messages[0]['content']);
    }

    public function testUsagePlusSumsTheFourCounters(): void
    {
        $sum = (new AiUsage(1, 2, 3, 4))->plus(new AiUsage(10, 20, 30, 40));

        Assert::same(11, $sum->inputTokens);
        Assert::same(22, $sum->outputTokens);
        Assert::same(33, $sum->cacheReadTokens);
        Assert::same(44, $sum->cacheWriteTokens);
        Assert::same(110, $sum->totalTokens());
    }

    private function request(string $content): AiCompletionRequest
    {
        return new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system', $content);
    }
}

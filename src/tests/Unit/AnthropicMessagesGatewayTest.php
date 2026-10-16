<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiConfig;
use CentralVet\Ai\AiGatewayFactory;
use CentralVet\Ai\AnthropicMessagesGateway;
use CentralVet\Ai\StubAiGateway;
use CentralVet\Ai\UnconfiguredAiGateway;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\Exception\AiProviderException;
use CentralVet\Tests\Support\Assert;

/**
 * Fase 8A (T-07). Every AnthropicMessagesGateway gets a fake transport:
 * no test reaches the network.
 */
final class AnthropicMessagesGatewayTest
{
    private const FAKE_KEY = 'sk-ant-test-f8a';

    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: int, connect: int}> */
    private array $calls = [];

    public function setUp(): void
    {
        $this->calls = [];
    }

    public function testRequestCarriesVersionKeyEffortAndFallbacksWithoutThinking(): void
    {
        $gateway = $this->gateway(['status' => 200, 'body' => $this->okBody(), 'request_id' => 'req_1', 'error' => null]);

        $gateway->complete($this->request());

        Assert::count(1, $this->calls);
        $call = $this->calls[0];
        Assert::same('https', parse_url($call['url'], PHP_URL_SCHEME));
        Assert::same('/v1/messages', parse_url($call['url'], PHP_URL_PATH));
        Assert::same('2023-06-01', $call['headers']['anthropic-version'] ?? null);
        Assert::same(AnthropicMessagesGateway::API_VERSION, $call['headers']['anthropic-version'] ?? null);
        Assert::same(self::FAKE_KEY, $call['headers']['x-api-key'] ?? null);
        Assert::same('application/json', $call['headers']['content-type'] ?? null);
        Assert::same('server-side-fallback-2026-07-01', $call['headers']['anthropic-beta'] ?? null);
        Assert::same(30, $call['timeout']);
        Assert::same(5, $call['connect']);

        $body = json_decode($call['body'], true);
        Assert::same('claude-sonnet-5-5', $body['model']);
        Assert::same(8000, $body['max_tokens']);
        Assert::same([['type' => 'text', 'text' => 'system prompt']], $body['system']);
        Assert::same([['role' => 'user', 'content' => 'clinical text']], $body['messages']);
        Assert::same(['effort' => 'low'], $body['output_config']);
        Assert::same('default', $body['fallbacks']);

        foreach (['thinking', 'temperature', 'top_p', 'top_k', 'stream'] as $field) {
            Assert::false(array_key_exists($field, $body), $field . ' must not be sent');
        }
    }

    public function testFallbacksOffSendsNoBetaHeaderNorField(): void
    {
        $gateway = $this->gateway(
            ['status' => 200, 'body' => $this->okBody(), 'request_id' => null, 'error' => null],
            ['AI_FALLBACKS' => 'off'],
        );

        $gateway->complete($this->request());

        Assert::false(array_key_exists('anthropic-beta', $this->calls[0]['headers']));
        Assert::false(array_key_exists('fallbacks', json_decode($this->calls[0]['body'], true)));
    }

    public function testSuccessfulAnswerBecomesResultWithCost(): void
    {
        $gateway = $this->gateway(['status' => 200, 'body' => $this->okBody(), 'request_id' => 'req_abc', 'error' => null]);

        $result = $gateway->complete($this->request());

        Assert::same('Resumo pronto.', $result->text);
        Assert::same('anthropic', $result->provider);
        Assert::same('claude-sonnet-5-5', $result->model);
        Assert::same(1000, $result->usage->inputTokens);
        Assert::same(500, $result->usage->outputTokens);
        Assert::same(0, $result->usage->cacheReadTokens);
        Assert::same(7000, $result->costMicroUsd);
        Assert::same('end_turn', $result->stopReason);
        Assert::same('req_abc', $result->providerRequestId);
    }

    public function testThinkingBlockBeforeTextIsIgnored(): void
    {
        $body = json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'internal reasoning', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Parte 1. '],
                ['type' => 'text', 'text' => 'Parte 2.'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'cache_read_input_tokens' => 5],
        ]);
        $gateway = $this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null]);

        $result = $gateway->complete($this->request());

        Assert::same('Parte 1. Parte 2.', $result->text);
        Assert::same('claude-sonnet-5', $result->model, 'cost follows the model of the answer (fallback)');
        Assert::same(5, $result->usage->cacheReadTokens);
        Assert::same(0, $result->usage->cacheWriteTokens);
    }

    public function testFallbackIterationsAreSummedAndPricedPerModel(): void
    {
        // Sonnet 5.5 declines before output, Sonnet 5 (fallback) answers.
        // Top-level usage covers only the final attempt; iterations carry each one.
        $body = json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'fallback', 'from' => ['model' => 'claude-sonnet-5-5'], 'to' => ['model' => 'claude-sonnet-5']],
                ['type' => 'text', 'text' => 'Resumo via fallback.'],
            ],
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 500,
                'iterations' => [
                    ['type' => 'message', 'input_tokens' => 1000, 'output_tokens' => 0],
                    ['type' => 'fallback_message', 'input_tokens' => 1000, 'output_tokens' => 500, 'cache_read_input_tokens' => 10],
                ],
            ],
        ]);

        $result = $this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null])->complete($this->request());

        Assert::same('Resumo via fallback.', $result->text);
        Assert::same('claude-sonnet-5', $result->model);
        Assert::same(2000, $result->usage->inputTokens);
        Assert::same(500, $result->usage->outputTokens);
        Assert::same(10, $result->usage->cacheReadTokens);
        // 1000*2 (sonnet-5-5) + (1000*2 + 500*10 + 10*0.20) (sonnet-5) = 9002
        Assert::same(9002, $result->costMicroUsd);
    }

    public function testEachIterationUsesThePriceOfItsOwnModel(): void
    {
        // Requested model priced differently from the fallback: the first
        // attempt (no model field) is priced at the requested model, the
        // fallback attempt at the answering model; an explicit per-iteration
        // `model` wins when present.
        $iterations = [
            ['type' => 'message', 'input_tokens' => 1000, 'output_tokens' => 100],
            ['type' => 'fallback_message', 'input_tokens' => 1000, 'output_tokens' => 500],
        ];
        $body = static fn (array $iterations): string => (string) json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500, 'iterations' => $iterations],
        ]);

        $inferred = $this->gateway(['status' => 200, 'body' => $body($iterations), 'request_id' => null, 'error' => null], ['AI_MODEL' => 'claude-opus-5-5'])
            ->complete($this->request());
        // opus-5-5: 1000*4 + 100*20 = 6000; sonnet-5: 1000*2 + 500*10 = 7000
        Assert::same(13000, $inferred->costMicroUsd);
        Assert::same(2000, $inferred->usage->inputTokens);
        Assert::same(600, $inferred->usage->outputTokens);

        $iterations[0]['model'] = 'claude-haiku-4-5';
        $explicit = $this->gateway(['status' => 200, 'body' => $body($iterations), 'request_id' => null, 'error' => null], ['AI_MODEL' => 'claude-opus-5-5'])
            ->complete($this->request());
        // haiku-4-5: 1000*1 + 100*5 = 1500; sonnet-5: 7000
        Assert::same(8500, $explicit->costMicroUsd);
    }

    public function testRefusalAfterFallbackCarriesSummedUsage(): void
    {
        $body = json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'refusal',
            'content' => [],
            'usage' => [
                'input_tokens' => 300,
                'output_tokens' => 0,
                'iterations' => [
                    ['type' => 'message', 'input_tokens' => 300, 'output_tokens' => 0],
                    ['type' => 'fallback_message', 'input_tokens' => 300, 'output_tokens' => 0],
                ],
            ],
        ]);

        $exception = $this->failure($this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null]));

        Assert::same('ai_refused', $exception->reason());
        Assert::same(600, $exception->usage()?->inputTokens);
    }

    public function testRefusalAfterFallbackCarriesTheCostPricedPerModel(): void
    {
        // Requested model (opus-5-5) priced differently from the fallback
        // (sonnet-5), as in testEachIterationUsesThePriceOfItsOwnModel.
        $body = json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'refusal',
            'content' => [],
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 0,
                'iterations' => [
                    ['type' => 'message', 'input_tokens' => 1000, 'output_tokens' => 100],
                    ['type' => 'fallback_message', 'input_tokens' => 1000, 'output_tokens' => 0],
                ],
            ],
        ]);

        $exception = $this->failure($this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null], ['AI_MODEL' => 'claude-opus-5-5']));

        Assert::same('ai_refused', $exception->reason());
        // opus-5-5: 1000*4 + 100*20 = 6000; sonnet-5: 1000*2 = 2000
        Assert::same(8000, $exception->costMicroUsd());
    }

    public function testFailureWithoutUsageCarriesNoCost(): void
    {
        $exception = $this->failure($this->gateway(['status' => 529, 'body' => '{"type":"error"}', 'request_id' => null, 'error' => null]));

        Assert::null($exception->costMicroUsd());
    }

    public function testHttpAndTransportFailuresMapToReasons(): void
    {
        $cases = [
            'ai_rate_limited' => ['status' => 429, 'body' => '{"type":"error"}', 'request_id' => 'req_x', 'error' => null],
            'ai_overloaded' => ['status' => 529, 'body' => '{"type":"error"}', 'request_id' => null, 'error' => null],
            'ai_auth_failed' => ['status' => 401, 'body' => '{"type":"error"}', 'request_id' => null, 'error' => null],
            'ai_timeout' => ['status' => 0, 'body' => '', 'request_id' => null, 'error' => 'timeout'],
        ];

        foreach ($cases as $reason => $response) {
            $exception = $this->failure($this->gateway($response));
            Assert::same($reason, $exception->reason(), 'response for ' . $reason);
        }

        Assert::same(429, $this->failure($this->gateway($cases['ai_rate_limited']))->httpStatus());
        Assert::null($this->failure($this->gateway($cases['ai_timeout']))->httpStatus());
    }

    public function testOtherStatusesAndConnectErrorAreProviderErrors(): void
    {
        Assert::same('ai_auth_failed', $this->failure($this->gateway(['status' => 403, 'body' => '', 'request_id' => null, 'error' => null]))->reason());

        foreach ([400, 404, 413, 500, 503] as $status) {
            $exception = $this->failure($this->gateway(['status' => $status, 'body' => '{}', 'request_id' => null, 'error' => null]));
            Assert::same('ai_provider_error', $exception->reason(), 'HTTP ' . $status);
            Assert::same($status, $exception->httpStatus());
        }

        $connect = $this->failure($this->gateway(['status' => 0, 'body' => '', 'request_id' => null, 'error' => 'connect']));
        Assert::same('ai_provider_error', $connect->reason());
    }

    public function testRefusalBecomesAiRefusedWithUsage(): void
    {
        $body = json_encode([
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'refusal',
            'content' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 3],
        ]);

        $exception = $this->failure($this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null]));

        Assert::same('ai_refused', $exception->reason());
        Assert::same(100, $exception->usage()?->inputTokens);
        Assert::same('claude-sonnet-5-5', $exception->model());
    }

    public function testMaxTokensBecomesAiTruncatedWithUsage(): void
    {
        $body = json_encode([
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => 'cortado']],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 8000],
        ]);

        $exception = $this->failure($this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null]));

        Assert::same('ai_truncated', $exception->reason());
        Assert::same(8000, $exception->usage()?->outputTokens);
        Assert::same('claude-sonnet-5-5', $exception->model());
    }

    public function testEmptyTextOrInvalidJsonIsProviderError(): void
    {
        $empty = json_encode([
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => '   ']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);

        Assert::same('ai_provider_error', $this->failure($this->gateway(['status' => 200, 'body' => $empty, 'request_id' => null, 'error' => null]))->reason());
        Assert::same('ai_provider_error', $this->failure($this->gateway(['status' => 200, 'body' => 'not json', 'request_id' => null, 'error' => null]))->reason());
    }

    public function testNoExceptionMessageCarriesTheApiKey(): void
    {
        $responses = [
            ['status' => 401, 'body' => '{"error":{"message":"invalid x-api-key ' . self::FAKE_KEY . '"}}', 'request_id' => null, 'error' => null],
            ['status' => 500, 'body' => self::FAKE_KEY, 'request_id' => null, 'error' => null],
            ['status' => 0, 'body' => '', 'request_id' => null, 'error' => 'timeout'],
            ['status' => 0, 'body' => '', 'request_id' => null, 'error' => 'connect'],
            ['status' => 200, 'body' => 'garbage ' . self::FAKE_KEY, 'request_id' => null, 'error' => null],
        ];

        foreach ($responses as $response) {
            $exception = $this->failure($this->gateway($response));
            Assert::false(str_contains($exception->getMessage(), self::FAKE_KEY));
            Assert::false(str_contains($exception->getMessage(), 'x-api-key'));
        }
    }

    public function testNoRetryOnFailure(): void
    {
        $this->failure($this->gateway(['status' => 529, 'body' => '', 'request_id' => null, 'error' => null]));

        Assert::count(1, $this->calls);
    }

    public function testFactoryPicksTheGateway(): void
    {
        Assert::instanceOf(StubAiGateway::class, AiGatewayFactory::fromConfig(AiConfig::fromArray(['AI_PROVIDER' => 'stub'])));

        $anthropic = AiGatewayFactory::fromConfig(AiConfig::fromArray(['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::FAKE_KEY]));
        Assert::instanceOf(AnthropicMessagesGateway::class, $anthropic);
        Assert::true($anthropic->isConfigured());
        Assert::same('anthropic', $anthropic->providerName());

        $unconfigured = AiGatewayFactory::fromConfig(AiConfig::fromArray(['AI_PROVIDER' => 'anthropic']));
        Assert::instanceOf(UnconfiguredAiGateway::class, $unconfigured);
        Assert::false($unconfigured->isConfigured());
    }

    public function testUnconfiguredGatewayThrowsNotConfigured(): void
    {
        $exception = $this->failure(new UnconfiguredAiGateway());

        Assert::same('ai_not_configured', $exception->reason());
    }

    public function testStubAnswersWithoutCostAndTimesOutOnDemand(): void
    {
        $content = str_repeat('a', 200);
        $result = (new StubAiGateway())->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system', $content));

        Assert::same('stub', $result->provider);
        Assert::same('stub', $result->model);
        Assert::same(0, $result->usage->totalTokens());
        Assert::same(0, $result->costMicroUsd);
        Assert::same('[stub] patient_summary v1: ' . str_repeat('a', 160), $result->text);
        Assert::true((new StubAiGateway())->isConfigured());

        $timeout = AiGatewayFactory::fromConfig(AiConfig::fromArray(['AI_PROVIDER' => 'stub', 'AI_STUB_BEHAVIOR' => 'timeout']));
        Assert::same('ai_timeout', $this->failure($timeout)->reason());
        Assert::same('ai_timeout', $this->failure(new StubAiGateway('timeout'))->reason());
    }

    public function testPayloadWithoutToolsNorMessagesIsByteIdenticalToPhase8A(): void
    {
        $this->gateway(['status' => 200, 'body' => $this->okBody(), 'request_id' => null, 'error' => null])->complete($this->request());

        Assert::same(
            '{"model":"claude-sonnet-5-5","max_tokens":8000,"system":[{"type":"text","text":"system prompt"}],'
            . '"messages":[{"role":"user","content":"clinical text"}],"output_config":{"effort":"low"},"fallbacks":"default"}',
            $this->calls[0]['body'],
        );
    }

    public function testToolsAndMessagesAreSentAndToolUseBecomesResult(): void
    {
        $tools = [[
            'name' => 'buscar_paciente',
            'description' => 'Busca pacientes',
            'input_schema' => ['type' => 'object', 'properties' => ['termo' => ['type' => 'string']], 'required' => ['termo']],
        ]];
        $messages = [
            ['role' => 'user', 'content' => 'Quem é o Thor?'],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_0', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Th']]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_0', 'content' => 'invalid_input', 'is_error' => true]]],
        ];
        $responseBody = (string) json_encode([
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Thor']]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 40],
        ]);
        $gateway = $this->gateway(['status' => 200, 'body' => $responseBody, 'request_id' => 'req_t', 'error' => null]);

        $result = $gateway->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system prompt', 'ignored', $messages, $tools));

        $body = json_decode($this->calls[0]['body'], true);
        Assert::same($tools, $body['tools']);
        Assert::same($messages, $body['messages']);
        Assert::same(true, $body['messages'][2]['content'][0]['is_error']);
        Assert::false(str_contains($this->calls[0]['body'], 'ignored'), 'userContent is ignored when messages are given');

        Assert::same('tool_use', $result->stopReason);
        Assert::same('', $result->text);
        Assert::count(1, $result->toolUses);
        Assert::same(['id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Thor']], $result->toolUses[0]);
        Assert::same('buscar_paciente', $result->toolUses[0]['name']);
        Assert::same([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => ['termo' => 'Thor']]], $result->content);
        Assert::same(300, $result->usage->inputTokens);
    }

    public function testEmptyToolInputIsSentAsJsonObject(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Agenda?'],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_0', 'name' => 'x', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_0', 'content' => 'ok']]],
        ];
        $tools = [['name' => 'x', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => []]]];

        $this->gateway(['status' => 200, 'body' => $this->okBody(), 'request_id' => null, 'error' => null])
            ->complete(new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 's', '', $messages, $tools));

        Assert::stringContains('"input":{}', $this->calls[0]['body']);
        Assert::stringContains('"properties":{}', $this->calls[0]['body']);
    }

    public function testToolUseWithoutToolsIsProviderError(): void
    {
        $body = (string) json_encode([
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'text', 'text' => 'vou buscar'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'buscar_paciente', 'input' => []]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $exception = $this->failure($this->gateway(['status' => 200, 'body' => $body, 'request_id' => null, 'error' => null]));

        Assert::same('ai_provider_error', $exception->reason());
        Assert::same(10, $exception->usage()?->inputTokens);
    }

    public function testEndTurnAnswerCarriesItsContentBlocks(): void
    {
        $result = $this->gateway(['status' => 200, 'body' => $this->okBody(), 'request_id' => null, 'error' => null])->complete($this->request());

        Assert::same([], $result->toolUses);
        Assert::same([['type' => 'text', 'text' => 'Resumo pronto.']], $result->content);
    }

    /**
     * @param array{status: int, body: string, request_id: ?string, error: ?string} $response
     * @param array<string, string> $env
     */
    private function gateway(array $response, array $env = []): AnthropicMessagesGateway
    {
        $config = AiConfig::fromArray($env + ['AI_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => self::FAKE_KEY]);

        return new AnthropicMessagesGateway($config, function (string $url, array $headers, string $body, int $timeout, int $connect) use ($response): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout, 'connect' => $connect];

            return $response;
        });
    }

    private function failure(object $gateway): AiProviderException
    {
        try {
            $gateway->complete($this->request());
        } catch (AiProviderException $exception) {
            return $exception;
        }

        throw new \RuntimeException('Expected AiProviderException');
    }

    private function request(): AiCompletionRequest
    {
        return new AiCompletionRequest(AiPurpose::PATIENT_SUMMARY, '1', 'system prompt', 'clinical text');
    }

    private function okBody(): string
    {
        return (string) json_encode([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Resumo pronto.']],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ]);
    }
}

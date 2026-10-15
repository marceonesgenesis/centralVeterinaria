<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Ai\AiConfig;
use CentralVet\Audit\AuditRedactor;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Tests\Support\Assert;

final class AiConfigTest
{
    private const FAKE_KEY = 'sk-ant-test-f8a';

    /** @var list<string> */
    private array $tempFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    public function testDefaultsFromEmptyEnvironment(): void
    {
        $config = AiConfig::fromArray([]);

        Assert::same('anthropic', $config->provider());
        Assert::null($config->apiKey());
        Assert::same('https', parse_url($config->baseUrl(), PHP_URL_SCHEME));
        Assert::same(implode('.', ['api', 'anthropic', 'com']), parse_url($config->baseUrl(), PHP_URL_HOST));
        Assert::null(parse_url($config->baseUrl(), PHP_URL_PATH));
        Assert::same('claude-sonnet-5-5', $config->model());
        Assert::same('low', $config->effort());
        Assert::same(8000, $config->maxOutputTokens());
        Assert::same(30, $config->timeoutSeconds());
        Assert::same(5, $config->connectTimeoutSeconds());
        Assert::true($config->fallbacksEnabled());
        Assert::same('ok', $config->stubBehavior());
        Assert::same('development', $config->appEnv());
    }

    public function testEmptyStringsFallBackToDefaults(): void
    {
        $config = AiConfig::fromArray(['ANTHROPIC_API_KEY' => '', 'AI_MODEL' => '', 'AI_TIMEOUT_SECONDS' => '']);

        Assert::null($config->apiKey());
        Assert::same('claude-sonnet-5-5', $config->model());
        Assert::same(30, $config->timeoutSeconds());
    }

    public function testReadsExplicitValues(): void
    {
        $config = AiConfig::fromArray([
            'AI_PROVIDER' => 'stub',
            'ANTHROPIC_API_KEY' => self::FAKE_KEY,
            'ANTHROPIC_BASE_URL' => 'https://example.invalid/',
            'AI_MODEL' => 'claude-sonnet-5',
            'AI_EFFORT' => 'medium',
            'AI_MAX_OUTPUT_TOKENS' => '256',
            'AI_TIMEOUT_SECONDS' => '60',
            'AI_CONNECT_TIMEOUT_SECONDS' => '15',
            'AI_FALLBACKS' => 'off',
            'AI_STUB_BEHAVIOR' => 'timeout',
            'APP_ENV' => 'testing',
        ]);

        Assert::same('stub', $config->provider());
        Assert::same(self::FAKE_KEY, $config->apiKey());
        Assert::same('https://example.invalid', $config->baseUrl());
        Assert::same('claude-sonnet-5', $config->model());
        Assert::same('medium', $config->effort());
        Assert::same(256, $config->maxOutputTokens());
        Assert::same(60, $config->timeoutSeconds());
        Assert::same(15, $config->connectTimeoutSeconds());
        Assert::false($config->fallbacksEnabled());
        Assert::same('timeout', $config->stubBehavior());
        Assert::same('testing', $config->appEnv());
    }

    public function testInvalidValuesNameTheVariableWithoutTheValue(): void
    {
        $cases = [
            'AI_PROVIDER' => 'openai-zz9',
            'AI_EFFORT' => 'turbo-zz9',
            'AI_MAX_OUTPUT_TOKENS' => '255',
            'AI_TIMEOUT_SECONDS' => '61',
            'AI_CONNECT_TIMEOUT_SECONDS' => '0',
            'AI_FALLBACKS' => 'maybe-zz9',
            'AI_STUB_BEHAVIOR' => 'crash-zz9',
        ];

        foreach ($cases as $name => $value) {
            $message = $this->exceptionMessage(static fn () => AiConfig::fromArray([$name => $value]));

            Assert::stringContains($name, $message, "Message must name {$name}");
            Assert::false(str_contains($message, $value), "Message must not leak the value of {$name}");
        }

        $message = $this->exceptionMessage(static fn () => AiConfig::fromArray(['AI_MAX_OUTPUT_TOKENS' => '32001']));
        Assert::stringContains('AI_MAX_OUTPUT_TOKENS', $message);

        $message = $this->exceptionMessage(static fn () => AiConfig::fromArray(['AI_TIMEOUT_SECONDS' => 'abc']));
        Assert::stringContains('AI_TIMEOUT_SECONDS', $message);
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $low = AiConfig::fromArray(['AI_MAX_OUTPUT_TOKENS' => '256', 'AI_TIMEOUT_SECONDS' => '5', 'AI_CONNECT_TIMEOUT_SECONDS' => '1']);
        $high = AiConfig::fromArray(['AI_MAX_OUTPUT_TOKENS' => '32000', 'AI_TIMEOUT_SECONDS' => '60', 'AI_CONNECT_TIMEOUT_SECONDS' => '15']);

        Assert::same(256, $low->maxOutputTokens());
        Assert::same(5, $low->timeoutSeconds());
        Assert::same(1, $low->connectTimeoutSeconds());
        Assert::same(32000, $high->maxOutputTokens());
        Assert::same(60, $high->timeoutSeconds());
        Assert::same(15, $high->connectTimeoutSeconds());

        foreach (['low', 'medium', 'high', 'xhigh', 'max'] as $effort) {
            Assert::same($effort, AiConfig::fromArray(['AI_EFFORT' => $effort])->effort());
        }
    }

    public function testStubProviderIsRefusedInProduction(): void
    {
        $message = $this->exceptionMessage(
            static fn () => AiConfig::fromArray(['AI_PROVIDER' => 'stub', 'APP_ENV' => 'production'])
        );

        Assert::same('AI stub provider is not allowed in production', $message);
        Assert::same('anthropic', AiConfig::fromArray(['APP_ENV' => 'production'])->provider());
    }

    public function testApiKeyIsMaskedInDebugOutput(): void
    {
        $config = AiConfig::fromArray(['ANTHROPIC_API_KEY' => self::FAKE_KEY]);

        $printed = print_r($config, true);
        $dumped = $this->capture(static fn () => var_dump($config));

        Assert::false(str_contains($printed, self::FAKE_KEY), 'print_r must not leak the key');
        Assert::false(str_contains($dumped, self::FAKE_KEY), 'var_dump must not leak the key');
        Assert::stringContains('*****', $printed);
        Assert::same('*****', $config->__debugInfo()['apiKey']);
        Assert::same('*****', $config->toArray()['apiKey']);
        Assert::false(str_contains(json_encode($config->toArray()), self::FAKE_KEY));
    }

    public function testMissingKeyIsNotMaskedAsPresent(): void
    {
        Assert::null(AiConfig::fromArray([])->toArray()['apiKey']);
    }

    public function testJsonLoggerMasksAnthropicKeyHeaders(): void
    {
        $file = $this->tempFile();
        $logger = new JsonLogger('centralvet-test', 'testing', $file, 'debug');

        $logger->info('ai.request', [
            'headers' => ['x-api-key' => self::FAKE_KEY, 'anthropic-version' => '2023-06-01'],
            'ANTHROPIC_API_KEY' => self::FAKE_KEY,
        ]);

        $contents = (string) file_get_contents($file);
        $decoded = json_decode(trim($contents), true);

        Assert::stringContains('"x-api-key":"*****"', $contents);
        Assert::same('*****', $decoded['context']['ANTHROPIC_API_KEY']);
        Assert::same('2023-06-01', $decoded['context']['headers']['anthropic-version']);
        Assert::false(str_contains($contents, self::FAKE_KEY));
    }

    public function testAuditRedactorMasksAnthropicKeys(): void
    {
        $result = AuditRedactor::redact([
            'anthropic_api_key' => self::FAKE_KEY,
            'request' => ['X-Api-Key' => self::FAKE_KEY],
            'model' => 'claude-sonnet-5-5',
        ]);

        Assert::same('*****', $result['anthropic_api_key']);
        Assert::same('*****', $result['request']['X-Api-Key']);
        Assert::same('claude-sonnet-5-5', $result['model']);
    }

    private function exceptionMessage(callable $callback): string
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        throw new \RuntimeException('Expected InvalidArgumentException was not thrown');
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    private function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cv-ai-config-');
        $this->tempFiles[] = $file;

        return $file;
    }
}

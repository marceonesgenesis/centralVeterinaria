<?php

declare(strict_types=1);

namespace CentralVet\Ai;

use Closure;
use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiModelPricing;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Exception\AiProviderException;

/**
 * Adapter for the Anthropic Messages API (POST /v1/messages, no streaming)
 * over curl. One call per request, short timeouts and no retry: every
 * failure becomes an AiProviderException carrying only a reason code, the
 * HTTP status and, after a 200, the usage, model and per-model cost.
 *
 * Nothing here is logged: neither headers, request/response bodies nor the
 * API key.
 *
 * The transport is injectable for tests:
 * Closure(string $url, array<string, string> $headers, string $body,
 *         int $timeoutSeconds, int $connectTimeoutSeconds):
 *     array{status: int, body: string, request_id: ?string, error: ?string}
 * where `error` is null, 'timeout' or 'connect'.
 */
final class AnthropicMessagesGateway implements AiGatewayInterface
{
    public const API_VERSION = '2023-06-01';
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    public const PROVIDER = 'anthropic';

    private readonly Closure $transport;

    public function __construct(
        private readonly AiConfig $config,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public function isConfigured(): bool
    {
        return $this->config->apiKey() !== null;
    }

    public function providerName(): string
    {
        return self::PROVIDER;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $apiKey = $this->config->apiKey();

        if ($apiKey === null) {
            throw new AiProviderException('ai_not_configured');
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ];
        $payload = [
            'model' => $this->config->model(),
            'max_tokens' => $this->config->maxOutputTokens(),
            'system' => [['type' => 'text', 'text' => $request->system]],
            'messages' => [['role' => 'user', 'content' => $request->userContent]],
            'output_config' => ['effort' => $this->config->effort()],
        ];

        if ($this->config->fallbacksEnabled()) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
            $payload['fallbacks'] = 'default';
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            throw new AiProviderException('ai_provider_error');
        }

        $response = ($this->transport)(
            $this->config->baseUrl() . '/v1/messages',
            $headers,
            $body,
            $this->config->timeoutSeconds(),
            $this->config->connectTimeoutSeconds(),
        );

        return $this->parse($response);
    }

    /**
     * @param array{status?: int, body?: string, request_id?: ?string, error?: ?string} $response
     */
    private function parse(array $response): AiCompletionResult
    {
        $error = $response['error'] ?? null;

        if ($error !== null) {
            throw new AiProviderException($error === 'timeout' ? 'ai_timeout' : 'ai_provider_error');
        }

        $status = (int) ($response['status'] ?? 0);

        if ($status !== 200) {
            throw new AiProviderException(self::reasonForStatus($status), $status > 0 ? $status : null);
        }

        $data = json_decode((string) ($response['body'] ?? ''), true);

        if (!is_array($data)) {
            throw new AiProviderException('ai_provider_error', $status);
        }

        $model = is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $this->config->model();
        [$usage, $cost] = $this->usageAndCost($data['usage'] ?? null, $model);
        $stopReason = is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : '';

        if ($stopReason === 'refusal') {
            throw new AiProviderException('ai_refused', $status, $usage, $model, $cost);
        }

        if ($stopReason === 'max_tokens') {
            throw new AiProviderException('ai_truncated', $status, $usage, $model, $cost);
        }

        $text = self::text($data['content'] ?? null);

        if (!in_array($stopReason, ['end_turn', 'stop_sequence'], true) || trim($text) === '') {
            throw new AiProviderException('ai_provider_error', $status, $usage, $model, $cost);
        }

        $requestId = $response['request_id'] ?? null;

        return new AiCompletionResult(
            $text,
            self::PROVIDER,
            $model,
            $usage,
            $cost,
            $stopReason,
            is_string($requestId) && $requestId !== '' ? $requestId : null,
        );
    }

    private static function reasonForStatus(int $status): string
    {
        return match ($status) {
            401, 403 => 'ai_auth_failed',
            429 => 'ai_rate_limited',
            529 => 'ai_overloaded',
            default => 'ai_provider_error',
        };
    }

    /**
     * With server-side fallbacks the top-level `usage` covers only the final
     * attempt; `usage.iterations` lists every attempt (the per-attempt source
     * of truth for billing). When present, tokens are summed over the
     * iterations and each one is priced at its own model: an explicit
     * `model` on the entry wins; otherwise a `fallback_message` entry is
     * priced at the answering (top-level) model and any other entry at the
     * requested model. Cost is null when any attempt has an unknown price.
     *
     * @return array{0: AiUsage, 1: ?int}
     */
    private function usageAndCost(mixed $usage, string $responseModel): array
    {
        if (!is_array($usage)) {
            return [AiUsage::none(), AiModelPricing::costMicroUsd($responseModel, AiUsage::none())];
        }

        $iterations = $usage['iterations'] ?? null;

        if (!is_array($iterations) || $iterations === []) {
            $total = self::usage($usage);

            return [$total, AiModelPricing::costMicroUsd($responseModel, $total)];
        }

        $input = $output = $cacheRead = $cacheWrite = 0;
        $cost = 0;

        foreach ($iterations as $iteration) {
            if (!is_array($iteration)) {
                continue;
            }

            $attempt = self::usage($iteration);
            $input += $attempt->inputTokens;
            $output += $attempt->outputTokens;
            $cacheRead += $attempt->cacheReadTokens;
            $cacheWrite += $attempt->cacheWriteTokens;

            if (is_string($iteration['model'] ?? null) && $iteration['model'] !== '') {
                $attemptModel = $iteration['model'];
            } elseif (($iteration['type'] ?? null) === 'fallback_message') {
                $attemptModel = $responseModel;
            } else {
                $attemptModel = $this->config->model();
            }

            $attemptCost = AiModelPricing::costMicroUsd($attemptModel, $attempt);
            $cost = ($cost === null || $attemptCost === null) ? null : $cost + $attemptCost;
        }

        return [new AiUsage($input, $output, $cacheRead, $cacheWrite), $cost];
    }

    /** @param array<string, mixed> $usage */
    private static function usage(array $usage): AiUsage
    {
        $count = static fn (string $key): int => max(0, (int) ($usage[$key] ?? 0));

        return new AiUsage(
            $count('input_tokens'),
            $count('output_tokens'),
            $count('cache_read_input_tokens'),
            $count('cache_creation_input_tokens'),
        );
    }

    /** Concatenates only the `text` blocks (thinking blocks are ignored). */
    private static function text(mixed $content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $text = '';

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Default transport (same curl pattern as S3Client). Captures only the
     * `request-id` response header.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string, request_id: ?string, error: ?string}
     */
    private static function curlTransport(
        string $url,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] string $body,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
    ): array {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $requestId = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$requestId): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'request-id') {
                    $requestId = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $errno = curl_errno($ch);
            curl_close($ch);

            return [
                'status' => 0,
                'body' => '',
                'request_id' => null,
                'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'connect',
            ];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $raw, 'request_id' => $requestId, 'error' => null];
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Ai;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiModelPricing;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Exception\AiProviderException;

/**
 * Network-free gateway for gates and local development (AI_PROVIDER=stub,
 * refused in production by AiConfig). Echoes a deterministic text with no
 * usage nor cost; behavior `timeout` simulates a provider timeout.
 *
 * Tests of the tool-use loop use `scripted()`: the given steps
 * (AiCompletionResult or AiProviderException) are consumed in order and a
 * call beyond them fails with `ai_provider_error`. Every request received is
 * kept in `requests()` (with its `toolChoice`). Without a script and with
 * `messages`, the echo uses the text of the last `user` message. Like the
 * Messages API, `tool_use`/`tool_result` blocks without `tools` are refused
 * (HTTP 400 → `ai_provider_error`) before any script step is consumed.
 */
final class StubAiGateway implements AiGatewayInterface
{
    public const PROVIDER = 'stub';
    private const ECHO_LENGTH = 160;

    /** @var list<AiCompletionResult|AiProviderException>|null */
    private ?array $script = null;

    /** @var list<AiCompletionRequest> */
    private array $requests = [];

    public function __construct(private readonly string $behavior = 'ok')
    {
        if (!in_array($behavior, AiConfig::STUB_BEHAVIORS, true)) {
            throw new \InvalidArgumentException('Unknown stub behavior');
        }
    }

    /** @param list<AiCompletionResult|AiProviderException> $steps */
    public static function scripted(array $steps): self
    {
        foreach ($steps as $step) {
            if (!$step instanceof AiCompletionResult && !$step instanceof AiProviderException) {
                throw new \InvalidArgumentException('Scripted step must be a result or a provider exception');
            }
        }

        $stub = new self();
        $stub->script = array_values($steps);

        return $stub;
    }

    /** @return list<AiCompletionRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $this->requests[] = $request;

        if ($request->tools === [] && self::hasToolBlocks($request->messages)) {
            throw new AiProviderException('ai_provider_error', 400);
        }

        if ($this->script !== null) {
            $step = array_shift($this->script);

            if ($step === null) {
                throw new AiProviderException('ai_provider_error');
            }

            if ($step instanceof AiProviderException) {
                throw $step;
            }

            return $step;
        }

        if ($this->behavior === 'timeout') {
            throw new AiProviderException('ai_timeout');
        }

        $usage = AiUsage::none();

        return new AiCompletionResult(
            sprintf('[stub] %s v%s: %s', $request->purpose, $request->promptVersion, mb_substr(self::echoSource($request), 0, self::ECHO_LENGTH)),
            self::PROVIDER,
            self::PROVIDER,
            $usage,
            AiModelPricing::costMicroUsd(self::PROVIDER, $usage),
            'end_turn',
            null,
        );
    }

    /** @param list<array<string, mixed>> $messages */
    private static function hasToolBlocks(array $messages): bool
    {
        foreach ($messages as $message) {
            if (!is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if (is_array($block) && in_array($block['type'] ?? null, ['tool_use', 'tool_result'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** `userContent`, or the text of the last `user` message when there are messages. */
    private static function echoSource(AiCompletionRequest $request): string
    {
        if ($request->messages === []) {
            return $request->userContent;
        }

        foreach (array_reverse($request->messages) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? '';

            if (is_string($content)) {
                return $content;
            }

            $text = '';

            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $text .= $block['text'];
                }
            }

            return $text;
        }

        return '';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return self::PROVIDER;
    }
}

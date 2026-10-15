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
 */
final class StubAiGateway implements AiGatewayInterface
{
    public const PROVIDER = 'stub';
    private const ECHO_LENGTH = 160;

    public function __construct(private readonly string $behavior = 'ok')
    {
        if (!in_array($behavior, AiConfig::STUB_BEHAVIORS, true)) {
            throw new \InvalidArgumentException('Unknown stub behavior');
        }
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        if ($this->behavior === 'timeout') {
            throw new AiProviderException('ai_timeout');
        }

        $usage = AiUsage::none();

        return new AiCompletionResult(
            sprintf('[stub] %s v%s: %s', $request->purpose, $request->promptVersion, mb_substr($request->userContent, 0, self::ECHO_LENGTH)),
            self::PROVIDER,
            self::PROVIDER,
            $usage,
            AiModelPricing::costMicroUsd(self::PROVIDER, $usage),
            'end_turn',
            null,
        );
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

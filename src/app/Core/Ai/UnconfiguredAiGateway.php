<?php

declare(strict_types=1);

namespace CentralVet\Ai;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Exception\AiProviderException;

/** Gateway used when the provider has no API key: the screen degrades. */
final class UnconfiguredAiGateway implements AiGatewayInterface
{
    public function __construct()
    {
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        throw new AiProviderException('ai_not_configured');
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function providerName(): string
    {
        return 'none';
    }
}

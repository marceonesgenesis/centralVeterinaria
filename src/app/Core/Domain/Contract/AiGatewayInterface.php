<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\Exception\AiProviderException;

/**
 * Provider-independent AI gateway (Fase 8A). Adapters: Anthropic Messages
 * (curl), stub, not configured. Implementations never log the request,
 * the response text nor the API key.
 */
interface AiGatewayInterface
{
    /** @throws AiProviderException on any provider failure (reason code only). */
    public function complete(AiCompletionRequest $request): AiCompletionResult;

    public function isConfigured(): bool;

    public function providerName(): string;
}

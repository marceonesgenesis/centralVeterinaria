<?php

declare(strict_types=1);

namespace CentralVet\Ai;

use CentralVet\Domain\Contract\AiGatewayInterface;

/** Picks the gateway for the configured provider. */
final class AiGatewayFactory
{
    private function __construct()
    {
    }

    public static function fromConfig(AiConfig $config): AiGatewayInterface
    {
        if ($config->provider() === 'stub') {
            return new StubAiGateway($config->stubBehavior());
        }

        if ($config->apiKey() === null) {
            return new UnconfiguredAiGateway();
        }

        return new AnthropicMessagesGateway($config);
    }
}

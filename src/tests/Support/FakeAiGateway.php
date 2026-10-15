<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\AiCompletionRequest;
use CentralVet\Domain\AiCompletionResult;
use CentralVet\Domain\AiUsage;
use CentralVet\Domain\Contract\AiGatewayInterface;
use CentralVet\Domain\Exception\AiProviderException;

/**
 * In-memory double for AiGatewayInterface (T-05). Never opens the network:
 * records every request and returns `$result` (default: `Resumo de teste`,
 * provider `fake`, model `claude-sonnet-5-5`, usage 100/50, cost 700,
 * `end_turn`) or throws `$failure` when one is given.
 */
final class FakeAiGateway implements AiGatewayInterface
{
    /** @var list<AiCompletionRequest> */
    private array $calls = [];

    public function __construct(
        private readonly ?AiCompletionResult $result = null,
        private readonly ?AiProviderException $failure = null,
        private readonly bool $configured = true,
    ) {
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $this->calls[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result ?? new AiCompletionResult('Resumo de teste', 'fake', 'claude-sonnet-5-5', new AiUsage(100, 50), 700, 'end_turn', 'req_fake');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function providerName(): string
    {
        return 'fake';
    }

    /** @return list<AiCompletionRequest> every request received, in order. */
    public function calls(): array
    {
        return $this->calls;
    }
}

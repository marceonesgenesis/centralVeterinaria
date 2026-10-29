<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Domain\CommunicationChannel;

/**
 * Double for an e-mail MessageChannelProviderInterface (T-06): deliver()
 * records the message and returns a provider message id; after
 * failWith($code) every deliver() throws MessageDeliveryFailed($code)
 * without recording, until succeed() is called.
 */
final class FakeEmailProvider implements MessageChannelProviderInterface
{
    /** @var list<OutgoingMessage> */
    private array $deliveries = [];
    private ?string $errorCode = null;

    public function __construct(private readonly string $name = 'fake')
    {
    }

    public function channel(): string
    {
        return CommunicationChannel::EMAIL;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function deliver(OutgoingMessage $message): ?string
    {
        if ($this->errorCode !== null) {
            throw new MessageDeliveryFailed($this->errorCode);
        }

        $this->deliveries[] = $message;

        return 'fake-' . count($this->deliveries);
    }

    public function failWith(string $errorCode): void
    {
        $this->errorCode = $errorCode;
    }

    public function succeed(): void
    {
        $this->errorCode = null;
    }

    /** @return list<OutgoingMessage> */
    public function deliveries(): array
    {
        return $this->deliveries;
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Billing;

/**
 * Resultado de BillingEventProcessor::process (T-14): `processed` quando o
 * evento mudou algo, `ignored` quando não havia o que fazer (tipo não
 * tratado, evento repetido ou antigo). orderToFulfill() traz o pedido que
 * acabou de ser pago e precisa de provisionamento (T-13).
 */
final class BillingEventOutcome
{
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';

    private function __construct(
        private readonly string $status,
        private readonly ?int $orderToFulfill,
    ) {
    }

    public static function processed(?int $orderToFulfill = null): self
    {
        return new self(self::PROCESSED, $orderToFulfill);
    }

    public static function ignored(): self
    {
        return new self(self::IGNORED, null);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function orderToFulfill(): ?int
    {
        return $this->orderToFulfill;
    }
}

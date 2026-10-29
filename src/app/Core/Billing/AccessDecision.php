<?php

declare(strict_types=1);

namespace CentralVet\Billing;

/**
 * Resultado de SubscriptionAccessGuard::decide(): permitido ou não, o motivo
 * e a chave de mensagem (T-01, traduzida por _t()) dos bloqueios.
 */
final class AccessDecision
{
    public const REASON_LEGACY = 'legacy';
    public const REASON_FULL = 'full';
    public const REASON_GRACE = 'grace';
    public const REASON_BLOCKED_STATUS = 'blocked_status';
    public const REASON_BLOCKED_PLAN = 'blocked_plan';
    public const REASON_NOT_RELEASED = 'not_released';

    private const MESSAGE_KEYS = [
        self::REASON_BLOCKED_STATUS => 'Your subscription is suspended. Regularize the payment to continue',
        self::REASON_BLOCKED_PLAN => 'Your plan does not include this feature',
        self::REASON_NOT_RELEASED => 'This module is not available in the pilot yet',
    ];

    private function __construct(private readonly bool $allowed, private readonly string $reason)
    {
    }

    public static function legacy(): self
    {
        return new self(true, self::REASON_LEGACY);
    }

    public static function full(): self
    {
        return new self(true, self::REASON_FULL);
    }

    public static function grace(): self
    {
        return new self(true, self::REASON_GRACE);
    }

    public static function blockedStatus(): self
    {
        return new self(false, self::REASON_BLOCKED_STATUS);
    }

    public static function blockedPlan(): self
    {
        return new self(false, self::REASON_BLOCKED_PLAN);
    }

    /** Plano inclui o controller, mas a feature está fora do recorte do piloto (OfferScope). */
    public static function notReleased(): self
    {
        return new self(false, self::REASON_NOT_RELEASED);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function messageKey(): ?string
    {
        return $this->allowed ? null : self::MESSAGE_KEYS[$this->reason];
    }
}

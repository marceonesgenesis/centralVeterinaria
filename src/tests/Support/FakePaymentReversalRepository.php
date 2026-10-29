<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PaymentReversalRepositoryInterface;
use CentralVet\Domain\PaymentReversal;
use InvalidArgumentException;
use LogicException;

/**
 * In-memory double for PaymentReversalRepositoryInterface (T-08).
 * listByReceivable() needs to know which payment belongs to which
 * receivable, so tests map it with linkPayment() (the real repository joins
 * `payment`). Append-only like the real one; UNIQUE payment_id is enforced.
 */
final class FakePaymentReversalRepository implements PaymentReversalRepositoryInterface
{
    /** @var array<int, PaymentReversal> */
    private array $reversals = [];

    /** @var array<int, int> payment_id => receivable_id */
    private array $receivableByPayment = [];

    private int $nextId = 1;

    public function __construct()
    {
    }

    public function linkPayment(int $paymentId, int $receivableId): void
    {
        $this->receivableByPayment[$paymentId] = $receivableId;
    }

    public function findById(int|string $id): ?object
    {
        return $this->reversals[(int) $id] ?? null;
    }

    public function existsForPayment(int $paymentId): bool
    {
        foreach ($this->reversals as $reversal) {
            if ($reversal->paymentId() === $paymentId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<PaymentReversal> */
    public function listByReceivable(int $receivableId): array
    {
        return array_values(array_filter(
            $this->reversals,
            fn (PaymentReversal $reversal): bool => ($this->receivableByPayment[$reversal->paymentId()] ?? null) === $receivableId,
        ));
    }

    /** @return list<PaymentReversal> */
    public function all(): array
    {
        return array_values($this->reversals);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof PaymentReversal) {
            throw new InvalidArgumentException('FakePaymentReversalRepository only stores PaymentReversal entities');
        }

        if ($entity->id() !== null) {
            throw new InvalidArgumentException('PaymentReversal is append-only and cannot be updated');
        }

        if ($this->existsForPayment($entity->paymentId())) {
            throw new LogicException('Duplicate payment_reversal_payment_uq for payment ' . $entity->paymentId());
        }

        $entity->assignId($this->nextId++);
        $this->reversals[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        throw new LogicException('PaymentReversal is append-only and cannot be removed');
    }
}

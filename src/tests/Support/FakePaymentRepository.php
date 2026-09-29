<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\PaymentRepositoryInterface;
use CentralVet\Domain\Payment;
use InvalidArgumentException;

/**
 * In-memory double for PaymentRepositoryInterface (T-13): the `payment`
 * table does not exist yet (migration T-01 not applied), so
 * PaymentServiceTest exercises PaymentService::register() against this
 * instead of a real database. Tenant-scoped like the real
 * PaymentRepository (ADR 0002): findById()/listByReceivable()/
 * listByCashSession() only ever return payments whose tenantId() matches
 * this instance's own $tenantId.
 */
final class FakePaymentRepository implements PaymentRepositoryInterface
{
    /** @var array<int, Payment> */
    private array $payments = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, Payment ...$seed)
    {
        foreach ($seed as $payment) {
            $this->save($payment);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $payment = $this->payments[(int) $id] ?? null;

        if ($payment === null || $payment->tenantId() !== $this->tenantId) {
            return null;
        }

        return $payment;
    }

    /** @return list<Payment> */
    public function listByReceivable(int $receivableId): array
    {
        return array_values(array_filter(
            $this->payments,
            fn (Payment $payment): bool => $payment->tenantId() === $this->tenantId
                && $payment->receivableId() === $receivableId,
        ));
    }

    /** @return list<Payment> */
    public function listByCashSession(int $cashSessionId): array
    {
        return array_values(array_filter(
            $this->payments,
            fn (Payment $payment): bool => $payment->tenantId() === $this->tenantId
                && $payment->cashSessionId() === $cashSessionId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof Payment) {
            throw new InvalidArgumentException('FakePaymentRepository only stores Payment entities');
        }

        if ($entity->id() === null) {
            $entity->assignId($this->nextId++);
        }

        $this->payments[$entity->id()] = $entity;

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof Payment && $entity->id() !== null) {
            unset($this->payments[$entity->id()]);
        }
    }
}

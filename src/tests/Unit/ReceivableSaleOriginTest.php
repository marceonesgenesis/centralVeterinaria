<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Exception\InvalidStatusTransitionException;
use CentralVet\Domain\Receivable;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeReceivableRepository;
use InvalidArgumentException;

/**
 * Etapa 2, T-08: a receivable now comes either from a closed encounter
 * account or from a sale (receivable_origin_ck), can be cancelled keeping
 * what was already paid, and refuses payments once cancelled.
 */
final class ReceivableSaleOriginTest
{
    private const TENANT_ID = 7;

    public function testOpenForSaleHasSaleOriginAndNoEncounterAccount(): void
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 41, 9, 15000);

        Assert::null($receivable->encounterAccountId());
        Assert::same(41, $receivable->saleId());
        Assert::same(9, $receivable->tutorId());
        Assert::same(15000, $receivable->totalCents());
        Assert::same(0, $receivable->paidCents());
        Assert::same(Receivable::STATUS_OPEN, $receivable->status());
        Assert::false($receivable->isCancelled());
    }

    public function testOpenForSaleWithZeroTotalIsAlreadyPaid(): void
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 41, 9, 0);

        Assert::same(Receivable::STATUS_PAID, $receivable->status());
        Assert::same(0, $receivable->paidCents());
    }

    public function testOpenForSaleRejectsInvalidIds(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => Receivable::openForSale(self::TENANT_ID, 0, 9, 100));
        Assert::throws(InvalidArgumentException::class, static fn () => Receivable::openForSale(self::TENANT_ID, 41, 0, 100));
        Assert::throws(InvalidArgumentException::class, static fn () => Receivable::openForSale(self::TENANT_ID, 41, 9, -1));
    }

    public function testEncounterReceivableHasNoSaleId(): void
    {
        $receivable = Receivable::open(self::TENANT_ID, 13, 9, 5000);

        Assert::same(13, $receivable->encounterAccountId());
        Assert::null($receivable->saleId());
    }

    public function testReconstituteReadsNullOrigins(): void
    {
        $fromSale = Receivable::reconstitute([
            'id' => 3, 'tenant_id' => self::TENANT_ID, 'encounter_account_id' => null, 'sale_id' => 41,
            'tutor_id' => 9, 'total_cents' => 100, 'paid_cents' => 0, 'status' => 'open',
        ]);
        Assert::null($fromSale->encounterAccountId());
        Assert::same(41, $fromSale->saleId());

        $fromEncounter = Receivable::reconstitute([
            'id' => 4, 'tenant_id' => self::TENANT_ID, 'encounter_account_id' => 13, 'sale_id' => null,
            'tutor_id' => 9, 'total_cents' => 100, 'paid_cents' => 0, 'status' => 'open',
        ]);
        Assert::same(13, $fromEncounter->encounterAccountId());
        Assert::null($fromEncounter->saleId());
    }

    public function testCancelKeepsPaidCentsAndIsIdempotent(): void
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 41, 9, 10000);
        $receivable->recordPayment(4000);

        $receivable->cancel();

        Assert::true($receivable->isCancelled());
        Assert::same(Receivable::STATUS_CANCELLED, $receivable->status());
        Assert::same(4000, $receivable->paidCents());

        $receivable->cancel();

        Assert::same(Receivable::STATUS_CANCELLED, $receivable->status());
        Assert::same(4000, $receivable->paidCents());
    }

    public function testRecordPaymentOnCancelledReceivableIsRefusedWithoutChanges(): void
    {
        $receivable = Receivable::openForSale(self::TENANT_ID, 41, 9, 10000);
        $receivable->recordPayment(2500);
        $receivable->cancel();

        $message = null;

        try {
            $receivable->recordPayment(1000);
        } catch (InvalidStatusTransitionException $e) {
            $message = $e->getMessage();
        }

        Assert::same('This receivable is cancelled and cannot receive payments', $message);
        Assert::same(2500, $receivable->paidCents());
        Assert::same(Receivable::STATUS_CANCELLED, $receivable->status());
    }

    public function testFakeRepositoryFindsBySaleIdAndLocksInsideNoTransaction(): void
    {
        $repository = new FakeReceivableRepository(self::TENANT_ID);
        $receivable = Receivable::openForSale(self::TENANT_ID, 41, 9, 10000);
        $repository->save($receivable);

        Assert::same($receivable, $repository->findBySaleId(41));
        Assert::null($repository->findBySaleId(42));
        Assert::same($receivable, $repository->findByIdForUpdate((int) $receivable->id()));
        Assert::null($repository->findByIdForUpdate(999));
    }
}

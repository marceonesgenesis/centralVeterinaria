<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSubscriptionInvoiceRepository;
use CentralVet\Tests\Support\FakeSubscriptionRepository;

/**
 * Os fakes de T-10 seguem o contrato dos repositórios reais: *ForUpdate fora
 * de transação lança LogicException, e limit <= 0 é recusado.
 */
final class SubscriptionFakesTest
{
    public function testSubscriptionFakeRefusesForUpdateOutsideTransaction(): void
    {
        $fake = new FakeSubscriptionRepository();
        $fake->inTransaction = false;

        Assert::throws(\LogicException::class, static fn () => $fake->findByIdForUpdate(1));
        Assert::throws(\LogicException::class, static fn () => $fake->findByStripeSubscriptionIdForUpdate('sub_x'));
        Assert::same([], $fake->lockCalls, 'A refused call must not be recorded as a lock');

        $fake->inTransaction = true;
        Assert::null($fake->findByIdForUpdate(1));
        Assert::same(['findByIdForUpdate'], $fake->lockCalls);
    }

    public function testInvoiceFakeRefusesForUpdateOutsideTransaction(): void
    {
        $fake = new FakeSubscriptionInvoiceRepository();
        $fake->inTransaction = false;

        Assert::throws(\LogicException::class, static fn () => $fake->findByIdForUpdate(1));
        Assert::throws(\LogicException::class, static fn () => $fake->findByStripeInvoiceIdForUpdate('in_x'));
        Assert::throws(\LogicException::class, static fn () => $fake->findByPaymentIntentIdForUpdate('pi_x'));
        Assert::same([], $fake->lockCalls);

        $fake->inTransaction = true;
        Assert::null($fake->findByPaymentIntentIdForUpdate('pi_x'));
        Assert::same(['findByPaymentIntentIdForUpdate'], $fake->lockCalls);
    }

    public function testFakesRefuseNonPositiveLimit(): void
    {
        $invoices = new FakeSubscriptionInvoiceRepository();
        $subscriptions = new FakeSubscriptionRepository($invoices);
        $now = new \DateTimeImmutable('2031-01-01 00:00:00');

        Assert::throws(\InvalidArgumentException::class, static fn () => $subscriptions->listGraceExpired($now, 0));
        Assert::throws(\InvalidArgumentException::class, static fn () => $subscriptions->listPixDueForIssue($now, 0));
        Assert::throws(\InvalidArgumentException::class, static fn () => $invoices->listOpenPix($now, 0));
        Assert::throws(\InvalidArgumentException::class, static fn () => $invoices->listBySubscription(1, 0));
    }
}

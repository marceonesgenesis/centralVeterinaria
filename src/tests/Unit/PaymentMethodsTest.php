<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\FinancialEntry;
use CentralVet\Domain\Payment;
use CentralVet\Tests\Support\Assert;

final class PaymentMethodsTest
{
    public function testPaymentMethodsAreTheSingleSource(): void
    {
        Assert::same(
            [
                Payment::METHOD_CASH,
                Payment::METHOD_DEBIT_CARD,
                Payment::METHOD_CREDIT_CARD,
                Payment::METHOD_PIX,
                Payment::METHOD_BANK_TRANSFER,
            ],
            Payment::METHODS
        );
        Assert::same(Payment::METHODS, FinancialEntry::PAYMENT_METHODS);
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\Exception\DuplicateSubmissionException;
use CentralVet\Domain\SubmissionToken;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeSubmissionKeyRepository;
use InvalidArgumentException;

/**
 * F2 idempotência (Etapa 2, T-07): formato do token de envio, a exceção de
 * envio repetido e o fake em memória usado pelos testes dos casos de uso.
 */
final class SubmissionTokenTest
{
    public function testScopesAreTheMigrationValues(): void
    {
        Assert::same('sale', SubmissionToken::SCOPE_SALE);
        Assert::same('payment', SubmissionToken::SCOPE_PAYMENT);
    }

    public function testGenerateProducesThirtyTwoLowercaseHexCharacters(): void
    {
        $first = SubmissionToken::generate();
        $second = SubmissionToken::generate();

        Assert::same(1, preg_match('/\A[0-9a-f]{32}\z/', $first), "Unexpected token format: {$first}");
        Assert::true($first !== $second, 'Two generated tokens must differ');
        Assert::same($first, SubmissionToken::assertValid($first));
    }

    public function testAssertValidReturnsAWellFormedToken(): void
    {
        $token = str_repeat('0a', 16);

        Assert::same($token, SubmissionToken::assertValid($token));
    }

    public function testAssertValidRejectsEmptyString(): void
    {
        $this->assertRejected('');
    }

    public function testAssertValidRejectsThirtyOneCharacters(): void
    {
        $this->assertRejected(str_repeat('a', 31));
    }

    public function testAssertValidRejectsThirtyThreeCharacters(): void
    {
        $this->assertRejected(str_repeat('a', 33));
    }

    public function testAssertValidRejectsUppercaseHex(): void
    {
        $this->assertRejected(str_repeat('AB', 16));
    }

    public function testAssertValidRejectsTrailingNewline(): void
    {
        $this->assertRejected(str_repeat('ab', 16) . "\n");
    }

    public function testAssertValidRejectsNonHexCharacters(): void
    {
        $this->assertRejected(str_repeat('g', 32));
    }

    public function testDuplicateSubmissionExceptionCarriesScopeAndReference(): void
    {
        $exception = DuplicateSubmissionException::forReference(SubmissionToken::SCOPE_SALE, 42);

        Assert::same('This submission was already processed', $exception->getMessage());
        Assert::same('sale', $exception->scope());
        Assert::same(42, $exception->referenceId());

        $withoutReference = DuplicateSubmissionException::forReference(SubmissionToken::SCOPE_PAYMENT, null);
        Assert::same('payment', $withoutReference->scope());
        Assert::null($withoutReference->referenceId());
    }

    public function testFakeRepositoryClaimsOncePerScopeAndToken(): void
    {
        $repository = new FakeSubmissionKeyRepository();
        $token = SubmissionToken::generate();

        Assert::true($repository->claim(SubmissionToken::SCOPE_SALE, $token, 1));
        Assert::false($repository->claim(SubmissionToken::SCOPE_SALE, $token, 1));
        Assert::true($repository->claim(SubmissionToken::SCOPE_PAYMENT, $token, 1), 'Another scope is another key');

        Assert::null($repository->findReference(SubmissionToken::SCOPE_SALE, $token));
        $repository->attach(SubmissionToken::SCOPE_SALE, $token, 7);
        Assert::same(7, $repository->findReference(SubmissionToken::SCOPE_SALE, $token));
        Assert::null($repository->findReference(SubmissionToken::SCOPE_PAYMENT, $token));
        Assert::null($repository->findReference(SubmissionToken::SCOPE_SALE, SubmissionToken::generate()));
    }

    private function assertRejected(string $token): void
    {
        $caught = null;

        try {
            SubmissionToken::assertValid($token);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'Expected InvalidArgumentException for ' . var_export($token, true));
        Assert::same('Invalid submission token', $caught->getMessage());
    }
}

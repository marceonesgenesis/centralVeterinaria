<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\TutorService;
use CentralVet\Domain\NameText;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeTutorRepository;
use InvalidArgumentException;

/**
 * Rodada 3, T-14: defesa na entrada. Nomes de Patient, Tutor, Service e
 * Product com `<` ou `>` são recusados na criação e na edição.
 */
final class NameTextTest
{
    public function testRejectsTagMarkup(): void
    {
        $this->assertRejected('<img src=x onerror=alert(1)> R3');
    }

    public function testRejectsLoneGreaterThan(): void
    {
        $this->assertRejected('R3 a>b');
    }

    public function testRejectsLoneLessThan(): void
    {
        $this->assertRejected('R3 a<b');
    }

    public function testAcceptsAmpersandAndApostrophe(): void
    {
        NameText::assertNoMarkup('João & Cia');
        NameText::assertNoMarkup('Rex d\'Ávila');

        Assert::same('Name must not contain < or >', NameText::MARKUP_MESSAGE);
    }

    public function testTutorServiceRejectsMarkupWithoutSaving(): void
    {
        $repository = new FakeTutorRepository(1);
        $service = new TutorService($repository, TenantContext::authenticated(1, 1));

        $this->assertRejectedBy(static fn () => $service->create(['full_name' => '<b>R3</b>', 'phone' => '11999990000']));

        Assert::count(0, $repository->search(''));
    }

    private function assertRejected(string $name): void
    {
        $this->assertRejectedBy(static fn () => NameText::assertNoMarkup($name));
    }

    private function assertRejectedBy(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            Assert::same('Name must not contain < or >', $e->getMessage());

            return;
        }

        throw new \RuntimeException('Expected InvalidArgumentException(Name must not contain < or >)');
    }
}

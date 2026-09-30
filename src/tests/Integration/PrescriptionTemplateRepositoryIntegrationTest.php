<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Domain\PrescriptionTemplate;
use CentralVet\Persistence\PrescriptionTemplateRepository;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * PrescriptionTemplateRepository against the real MySQL schema (rodada 2,
 * T-13, migration 0007): header in `prescription_template`, ordered lines in
 * `prescription_template_item`. Every row belongs to throwaway tenants
 * created inside the test's transaction, rolled back in tearDown().
 */
final class PrescriptionTemplateRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private int $userId;
    private int $tenantA;
    private int $tenantB;

    public function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM system_users')->fetchColumn();
        Assert::true($this->userId > 0, 'Fixture requires at least one existing system_users row');

        $this->tenantA = $this->createTenant('r2-rxt-a');
        $this->tenantB = $this->createTenant('r2-rxt-b');
    }

    public function testSaveAndFindByIdRoundTripsHeaderAndOrderedItems(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $template = PrescriptionTemplate::create($this->tenantA, 'Otite', 'Limpar o conduto antes', self::twoItems(), $this->userId);

        $repository->save($template);
        Assert::notNull($template->id());

        /** @var PrescriptionTemplate|null $found */
        $found = $this->repositoryFor($this->tenantA)->findById((int) $template->id());
        Assert::notNull($found);
        Assert::same('Otite', $found->name());
        Assert::same('Limpar o conduto antes', $found->orientationText());
        Assert::same($this->tenantA, $found->tenantId());
        Assert::same($this->userId, $found->createdBySystemUserId());
        Assert::same(self::twoItems(), $found->items());

        $statement = $this->pdo->prepare(
            'SELECT position, tenant_id FROM prescription_template_item WHERE template_id = :id ORDER BY position',
        );
        $statement->execute(['id' => $template->id()]);
        Assert::same(
            [['position' => 1, 'tenant_id' => $this->tenantA], ['position' => 2, 'tenant_id' => $this->tenantA]],
            array_map(
                static fn (array $r): array => ['position' => (int) $r['position'], 'tenant_id' => (int) $r['tenant_id']],
                $statement->fetchAll(\PDO::FETCH_ASSOC),
            ),
        );
    }

    public function testFindByIdAndFindByNameReturnNullForAnotherTenant(): void
    {
        $template = PrescriptionTemplate::create($this->tenantA, 'Otite', null, self::twoItems(), $this->userId);
        $this->repositoryFor($this->tenantA)->save($template);

        $other = $this->repositoryFor($this->tenantB);
        Assert::null($other->findById((int) $template->id()));
        Assert::null($other->findByName('Otite'));
        Assert::same([], $other->listAll());

        Assert::notNull($this->repositoryFor($this->tenantA)->findByName('Otite'));
    }

    public function testListAllIsOrderedByNameAndRemoveDeletesItems(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $otite = PrescriptionTemplate::create($this->tenantA, 'Otite', null, self::twoItems(), $this->userId);
        $dermatite = PrescriptionTemplate::create($this->tenantA, 'Dermatite', null, self::twoItems(), $this->userId);
        $repository->save($otite);
        $repository->save($dermatite);

        Assert::same(
            ['Dermatite', 'Otite'],
            array_map(static fn (PrescriptionTemplate $t): string => $t->name(), $repository->listAll()),
        );

        $repository->remove($otite);

        Assert::null($repository->findById((int) $otite->id()));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM prescription_template_item WHERE template_id = :id');
        $count->execute(['id' => $otite->id()]);
        Assert::same(0, (int) $count->fetchColumn());
    }

    public function testSaveOfDuplicateNameInSameTenantThrowsServiceMessage(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $repository->save(PrescriptionTemplate::create($this->tenantA, 'Otite', null, self::twoItems(), $this->userId));

        // Bypasses PrescriptionTemplateService's findByName() check: the
        // unique key is what keeps two concurrent saves from both landing.
        $duplicate = PrescriptionTemplate::create($this->tenantA, 'Otite', 'Outra', self::twoItems(), $this->userId);

        try {
            $repository->save($duplicate);
        } catch (\InvalidArgumentException $e) {
            Assert::same('A template named "Otite" already exists for this tenant', $e->getMessage());
            Assert::null($duplicate->id(), 'A rejected template must not get an id');
            Assert::count(1, $repository->listAll());

            // Same name in another tenant is still allowed.
            $this->repositoryFor($this->tenantB)->save(
                PrescriptionTemplate::create($this->tenantB, 'Otite', null, self::twoItems(), $this->userId),
            );

            return;
        }

        Assert::true(false, 'Expected InvalidArgumentException for a duplicate template name');
    }

    public function testListAllReturnsTheItemsOfEachTemplate(): void
    {
        $repository = $this->repositoryFor($this->tenantA);
        $one = [self::twoItems()[0]];
        $two = self::twoItems();
        $three = [...self::twoItems(), array_merge(self::twoItems()[0], ['medication_name' => 'Cefalexina'])];

        $repository->save(PrescriptionTemplate::create($this->tenantA, 'A', null, $one, $this->userId));
        $repository->save(PrescriptionTemplate::create($this->tenantA, 'B', null, $two, $this->userId));
        $repository->save(PrescriptionTemplate::create($this->tenantA, 'C', null, $three, $this->userId));
        $this->repositoryFor($this->tenantB)->save(
            PrescriptionTemplate::create($this->tenantB, 'A', null, $three, $this->userId),
        );

        $templates = $repository->listAll();

        Assert::same(['A', 'B', 'C'], array_map(static fn (PrescriptionTemplate $t): string => $t->name(), $templates));
        Assert::same($one, $templates[0]->items());
        Assert::same($two, $templates[1]->items());
        Assert::same($three, $templates[2]->items());
    }

    private function repositoryFor(int $tenantId): PrescriptionTemplateRepository
    {
        return new PrescriptionTemplateRepository(TenantContext::authenticated($tenantId, $this->userId), $this->pdo);
    }

    /** @return list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}> */
    private static function twoItems(): array
    {
        return [
            [
                'medication_name' => 'Otomax',
                'dose' => '4',
                'dose_unit' => 'gotas',
                'route' => 'otológica',
                'frequency' => '12/12h',
                'duration' => '10 dias',
            ],
            [
                'medication_name' => 'Meloxicam',
                'dose' => '0,1',
                'dose_unit' => 'mg/kg',
                'route' => 'oral',
                'frequency' => '24/24h',
                'duration' => '3 dias',
            ],
        ];
    }

    private function createTenant(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :legal_name, :status)',
        );
        $statement->execute(['slug' => $slug, 'legal_name' => 'R2 test tenant (' . $slug . ')', 'status' => 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}

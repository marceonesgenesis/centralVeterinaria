<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Onboarding\ClinicProfileService;
use CentralVet\Onboarding\OnboardingChecklistService;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Checklist de onboarding e dados da clínica (Etapa 5, T-09) contra o
 * centralvet_test com a 0020. Fixture: tenant A e tenant B, cada um com
 * 1 administrador, 1 unidade, nenhum serviço e nenhuma linha em
 * tenant_onboarding. Tudo é desfeito no tearDown().
 */
final class OnboardingChecklistIntegrationTest extends MysqlIntegrationTestCase
{
    private DateTimeImmutable $now;

    /** @var array{tenant: int, unit: int, user: int} */
    private array $a;

    /** @var array{tenant: int, unit: int, user: int} */
    private array $b;

    public function setUp(): void
    {
        parent::setUp();

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'tenant_onboarding'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0020 must be applied to the test database');

        $this->now = new DateTimeImmutable('2031-01-10 12:00:00');
        $this->a = $this->clinic('A');
        $this->b = $this->clinic('B');
    }

    public function testNewTenantHasAllFourItemsPendingInOrder(): void
    {
        $checklist = $this->checklist($this->a);

        Assert::same([
            ['item' => 'clinic_profile', 'done' => false],
            ['item' => 'team', 'done' => false],
            ['item' => 'services', 'done' => false],
            ['item' => 'first_encounter_guide', 'done' => false],
        ], $checklist->status());
        Assert::same(OnboardingChecklistService::ITEMS, array_column($checklist->status(), 'item'));
        Assert::false($checklist->isComplete());
    }

    public function testSaveClinicProfileConfirmsTheItemAndLoadReturnsTheValues(): void
    {
        $profile = $this->profile($this->a);

        $profile->save('  Clínica A Ltda ', ' Clínica A ', ' Matriz ', $this->now);

        Assert::true($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_CLINIC));
        Assert::same(['legal_name' => 'Clínica A Ltda', 'trade_name' => 'Clínica A', 'unit_name' => 'Matriz'], $profile->load());
    }

    public function testBlankTradeNameIsStoredAsNull(): void
    {
        $profile = $this->profile($this->a);

        $profile->save('Clínica A Ltda', '   ', 'Matriz', $this->now);

        Assert::same(['legal_name' => 'Clínica A Ltda', 'trade_name' => null, 'unit_name' => 'Matriz'], $profile->load());
    }

    public function testSaveWithBlankLegalNameOrUnitIsRefusedWithoutWrites(): void
    {
        $profile = $this->profile($this->a);

        $this->expect('Enter the clinic legal name', fn () => $profile->save('  ', 'Clínica A', 'Matriz', $this->now));
        $this->expect('Enter the unit name', fn () => $profile->save('Clínica A Ltda', 'Clínica A', ' ', $this->now));

        Assert::same('Clínica A', $this->value('SELECT legal_name FROM tenant WHERE id = ?', [$this->a['tenant']]));
        Assert::same('Unidade A', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->a['unit']]));
        Assert::false($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_CLINIC));
    }

    public function testServicesNeedAnActiveServiceWithPrice(): void
    {
        $this->service($this->a['tenant'], 0, 1);
        $this->service($this->a['tenant'], 9000, 0);
        Assert::false($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_SERVICES));

        $this->service($this->a['tenant'], 9000, 1);
        Assert::true($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_SERVICES));
    }

    public function testTeamIsDoneWithTwoMembers(): void
    {
        $this->member($this->a['tenant'], 'inactive');
        Assert::false($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_TEAM));

        $this->member($this->a['tenant'], 'invited');
        Assert::true($this->done($this->checklist($this->a), OnboardingChecklistService::ITEM_TEAM));
    }

    public function testMarkTeamReviewedTurnsTeamOnWithOneUser(): void
    {
        $checklist = $this->checklist($this->a);

        $checklist->markTeamReviewed($this->now);

        Assert::true($this->done($checklist, OnboardingChecklistService::ITEM_TEAM));
    }

    public function testAllMarksCompleteTheChecklist(): void
    {
        $checklist = $this->checklist($this->a);
        $this->profile($this->a)->save('Clínica A Ltda', 'Clínica A', 'Matriz', $this->now);
        $this->service($this->a['tenant'], 9000, 1);
        $checklist->markTeamReviewed($this->now);
        Assert::false($checklist->isComplete());

        $checklist->markGuideRead($this->now);
        $checklist->markGuideRead($this->now->modify('+1 minute'));

        Assert::true($this->done($checklist, OnboardingChecklistService::ITEM_GUIDE));
        Assert::true($checklist->isComplete());
        Assert::same(1, (int) $this->value('SELECT COUNT(*) FROM tenant_onboarding WHERE tenant_id = ?', [$this->a['tenant']]));
    }

    public function testTenantBSeesNothingOfAAndItsSaveDoesNotTouchA(): void
    {
        $this->profile($this->a)->save('Clínica A Ltda', 'Clínica A', 'Matriz', $this->now);
        $this->service($this->a['tenant'], 9000, 1);
        $this->member($this->a['tenant'], 'active');
        $checklistA = $this->checklist($this->a);
        $checklistA->markTeamReviewed($this->now);
        $checklistA->markGuideRead($this->now);
        Assert::true($checklistA->isComplete());

        $checklistB = $this->checklist($this->b);
        Assert::same([false, false, false, false], array_column($checklistB->status(), 'done'));

        $this->profile($this->b)->save('Clínica B Ltda', 'Clínica B', 'Sede B', $this->now);

        Assert::same('Clínica A Ltda', $this->value('SELECT legal_name FROM tenant WHERE id = ?', [$this->a['tenant']]));
        Assert::same('Matriz', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->a['unit']]));
        Assert::same('Clínica B Ltda', $this->value('SELECT legal_name FROM tenant WHERE id = ?', [$this->b['tenant']]));
        Assert::same(['legal_name' => 'Clínica A Ltda', 'trade_name' => 'Clínica A', 'unit_name' => 'Matriz'], $this->profile($this->a)->load());
    }

    public function testSaveWithUnitOfAnotherTenantDoesNotRenameIt(): void
    {
        $context = TenantContext::authenticated($this->b['tenant'], $this->b['user'], $this->a['unit']);
        $profile = new ClinicProfileService($this->pdo, $context);

        try {
            $profile->save('Clínica B Ltda', null, 'Invasor', $this->now);
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedException) {
                throw $e;
            }
        }

        Assert::same('Unidade A', $this->value('SELECT name FROM system_unit WHERE id = ?', [$this->a['unit']]));
    }

    private function checklist(array $clinic): OnboardingChecklistService
    {
        return new OnboardingChecklistService($this->pdo, $this->context($clinic));
    }

    private function profile(array $clinic): ClinicProfileService
    {
        return new ClinicProfileService($this->pdo, $this->context($clinic));
    }

    /** @param array{tenant: int, unit: int, user: int} $clinic */
    private function context(array $clinic): TenantContext
    {
        return TenantContext::authenticated($clinic['tenant'], $clinic['user'], $clinic['unit']);
    }

    private function done(OnboardingChecklistService $checklist, string $item): bool
    {
        foreach ($checklist->status() as $row) {
            if ($row['item'] === $item) {
                return $row['done'];
            }
        }

        throw new AssertionFailedException("Item {$item} missing from status()");
    }

    private function expect(string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof AssertionFailedException) {
                throw $e;
            }
            Assert::true($e instanceof \DomainException, sprintf('Expected DomainException, got %s: %s', $e::class, $e->getMessage()));
            Assert::same($message, $e->getMessage());

            return;
        }

        throw new AssertionFailedException("Expected DomainException('{$message}') was not thrown");
    }

    /** @return array{tenant: int, unit: int, user: int} */
    private function clinic(string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $this->pdo->prepare("INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (?, ?, ?, 'active')")
            ->execute([$this->uuid(), 't09-' . strtolower($label) . '-' . $suffix, 'Clínica ' . $label]);
        $tenantId = (int) $this->pdo->lastInsertId();

        $unitId = $this->nextId('system_unit');
        $this->pdo->prepare('INSERT INTO system_unit (id, tenant_id, name) VALUES (?, ?, ?)')
            ->execute([$unitId, $tenantId, 'Unidade ' . $label]);

        $userId = $this->member($tenantId, 'active', $unitId);

        return ['tenant' => $tenantId, 'unit' => $unitId, 'user' => $userId];
    }

    private function member(int $tenantId, string $status, ?int $unitId = null): int
    {
        $login = 't09-' . bin2hex(random_bytes(5)) . '@example.test';
        $userId = $this->nextId('system_users');
        $this->pdo->prepare("INSERT INTO system_users (id, name, login, email, password, active, system_unit_id) VALUES (?, ?, ?, ?, 'x', 'Y', ?)")
            ->execute([$userId, 'Usuário ' . $userId, $login, $login, $unitId]);
        $this->pdo->prepare('INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $userId, $status]);

        return $userId;
    }

    private function service(int $tenantId, int $priceCents, int $active): void
    {
        $this->pdo->prepare('INSERT INTO service (tenant_id, name, duration_minutes, price_cents, active) VALUES (?, ?, 30, ?, ?)')
            ->execute([$tenantId, 'T09 Consulta ' . bin2hex(random_bytes(4)), $priceCents, $active]);
    }

    private function nextId(string $table): int
    {
        return (int) $this->value(sprintf('SELECT COALESCE(MAX(id), 0) + 1 FROM %s FOR UPDATE', $table));
    }

    /** @param list<int|string> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

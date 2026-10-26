<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\BillingOrder;
use CentralVet\Persistence\AccountEmailReader;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * Etapa 3, T-27: o checkout recusa o e-mail que já é login
 * (`system_users.login`) ou que já pagou outro pedido (`billing_order`
 * paid/provisioned/provisioning_failed). Comparação sem diferença de caixa
 * (collation da coluna) e com trim. Tudo é desfeito no rollback do tearDown().
 */
final class AccountEmailReaderIntegrationTest extends MysqlIntegrationTestCase
{
    private BillingOrderRepository $orders;

    private AccountEmailReader $reader;

    public function setUp(): void
    {
        parent::setUp();

        $billingTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_order'")->fetchColumn();
        Assert::true($billingTable, 'Migration 0017 (billing_order) must be applied to the test database');

        $this->orders = new BillingOrderRepository($this->pdo);
        $this->reader = new AccountEmailReader($this->pdo);
    }

    public function testLoginIsTakenIgnoringCaseAndSurroundingSpaces(): void
    {
        $this->createUser('dono.t27@clinica.test');

        Assert::true($this->reader->isTaken(' Dono.T27@Clinica.TEST '), 'login with other case and spaces is taken');
        Assert::true($this->reader->isTaken('dono.t27@clinica.test'));
        Assert::false($this->reader->isTaken('outro.t27@clinica.test'));
    }

    public function testPaidProvisionedAndFailedOrdersTakeTheEmail(): void
    {
        $tenantId = (int) $this->pdo->query('SELECT MIN(id) FROM tenant')->fetchColumn();

        $paid = $this->paid('pago.t27@clinica.test');
        $this->orders->insert($paid);

        $provisioned = $this->paid('provisionado.t27@clinica.test');
        $provisioned->markProvisioned($tenantId);
        $this->orders->insert($provisioned);

        $failed = $this->paid('falhou.t27@clinica.test');
        $failed->markProvisioningFailed('login_conflict');
        $this->orders->insert($failed);

        Assert::true($this->reader->isTaken('Pago.T27@Clinica.test'), 'paid order takes the e-mail');
        Assert::true($this->reader->isTaken('provisionado.t27@clinica.test'), 'provisioned order takes the e-mail');
        Assert::true($this->reader->isTaken(' falhou.t27@clinica.test'), 'provisioning_failed order takes the e-mail');
    }

    public function testOpenAndExpiredOrdersDoNotTakeTheEmail(): void
    {
        $open = $this->place('aberto.t27@clinica.test');
        $open->attachCheckout('cs_test_' . bin2hex(random_bytes(8)), 'cus_test_t27');
        $this->orders->insert($open);

        $expired = $this->place('expirado.t27@clinica.test');
        $expired->expire();
        $this->orders->insert($expired);

        $this->orders->insert($this->place('pendente.t27@clinica.test'));

        Assert::false($this->reader->isTaken('aberto.t27@clinica.test'), 'checkout_open does not take the e-mail');
        Assert::false($this->reader->isTaken('expirado.t27@clinica.test'), 'expired does not take the e-mail');
        Assert::false($this->reader->isTaken('pendente.t27@clinica.test'), 'pending does not take the e-mail');
    }

    public function testEmptyEmailIsNotTaken(): void
    {
        Assert::false($this->reader->isTaken(''));
        Assert::false($this->reader->isTaken('   '));
    }

    /** system_users.id não é auto_increment (Adianti). */
    private function createUser(string $login): void
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();
        $statement = $this->pdo->prepare(
            "INSERT INTO system_users (id, name, login, active) VALUES (:id, :name, :login, 'Y')",
        );
        $statement->execute(['id' => $id, 'name' => 'T-27 user ' . $id, 'login' => $login]);
    }

    private function paid(string $email): BillingOrder
    {
        $order = $this->place($email);
        $order->attachCheckout('cs_test_' . bin2hex(random_bytes(8)), 'cus_test_t27');
        $order->markPaid('sub_test_' . bin2hex(random_bytes(4)), new DateTimeImmutable());

        return $order;
    }

    private function place(string $email): BillingOrder
    {
        $hex = bin2hex(random_bytes(16));
        $uuid = sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));

        return BillingOrder::place(
            $uuid,
            'pro',
            BillingOrder::METHOD_CARD,
            'Responsável T-27',
            $email,
            '+55 85 90000-0000',
            'Clínica T-27',
            '2026-10',
            new DateTimeImmutable('2026-10-26 09:00:00'),
            '203.0.113.27',
            false,
        );
    }
}

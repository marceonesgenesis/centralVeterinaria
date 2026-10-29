<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\PdoOperationalCounts;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * T-12: contagens globais do monitor operacional no `centralvet_test`. As
 * linhas inseridas ficam na transação desfeita no tearDown; cada teste mede
 * a diferença em relação à contagem anterior (o banco pode ter outras linhas).
 */
final class PdoOperationalCountsIntegrationTest extends MysqlIntegrationTestCase
{
    private const KEYS = [
        'billing_event_failed',
        'billing_order_stuck',
        'message_failed',
        'message_queue_stalled',
        'document_failed',
        'document_queue_stalled',
    ];

    public function testEveryKeyIsANonNegativeInteger(): void
    {
        $counts = (new PdoOperationalCounts($this->pdo))->counts(new DateTimeImmutable());

        $keys = array_keys($counts);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        Assert::same($expected, $keys);
        foreach ($counts as $key => $value) {
            Assert::true(is_int($value) && $value >= 0, $key . ' deveria ser inteiro >= 0');
        }
    }

    public function testFailedBillingEventIsCounted(): void
    {
        $now = new DateTimeImmutable();
        $before = $this->count('billing_event_failed', $now);

        $this->insertEvent('zz_t12_failed', 'failed', $now);

        Assert::same($before + 1, $this->count('billing_event_failed', $now));
    }

    public function testReceivedBillingEventOlderThanOneHourIsCounted(): void
    {
        $now = new DateTimeImmutable();
        $before = $this->count('billing_event_failed', $now);

        $this->insertEvent('zz_t12_received_old', 'received', $now->modify('-2 hours'));
        $this->insertEvent('zz_t12_received_new', 'received', $now->modify('-10 minutes'));

        Assert::same($before + 1, $this->count('billing_event_failed', $now));
    }

    public function testPaidOrderNotUpdatedForTwoHoursIsStuck(): void
    {
        $now = new DateTimeImmutable();
        $before = $this->count('billing_order_stuck', $now);

        $this->insertOrder('paid', $now->modify('-2 hours'));
        $this->insertOrder('paid', $now->modify('-5 minutes'));
        $this->insertOrder('provisioned', $now->modify('-2 hours'));

        Assert::same($before + 1, $this->count('billing_order_stuck', $now));
    }

    private function count(string $key, DateTimeImmutable $now): int
    {
        return (new PdoOperationalCounts($this->pdo))->counts($now)[$key];
    }

    private function insertEvent(string $eventId, string $status, DateTimeImmutable $receivedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO billing_event (provider, event_id, event_type, event_created, livemode, payload, status, received_at) '
            . "VALUES ('stripe', :event_id, 'checkout.session.completed', :created, 0, '{}', :status, :received_at)"
        );
        $statement->execute([
            'event_id' => $eventId . '_' . bin2hex(random_bytes(4)),
            'created' => $receivedAt->getTimestamp(),
            'status' => $status,
            'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function insertOrder(string $status, DateTimeImmutable $updatedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO billing_order (public_id, plan_id, price_cents, payment_method, responsible_name, responsible_email, '
            . 'responsible_phone, clinic_name, terms_version, terms_ip, status, created_at, updated_at) '
            . "VALUES (:public_id, 'starter', 9900, 'card', 'Teste T12', 'zz-t12@example.test', '11999999999', "
            . "'Clínica T12', 'v1', '127.0.0.1', :status, :at, :at2)"
        );
        $at = $updatedAt->format('Y-m-d H:i:s.u');
        $statement->execute([
            'public_id' => $this->uuid(),
            'status' => $status,
            'at' => $at,
            'at2' => $at,
        ]);
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Billing\BillingEventPayloadRetention;
use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Etapa 4, T-13: retenção LGPD de `billing_event.payload` (30 dias) contra o
 * schema real da 0017. Com relógio fixo num passado distante (nenhuma linha
 * alheia passa do corte), só o evento `processed` de 31 dias tem o payload
 * esvaziado; o de 1 dia e o `failed` de 31 dias ficam. A linha nunca sai:
 * `COUNT(*)` e o cursor `latestEventCreated()` do reconciliador não mudam.
 * Tudo dentro da transação do teste, desfeita no tearDown().
 */
final class BillingEventPayloadRetentionIntegrationTest extends MysqlIntegrationTestCase
{
    private const NOW = '2001-06-15 12:00:00.000000';

    private const PAYLOAD = '{"id":"evt_zz_e4_ret","data":{"object":{"customer_email":"zz-e4-ret@example.test"}}}';

    public function setUp(): void
    {
        parent::setUp();

        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $hasTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_event'")->fetchColumn();
        Assert::true($hasTable, 'Migration 0017 must be applied to the test database');
    }

    public function testEmptiesOnlyProcessedPayloadsOlderThanThirtyDaysAndKeepsTheRows(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $this->assertNothingElseIsPastTheCutoff($now);

        $old = $this->event('processed', $now->modify('-31 days'));
        $recent = $this->event('processed', $now->modify('-1 day'));
        $failed = $this->event('failed', $now->modify('-31 days'));

        $repository = new BillingEventRepository($this->pdo);
        $countBefore = $this->count('SELECT COUNT(*) FROM billing_event');
        $cursorBefore = $repository->latestEventCreated();

        $result = BillingEventPayloadRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now)->runOnce();

        Assert::same(1, $result['purged']);
        Assert::same(1, $result['batches']);
        Assert::same('', $this->payload($old));
        Assert::same(self::PAYLOAD, $this->payload($recent));
        Assert::same(self::PAYLOAD, $this->payload($failed));
        Assert::same($countBefore, $this->count('SELECT COUNT(*) FROM billing_event'), 'a retenção nunca apaga a linha');
        Assert::same($cursorBefore, $repository->latestEventCreated(), 'o cursor do reconciliador não muda');

        $again = BillingEventPayloadRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now)->runOnce();
        Assert::same(0, $again['purged']);
        Assert::same(0, $again['batches']);
    }

    public function testIgnoredIsEmptiedAndReceivedIsKept(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $this->assertNothingElseIsPastTheCutoff($now);

        $ignored = $this->event('ignored', $now->modify('-31 days'));
        $received = $this->event('received', $now->modify('-31 days'));

        $result = BillingEventPayloadRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now)->runOnce();

        Assert::same(1, $result['purged']);
        Assert::same('', $this->payload($ignored));
        Assert::same(self::PAYLOAD, $this->payload($received));
    }

    public function testInjectedBatchSizeAndCapStopAtTheCap(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $this->assertNothingElseIsPastTheCutoff($now);

        for ($i = 0; $i < 5; ++$i) {
            $this->event('processed', $now->modify('-40 days'));
        }

        $result = BillingEventPayloadRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now, 2, 2)->runOnce();
        Assert::same(['purged' => 4, 'batches' => 2], $result);

        $rest = BillingEventPayloadRetention::forConnection($this->pdo, static fn (): DateTimeImmutable => $now, 2, 2)->runOnce();
        Assert::same(['purged' => 1, 'batches' => 1], $rest);
    }

    public function testRejectsBatchSizeOrCapBelowOneAndExposesTheDefaults(): void
    {
        foreach ([[0, 1], [1, 0]] as [$batch, $max]) {
            $thrown = false;
            try {
                BillingEventPayloadRetention::forConnection($this->pdo, null, $batch, $max);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            Assert::true($thrown, "forConnection(batch={$batch}, max={$max}) deve lançar InvalidArgumentException");
        }

        Assert::same(30, BillingEventPayloadRetention::RETENTION_DAYS);
        Assert::same(500, BillingEventPayloadRetention::BATCH_SIZE);
        Assert::same(200, BillingEventPayloadRetention::MAX_BATCHES);
    }

    private function event(string $status, DateTimeImmutable $receivedAt): int
    {
        $this->pdo->prepare(
            'INSERT INTO billing_event (provider, event_id, event_type, event_created, livemode, payload, status, received_at)'
            . " VALUES ('stripe', :event_id, 'checkout.session.completed', :created, 0, :payload, :status, :received)",
        )->execute([
            'event_id' => 'evt_zz_e4_ret_' . bin2hex(random_bytes(6)),
            'created' => $receivedAt->getTimestamp(),
            'payload' => self::PAYLOAD,
            'status' => $status,
            'received' => $receivedAt->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function payload(int $id): string
    {
        return (string) $this->pdo->query('SELECT payload FROM billing_event WHERE id = ' . $id)->fetchColumn();
    }

    private function assertNothingElseIsPastTheCutoff(DateTimeImmutable $now): void
    {
        $cutoff = $now->modify('-' . BillingEventPayloadRetention::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');
        Assert::same(0, $this->count("SELECT COUNT(*) FROM billing_event WHERE received_at < '{$cutoff}' AND payload <> ''"), 'nenhuma linha alheia passa do corte');
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * BillingEventRepository (T-09) against `billing_event` (migration 0017):
 * exact deduplication by `event_id`. Everything runs inside the rolled-back
 * test transaction.
 */
final class BillingEventRepositoryIntegrationTest extends MysqlIntegrationTestCase
{
    private BillingEventRepository $events;

    public function setUp(): void
    {
        parent::setUp();

        $billingTable = (bool) $this->pdo->query("SHOW TABLES LIKE 'billing_event'")->fetchColumn();
        Assert::true($billingTable, 'Migration 0017 (billing_event) must be applied to the test database');

        $this->events = new BillingEventRepository($this->pdo);
    }

    public function testClaimStoresTheEventAsReceived(): void
    {
        $eventId = $this->eventId();
        $id = $this->events->claim($eventId, 'checkout.session.completed', 1792000000, false, '{"id":"' . $eventId . '"}');

        Assert::notNull($id, 'A new event is claimed');
        $row = $this->row((int) $id);
        Assert::same('stripe', $row['provider']);
        Assert::same($eventId, $row['event_id']);
        Assert::same('checkout.session.completed', $row['event_type']);
        Assert::same(1792000000, (int) $row['event_created']);
        Assert::same(0, (int) $row['livemode']);
        Assert::same('{"id":"' . $eventId . '"}', $row['payload']);
        Assert::same('received', $row['status']);
        Assert::same(1, (int) $row['attempts']);
        Assert::null($row['processed_at']);
    }

    public function testDuplicateOfAProcessedEventIsNotClaimedAgain(): void
    {
        $eventId = $this->eventId();
        $id = (int) $this->events->claim($eventId, 'invoice.paid', 1792000001, true, '{}');
        $this->events->markDone($id, 'processed', new DateTimeImmutable('2026-10-21 13:14:15.123456'));

        Assert::null($this->events->claim($eventId, 'invoice.paid', 1792000001, true, '{}'), 'processed event is a duplicate');

        $row = $this->row($id);
        Assert::same('processed', $row['status']);
        Assert::same(1, (int) $row['attempts'], 'a duplicate does not count as an attempt');
        Assert::same('2026-10-21 13:14:15.123456', $row['processed_at']);
    }

    public function testDuplicateOfAnIgnoredEventIsNotClaimedAgain(): void
    {
        $eventId = $this->eventId();
        $id = (int) $this->events->claim($eventId, 'customer.created', 1792000002, false, '{}');
        $this->events->markDone($id, 'ignored', new DateTimeImmutable());

        Assert::null($this->events->claim($eventId, 'customer.created', 1792000002, false, '{}'));
    }

    public function testFailedEventIsClaimedAgainWithTheSameId(): void
    {
        $eventId = $this->eventId();
        $id = (int) $this->events->claim($eventId, 'invoice.payment_failed', 1792000003, false, '{}');
        $this->events->markFailed($id, str_repeat('x', 250));

        $row = $this->row($id);
        Assert::same('failed', $row['status']);
        Assert::same(190, mb_strlen((string) $row['last_error']), 'error is cut to 190 characters');

        Assert::same($id, $this->events->claim($eventId, 'invoice.payment_failed', 1792000003, false, '{}'), 'failed event is retried');
        Assert::same(2, (int) $this->row($id)['attempts']);

        Assert::same($id, $this->events->claim($eventId, 'invoice.payment_failed', 1792000003, false, '{}'), 'received event is retried');
        Assert::same(3, (int) $this->row($id)['attempts']);
    }

    public function testMarkDoneRejectsOtherStatuses(): void
    {
        $id = (int) $this->events->claim($this->eventId(), 'invoice.paid', 1792000004, false, '{}');

        Assert::throws(InvalidArgumentException::class, fn () => $this->events->markDone($id, 'failed', new DateTimeImmutable()));
        Assert::throws(InvalidArgumentException::class, fn () => $this->events->markDone($id, 'received', new DateTimeImmutable()));
    }

    public function testLatestEventCreatedIsTheHighestStoredCreated(): void
    {
        $before = $this->events->latestEventCreated();
        $max = max((int) $before, 4000000000) + 17;

        $this->events->claim($this->eventId(), 'invoice.paid', $max - 5, false, '{}');
        $this->events->claim($this->eventId(), 'invoice.paid', $max, false, '{}');

        Assert::same($max, $this->events->latestEventCreated());
    }

    private function eventId(): string
    {
        return 'evt_test_' . bin2hex(random_bytes(10));
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM billing_event WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        Assert::true(is_array($row), "billing_event {$id} exists");

        return $row;
    }
}

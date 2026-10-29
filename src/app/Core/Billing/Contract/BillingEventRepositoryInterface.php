<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

use DateTimeImmutable;

/**
 * Persistence of Stripe events in `billing_event` (migration 0017), with
 * exact deduplication by `event_id`.
 */
interface BillingEventRepositoryInterface
{
    /**
     * Stores a new event as `received` (attempts = 1) and returns its id.
     * When the event_id already exists: `processed`/`ignored` → null;
     * `received`/`failed` → the row is locked FOR UPDATE, `attempts` is
     * incremented and its id is returned.
     */
    public function claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int;

    /** @param string $status `processed` or `ignored` */
    public function markDone(int $id, string $status, DateTimeImmutable $at): void;

    /** Marks the event `failed`, with the error cut to 190 characters. */
    public function markFailed(int $id, string $error): void;

    /** Highest `event_created` stored, or null when there is none. */
    public function latestEventCreated(): ?int;
}

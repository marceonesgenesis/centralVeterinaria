<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\Contract\BillingEventRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO-backed `billing_event` (migration 0017). No TenantContext: events
 * arrive from Stripe before (and independently of) any tenant.
 *
 * claim() is a plain INSERT; the UNIQUE `billing_event_provider_event_uq`
 * (provider, event_id) answers a repetition with 1062, and then the existing
 * row is read FOR UPDATE: finished (`processed`/`ignored`) → null, otherwise
 * attempts + 1 and the same id. A concurrent claim of an event another
 * transaction inserted uncommitted waits on that key. Never INSERT IGNORE.
 */
final class BillingEventRepository implements BillingEventRepositoryInterface
{
    private const PROVIDER = 'stripe';

    private const DUPLICATE_KEY = 1062;

    private const UNIQUE_KEY = 'billing_event_provider_event_uq';

    private const DONE_STATUSES = ['processed', 'ignored'];

    private const ERROR_LENGTH = 190;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO billing_event (provider, event_id, event_type, event_created, livemode, payload, status, attempts) '
            . "VALUES (:provider, :event_id, :event_type, :event_created, :livemode, :payload, 'received', 1)"
        );

        try {
            $statement->execute([
                'provider' => self::PROVIDER,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'event_created' => $eventCreated,
                'livemode' => $livemode ? 1 : 0,
                'payload' => $payload,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            if (!$this->isDuplicateEvent($exception)) {
                throw $exception;
            }
        }

        $existing = $this->pdo->prepare(
            'SELECT id, status FROM billing_event WHERE provider = :provider AND event_id = :event_id FOR UPDATE'
        );
        $existing->execute(['provider' => self::PROVIDER, 'event_id' => $eventId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(sprintf('Billing event %s vanished after a duplicate claim', $eventId));
        }

        if (in_array($row['status'], self::DONE_STATUSES, true)) {
            return null;
        }

        $id = (int) $row['id'];
        $this->pdo->prepare('UPDATE billing_event SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $id]);

        return $id;
    }

    public function markDone(int $id, string $status, DateTimeImmutable $at): void
    {
        if (!in_array($status, self::DONE_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Billing event cannot be marked done as "%s"', $status));
        }

        $this->update(
            'UPDATE billing_event SET status = :status, processed_at = :processed_at WHERE id = :id',
            ['status' => $status, 'processed_at' => $at->format('Y-m-d H:i:s.u'), 'id' => $id],
            $id,
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->update(
            "UPDATE billing_event SET status = 'failed', last_error = :last_error WHERE id = :id",
            ['last_error' => mb_substr($error, 0, self::ERROR_LENGTH), 'id' => $id],
            $id,
        );
    }

    public function latestEventCreated(): ?int
    {
        $value = $this->pdo->query('SELECT MAX(event_created) FROM billing_event')->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    /** @param array<string, int|string> $parameters */
    private function update(string $sql, array $parameters, int $id): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        if ($statement->rowCount() > 0) {
            return;
        }

        $check = $this->pdo->prepare('SELECT 1 FROM billing_event WHERE id = :id');
        $check->execute(['id' => $id]);

        if ($check->fetchColumn() === false) {
            throw new RuntimeException(sprintf('Billing event %d not found', $id));
        }
    }

    private function isDuplicateEvent(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === self::DUPLICATE_KEY
            && str_contains((string) ($exception->errorInfo[2] ?? $exception->getMessage()), self::UNIQUE_KEY);
    }
}

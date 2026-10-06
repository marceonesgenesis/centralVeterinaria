<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\OutboundMessage;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for OutboundMessageRepositoryInterface (T-06). Stores rows
 * keyed like `communication_message` and findById() returns a fresh
 * OutboundMessage::reconstitute() copy, like the PDO repository.
 *
 * insertIfNew() reproduces the UNIQUE (tenant_id, dedupe_key): a repeated
 * non-null key of the same tenant inserts nothing and returns null. Every
 * transition mirrors `UPDATE ... WHERE status = <expected>`: it returns false
 * (changing nothing) when the row is missing, belongs to another tenant or
 * is not in the expected state. simulateConcurrentTransition() changes the
 * stored status as another worker/tab would, to prove races.
 */
final class FakeOutboundMessageRepository implements OutboundMessageRepositoryInterface
{
    private const CLAIM_TTL = '-10 minutes';

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, OutboundMessage ...$seed)
    {
        $this->seed(...$seed);
    }

    /** Stores messages as they are (any tenant, no dedupe check), assigning ids when missing. */
    public function seed(OutboundMessage ...$messages): void
    {
        foreach ($messages as $message) {
            $this->store($message);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function insertIfNew(OutboundMessage $message): ?OutboundMessage
    {
        if ($message->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('Outbound message belongs to another tenant');
        }

        if ($message->dedupeKey() !== null) {
            foreach ($this->rows as $row) {
                if ($row['tenant_id'] === $this->tenantId && $row['dedupe_key'] === $message->dedupeKey()) {
                    return null;
                }
            }
        }

        $this->store($message);

        return $message;
    }

    public function findById(int|string $id): ?object
    {
        $row = $this->ownRow((int) $id);

        return $row === null ? null : OutboundMessage::reconstitute($row);
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof OutboundMessage) {
            throw new InvalidArgumentException('FakeOutboundMessageRepository only stores OutboundMessage entities');
        }

        $this->store($entity);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof OutboundMessage && $entity->id() !== null && $this->ownRow($entity->id()) !== null) {
            unset($this->rows[$entity->id()]);
        }
    }

    public function claim(int $messageId, DateTimeImmutable $now): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED || $row['channel'] !== CommunicationChannel::EMAIL) {
            return false;
        }

        if ($row['claimed_at'] !== null && new DateTimeImmutable($row['claimed_at']) > $now->modify(self::CLAIM_TTL)) {
            return false;
        }

        return $this->update($messageId, ['claimed_at' => self::date($now)]);
    }

    public function markSent(int $messageId, string $provider, ?string $providerMessageId, DateTimeImmutable $sentAt): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED || $row['claimed_at'] === null) {
            return false;
        }

        return $this->update($messageId, [
            'status' => OutboundMessage::STATUS_SENT,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'sent_at' => self::date($sentAt),
        ]);
    }

    public function releaseClaim(int $messageId, string $errorCode): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED) {
            return false;
        }

        return $this->update($messageId, [
            'claimed_at' => null,
            'attempt_count' => $row['attempt_count'] + 1,
            'last_error_code' => $errorCode,
        ]);
    }

    public function markFailed(int $messageId, string $errorCode, DateTimeImmutable $failedAt): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED) {
            return false;
        }

        return $this->update($messageId, [
            'status' => OutboundMessage::STATUS_FAILED,
            'attempt_count' => $row['attempt_count'] + 1,
            'last_error_code' => $errorCode,
            'failed_at' => self::date($failedAt),
        ]);
    }

    public function markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED || $row['channel'] !== CommunicationChannel::WHATSAPP) {
            return false;
        }

        return $this->update($messageId, [
            'status' => OutboundMessage::STATUS_MANUAL,
            'manual_sent_by_system_user_id' => $systemUserId,
            'sent_at' => self::date($sentAt),
        ]);
    }

    public function cancel(int $messageId, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_QUEUED) {
            return false;
        }

        return $this->update($messageId, [
            'status' => OutboundMessage::STATUS_CANCELLED,
            'last_error_code' => $reasonCode,
            'cancelled_at' => self::date($cancelledAt),
            'cancelled_by_system_user_id' => $systemUserId,
        ]);
    }

    public function requeue(int $messageId): bool
    {
        $row = $this->ownRow($messageId);

        if ($row === null || $row['status'] !== OutboundMessage::STATUS_FAILED) {
            return false;
        }

        return $this->update($messageId, [
            'status' => OutboundMessage::STATUS_QUEUED,
            'failed_at' => null,
            'claimed_at' => null,
        ]);
    }

    public function listForUnit(int $systemUnitId, array $filters, int $limit): array
    {
        $rows = array_filter($this->rows, function (array $row) use ($systemUnitId, $filters): bool {
            if ($row['tenant_id'] !== $this->tenantId || $row['system_unit_id'] !== $systemUnitId) {
                return false;
            }

            foreach (['status', 'channel', 'purpose', 'tutor_id'] as $key) {
                if (isset($filters[$key]) && $row[$key] !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        });
        usort($rows, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return array_map(
            static fn (array $row): OutboundMessage => OutboundMessage::reconstitute($row),
            array_slice($rows, 0, max(0, $limit)),
        );
    }

    public function listStaleQueuedEmailIds(DateTimeImmutable $olderThan, int $limit): array
    {
        $limitDate = self::date($olderThan);
        $rows = array_filter(
            $this->rows,
            fn (array $row): bool => $row['tenant_id'] === $this->tenantId
                && $row['status'] === OutboundMessage::STATUS_QUEUED
                && $row['channel'] === CommunicationChannel::EMAIL
                && $row['created_at'] < $limitDate,
        );
        usort($rows, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);

        return array_map(static fn (array $row): int => $row['id'], array_slice($rows, 0, max(0, $limit)));
    }

    /**
     * Every stored message (all tenants), as fresh copies ordered by id.
     *
     * @return list<OutboundMessage>
     */
    public function all(): array
    {
        ksort($this->rows);

        return array_values(array_map(static fn (array $row): OutboundMessage => OutboundMessage::reconstitute($row), $this->rows));
    }

    /** Simulates another worker/tab changing the stored status of the message. */
    public function simulateConcurrentTransition(int $messageId, string $status): void
    {
        if (!isset($this->rows[$messageId])) {
            throw new InvalidArgumentException("Outbound message {$messageId} not found");
        }

        $this->rows[$messageId]['status'] = $status;
    }

    private function store(OutboundMessage $message): void
    {
        if ($message->id() === null) {
            $message->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $message->id() + 1);
        }

        $this->rows[(int) $message->id()] = self::toRow($message);
    }

    /** @param array<string, mixed> $changes */
    private function update(int $messageId, array $changes): bool
    {
        $this->rows[$messageId] = array_merge($this->rows[$messageId], $changes, ['updated_at' => self::date(new DateTimeImmutable())]);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function ownRow(int $id): ?array
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->tenantId ? $row : null;
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s.u');
    }

    /** @return array<string, mixed> */
    private static function toRow(OutboundMessage $m): array
    {
        $now = self::date(new DateTimeImmutable());

        return [
            'id' => $m->id(),
            'tenant_id' => $m->tenantId(),
            'system_unit_id' => $m->systemUnitId(),
            'tutor_id' => $m->tutorId(),
            'patient_id' => $m->patientId(),
            'template_id' => $m->templateId(),
            'purpose' => $m->purpose(),
            'channel' => $m->channel(),
            'origin' => $m->origin(),
            'legal_basis' => $m->legalBasis(),
            'source_type' => $m->sourceType(),
            'source_id' => $m->sourceId(),
            'dedupe_key' => $m->dedupeKey(),
            'recipient' => $m->recipient(),
            'subject' => $m->subject(),
            'body_text' => $m->bodyText(),
            'status' => $m->status(),
            'attempt_count' => $m->attemptCount(),
            'last_error_code' => $m->lastErrorCode(),
            'provider' => $m->provider(),
            'provider_message_id' => $m->providerMessageId(),
            'claimed_at' => self::date($m->claimedAt()),
            'sent_at' => self::date($m->sentAt()),
            'failed_at' => self::date($m->failedAt()),
            'cancelled_at' => self::date($m->cancelledAt()),
            'manual_sent_by_system_user_id' => $m->manualSentBySystemUserId(),
            'cancelled_by_system_user_id' => $m->cancelledBySystemUserId(),
            'created_by_system_user_id' => $m->createdBySystemUserId(),
            'created_at' => self::date($m->createdAt()) ?? $now,
            'updated_at' => $now,
        ];
    }
}

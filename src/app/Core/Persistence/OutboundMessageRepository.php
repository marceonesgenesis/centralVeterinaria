<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * PDO-backed persistence for outbound messages (`communication_message`,
 * migration 20261006_0012_phase7a_communication). Every query starts from
 * TenantQuery::forTenant() (ADR 0002).
 *
 * A message is inserted once (`insertIfNew`, a plain INSERT: a duplicate
 * key error 1062 on a non-null `dedupe_key` means "already generated" and
 * returns null; never the IGNORE modifier, which on MySQL 8 turns CHECK
 * violations into warnings). After that it only moves through conditional
 * transitions: one `UPDATE ... WHERE id AND tenant_id AND status =
 * <expected>` per method, whose `rowCount() === 1` is the answer, so
 * concurrent workers and attendants cannot overwrite each other.
 *
 * @implements OutboundMessageRepositoryInterface<OutboundMessage>
 */
final class OutboundMessageRepository extends AbstractTenantRepository implements OutboundMessageRepositoryInterface
{
    /** A claim older than this is considered abandoned (worker died mid-job). */
    private const CLAIM_TTL = '-10 minutes';

    private const DUPLICATE_KEY = 1062;

    private const LIST_FILTERS = ['status', 'channel', 'purpose', 'tutor_id'];

    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function insertIfNew(OutboundMessage $message): ?OutboundMessage
    {
        if ($message->id() !== null) {
            throw new InvalidArgumentException('Outbound message already has an id');
        }

        $this->assertEntityTenant($message->tenantId());

        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO communication_message (
                tenant_id, system_unit_id, tutor_id, patient_id, template_id,
                purpose, channel, origin, source_type, source_id, dedupe_key,
                legal_basis, recipient, subject, body_text, status,
                attempt_count, created_by_system_user_id
            ) VALUES (
                :tenant_id, :system_unit_id, :tutor_id, :patient_id, :template_id,
                :purpose, :channel, :origin, :source_type, :source_id, :dedupe_key,
                :legal_basis, :recipient, :subject, :body_text, :status,
                :attempt_count, :created_by_system_user_id
            )
            SQL
        );

        try {
            $statement->execute([
                ':tenant_id' => $message->tenantId(),
                ':system_unit_id' => $message->systemUnitId(),
                ':tutor_id' => $message->tutorId(),
                ':patient_id' => $message->patientId(),
                ':template_id' => $message->templateId(),
                ':purpose' => $message->purpose(),
                ':channel' => $message->channel(),
                ':origin' => $message->origin(),
                ':source_type' => $message->sourceType(),
                ':source_id' => $message->sourceId(),
                ':dedupe_key' => $message->dedupeKey(),
                ':legal_basis' => $message->legalBasis(),
                ':recipient' => $message->recipient(),
                ':subject' => $message->subject(),
                ':body_text' => $message->bodyText(),
                ':status' => $message->status(),
                ':attempt_count' => $message->attemptCount(),
                ':created_by_system_user_id' => $message->createdBySystemUserId(),
            ]);
        } catch (PDOException $exception) {
            // Only the UNIQUE (tenant_id, dedupe_key) is a "duplicate" here:
            // a null key never collides, and every other violation (CHECK,
            // FK) must surface.
            if ($message->dedupeKey() !== null && (int) ($exception->errorInfo[1] ?? 0) === self::DUPLICATE_KEY) {
                return null;
            }

            throw $exception;
        }

        $message->assignId((int) $this->connection->lastInsertId());

        return $message;
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM communication_message WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : OutboundMessage::reconstitute($row);
    }

    /**
     * Inserts a new message (same as insertIfNew(), but a duplicate
     * `dedupe_key` is an error). Existing messages only change through the
     * conditional transitions.
     */
    public function save(object $entity): object
    {
        if (!$entity instanceof OutboundMessage) {
            throw new InvalidArgumentException('Expected an OutboundMessage entity');
        }

        if ($entity->id() !== null) {
            throw new LogicException('Outbound messages change only through conditional transitions');
        }

        if ($this->insertIfNew($entity) === null) {
            throw new RuntimeException('Outbound message with the same dedupe key already exists');
        }

        return $entity;
    }

    /** Message history is never deleted. */
    public function remove(object $entity): void
    {
        throw new LogicException('Outbound messages are never removed');
    }

    public function claim(int $messageId, DateTimeImmutable $now): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED, 'channel' => CommunicationChannel::EMAIL],
            'claimed_at = :claimed_at',
            [':claimed_at' => self::timestamp($now)],
            ' AND (claimed_at IS NULL OR claimed_at < :stale_before)',
            [':stale_before' => self::timestamp($now->modify(self::CLAIM_TTL))],
        )->rowCount() === 1;
    }

    public function markSent(int $messageId, string $provider, ?string $providerMessageId, DateTimeImmutable $sentAt): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED],
            'status = :new_status, provider = :provider, provider_message_id = :provider_message_id, sent_at = :sent_at',
            [
                ':new_status' => OutboundMessage::STATUS_SENT,
                ':provider' => $provider,
                ':provider_message_id' => $providerMessageId,
                ':sent_at' => self::timestamp($sentAt),
            ],
            ' AND claimed_at IS NOT NULL',
        )->rowCount() === 1;
    }

    public function releaseClaim(int $messageId, string $errorCode): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED],
            'claimed_at = NULL, attempt_count = attempt_count + 1, last_error_code = :error_code',
            [':error_code' => $errorCode],
        )->rowCount() === 1;
    }

    public function markFailed(int $messageId, string $errorCode, DateTimeImmutable $failedAt): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED],
            'status = :new_status, attempt_count = attempt_count + 1, last_error_code = :error_code, failed_at = :failed_at',
            [
                ':new_status' => OutboundMessage::STATUS_FAILED,
                ':error_code' => $errorCode,
                ':failed_at' => self::timestamp($failedAt),
            ],
        )->rowCount() === 1;
    }

    public function markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED, 'channel' => CommunicationChannel::WHATSAPP],
            'status = :new_status, manual_sent_by_system_user_id = :user_id, sent_at = :sent_at',
            [
                ':new_status' => OutboundMessage::STATUS_MANUAL,
                ':user_id' => $systemUserId,
                ':sent_at' => self::timestamp($sentAt),
            ],
        )->rowCount() === 1;
    }

    public function cancel(int $messageId, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_QUEUED],
            'status = :new_status, last_error_code = :reason_code, cancelled_at = :cancelled_at, cancelled_by_system_user_id = :user_id',
            [
                ':new_status' => OutboundMessage::STATUS_CANCELLED,
                ':reason_code' => $reasonCode,
                ':cancelled_at' => self::timestamp($cancelledAt),
                ':user_id' => $systemUserId,
            ],
        )->rowCount() === 1;
    }

    public function cancelQueuedForTutor(int $tutorId, string $channel, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): int
    {
        $query = $this->tenantQuery()
            ->andEquals('tutor_id', $tutorId)
            ->andEquals('channel', $channel)
            ->andEquals('status', OutboundMessage::STATUS_QUEUED);

        $statement = $this->connection->prepare(
            'UPDATE communication_message SET status = :new_status, last_error_code = :reason_code, '
            . 'cancelled_at = :cancelled_at, cancelled_by_system_user_id = :user_id '
            . "WHERE {$query->whereSql()} AND claimed_at IS NULL",
        );
        $statement->execute([
            ...$query->parameters(),
            ':new_status' => OutboundMessage::STATUS_CANCELLED,
            ':reason_code' => $reasonCode,
            ':cancelled_at' => self::timestamp($cancelledAt),
            ':user_id' => $systemUserId,
        ]);

        return $statement->rowCount();
    }

    public function requeue(int $messageId): bool
    {
        return $this->conditionalUpdate(
            $messageId,
            ['status' => OutboundMessage::STATUS_FAILED],
            'status = :new_status, failed_at = NULL, claimed_at = NULL',
            [':new_status' => OutboundMessage::STATUS_QUEUED],
        )->rowCount() === 1;
    }

    public function listForUnit(int $systemUnitId, array $filters, int $limit): array
    {
        $query = $this->tenantQuery()->andEquals('system_unit_id', $systemUnitId);

        foreach (self::LIST_FILTERS as $key) {
            if (isset($filters[$key])) {
                $query = $query->andEquals($key, $key === 'tutor_id' ? (int) $filters[$key] : (string) $filters[$key]);
            }
        }

        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            "SELECT * FROM communication_message WHERE {$query->whereSql()} ORDER BY created_at DESC, id DESC LIMIT {$limit}",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): OutboundMessage => OutboundMessage::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listStaleQueuedEmailIds(DateTimeImmutable $olderThan, int $limit): array
    {
        $query = $this->tenantQuery()
            ->andEquals('status', OutboundMessage::STATUS_QUEUED)
            ->andEquals('channel', CommunicationChannel::EMAIL);
        $limit = max(1, $limit);

        $statement = $this->connection->prepare(
            <<<SQL
            SELECT id FROM communication_message
            WHERE {$query->whereSql()}
              AND created_at < :created_before
              AND (claimed_at IS NULL OR claimed_at < :claimed_before)
            ORDER BY created_at ASC, id ASC
            LIMIT {$limit}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':created_before' => self::timestamp($olderThan),
            ':claimed_before' => self::timestamp($olderThan),
        ]);

        return array_map(static fn (mixed $id): int => (int) $id, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * One conditional UPDATE: applies `$set` only to the tenant's row that
     * matches every `$expected` column (and `$extraWhere`). Each named
     * parameter appears once, so it also works with native prepares. The
     * caller's answer is `rowCount() === 1` (exactly one row moved).
     *
     * @param array<string, string> $expected
     * @param array<string, int|string|null> $setParameters
     * @param array<string, int|string|null> $extraParameters
     */
    private function conditionalUpdate(
        int $messageId,
        array $expected,
        string $set,
        array $setParameters,
        string $extraWhere = '',
        array $extraParameters = [],
    ): PDOStatement {
        $query = $this->tenantQuery()->andEquals('id', $messageId);

        foreach ($expected as $column => $value) {
            $query = $query->andEquals($column, $value);
        }

        $statement = $this->connection->prepare(
            "UPDATE communication_message SET {$set} WHERE {$query->whereSql()}{$extraWhere}",
        );
        $statement->execute([...$query->parameters(), ...$setParameters, ...$extraParameters]);

        return $statement;
    }

    private static function timestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.u');
    }
}

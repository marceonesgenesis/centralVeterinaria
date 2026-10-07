<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\OutboundMessage;
use CentralVet\Persistence\TenantRepositoryInterface;
use DateTimeImmutable;

/**
 * Persistence boundary for outbound messages (`communication_message`),
 * always within the current tenant.
 *
 * A message is inserted once (`insertIfNew`) and then only moves through
 * conditional transitions: each one is an `UPDATE ... WHERE status =
 * <expected>` and returns true only when one row of the tenant changed.
 *
 * - `claim`: `queued` + channel `email` + (`claimed_at` null or older than
 *   `$now` - 10 min) → sets `claimed_at = $now`.
 * - `markSent`: `queued` with a claim → `sent` (provider, provider message
 *   id, `sent_at`).
 * - `releaseClaim`: `queued` → `claimed_at` null, `attempt_count` + 1,
 *   `last_error_code`.
 * - `markFailed`: `queued` → `failed` (`attempt_count` + 1,
 *   `last_error_code`, `failed_at`).
 * - `markManualSent`: `queued` + channel `whatsapp` → `manual`
 *   (`manual_sent_by_system_user_id`, `sent_at`).
 * - `cancel`: `queued` → `cancelled` (reason code in `last_error_code`:
 *   `discarded`, `opted_out` or `consent_missing`; `cancelled_at`,
 *   `cancelled_by_system_user_id`).
 * - `requeue`: `failed` → `queued` (clears `failed_at` and `claimed_at`).
 * - `cancelQueuedForTutor`: every `queued`, unclaimed message of the tutor
 *   on one channel → `cancelled` (bulk form of `cancel`, used on opt-out).
 *
 * @template TEntity of object
 * @extends TenantRepositoryInterface<TEntity>
 */
interface OutboundMessageRepositoryInterface extends TenantRepositoryInterface
{
    /**
     * Inserts the message and assigns its id. Returns null (inserting
     * nothing) when the tenant already has a message with the same
     * `dedupe_key`; a null key never collides.
     */
    public function insertIfNew(OutboundMessage $message): ?OutboundMessage;

    /** @return TEntity|null */
    public function findById(int|string $id): ?object;

    public function claim(int $messageId, DateTimeImmutable $now): bool;

    public function markSent(int $messageId, string $provider, ?string $providerMessageId, DateTimeImmutable $sentAt): bool;

    public function releaseClaim(int $messageId, string $errorCode): bool;

    public function markFailed(int $messageId, string $errorCode, DateTimeImmutable $failedAt): bool;

    public function markManualSent(int $messageId, int $systemUserId, DateTimeImmutable $sentAt): bool;

    public function cancel(int $messageId, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): bool;

    public function requeue(int $messageId): bool;

    /**
     * Cancels, in one conditional UPDATE, every `queued` message of the
     * tutor on `$channel` within the tenant (all units: the preference is
     * tenant-wide) that is not claimed by a worker; a claimed e-mail is
     * left to the worker's own preference re-check. Returns how many rows
     * changed.
     */
    public function cancelQueuedForTutor(int $tutorId, string $channel, ?int $systemUserId, string $reasonCode, DateTimeImmutable $cancelledAt): int;

    /**
     * Messages of a unit ordered by `created_at DESC`, at most `$limit`.
     * Optional filters: `status`, `channel`, `purpose`, `tutor_id`.
     *
     * @param array{status?: string, channel?: string, purpose?: string, tutor_id?: int} $filters
     * @return list<OutboundMessage>
     */
    public function listForUnit(int $systemUnitId, array $filters, int $limit): array;

    /**
     * Ids of `queued` e-mail messages created before `$olderThan` (stuck
     * after a lost publish), oldest first, at most `$limit`.
     *
     * @return list<int>
     */
    public function listStaleQueuedEmailIds(DateTimeImmutable $olderThan, int $limit): array;
}

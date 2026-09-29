<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by `QueueEntry::advance()` (invoked from
 * `CentralVet\Application\QueueEntryService::advanceStatus()`) when there is
 * no legal next status from the entry's current status.
 *
 * The queue status machine only exposes "advance by one step from wherever
 * the entry currently is" — there is no "set status to X" operation — so
 * this single exception covers both ways a caller could try to go out of
 * turn:
 *   - skipping a step (e.g. `aguardando` straight to `atendido`): rejected
 *     because `aguardando`'s only legal next status is `em_atendimento`;
 *   - regressing (e.g. `atendido` back to `em_atendimento`): rejected
 *     because `atendido` is terminal and has no next status at all.
 *
 * Kept in `Domain\Exception` alongside `CrossTenantReferenceException`
 * (same rationale: a business rule owned by the Domain layer, reusable from
 * any presentation — Adianti today, REST/worker later).
 */
final class InvalidStatusTransitionException extends RuntimeException
{
}

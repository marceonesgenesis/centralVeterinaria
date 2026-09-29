<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by AppointmentService::schedule() (T-07) when the requested
 * [scheduled_at, scheduled_at + service duration) window overlaps another,
 * still-active appointment already booked for the same
 * professional_system_user_id within the tenant.
 *
 * Distinct from CrossTenantReferenceException, which rejects a dangling or
 * foreign-tenant foreign key (patient_id/service_id) before any scheduling
 * logic runs: this one rejects a business-rule conflict between two
 * otherwise-valid, same-tenant appointments.
 */
final class SchedulingConflictException extends RuntimeException
{
}

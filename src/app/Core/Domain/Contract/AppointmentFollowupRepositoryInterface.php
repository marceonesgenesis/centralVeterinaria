<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Persistence boundary for `appointment_followup`: marks an appointment as
 * the return visit scheduled from an encounter, within the current tenant.
 */
interface AppointmentFollowupRepositoryInterface
{
    /** Records that `$appointmentId` is the return visit of `$encounterId`. */
    public function link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void;

    /** True when the appointment of the current tenant is a recorded return visit. */
    public function isFollowup(int $appointmentId): bool;
}

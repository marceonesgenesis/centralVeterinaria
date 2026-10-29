<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown on admission when the patient already has a hospitalization in
 * status `admitted` within the current tenant.
 */
final class PatientAlreadyHospitalizedException extends DomainException
{
    public static function forPatient(int $patientId): self
    {
        return new self("Patient {$patientId} already has an active hospitalization");
    }
}

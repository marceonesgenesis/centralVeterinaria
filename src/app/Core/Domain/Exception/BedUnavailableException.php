<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown when a bed cannot take (or give up) a hospitalization: the
 * conditional occupy UPDATE lost the race, the bed is inactive, or an
 * occupied bed is being deactivated. Messages are raw English; the
 * presentation maps them through `UserMessage`.
 */
final class BedUnavailableException extends DomainException
{
    public static function forBed(int $bedId): self
    {
        return new self("Bed {$bedId} is not available");
    }

    public static function occupied(int $bedId): self
    {
        return new self("Bed {$bedId} is occupied and cannot be deactivated");
    }
}

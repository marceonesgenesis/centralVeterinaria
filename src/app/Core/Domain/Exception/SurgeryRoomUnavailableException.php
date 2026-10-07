<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * Thrown when a surgery cannot be booked in a room: the room already holds
 * an overlapping open surgery (`scheduled`/`pre_op`/`in_progress`) or the
 * room is inactive. Messages are raw English; the presentation maps them
 * through `UserMessage`.
 */
final class SurgeryRoomUnavailableException extends DomainException
{
    public static function booked(int $roomId): self
    {
        return new self("Surgery room {$roomId} is already booked for this period");
    }

    public static function inactive(int $roomId): self
    {
        return new self("Surgery room {$roomId} is not active");
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\AppointmentFollowupRepositoryInterface;

/**
 * In-memory double for AppointmentFollowupRepositoryInterface (T-06): one
 * link per appointment (UNIQUE appointment_id); a repeated link() keeps the
 * first one. links() exposes the stored rows to the tests.
 */
final class FakeAppointmentFollowupRepository implements AppointmentFollowupRepositoryInterface
{
    /** @var array<int, array{encounter_id: int, created_by_system_user_id: int}> keyed by appointment id */
    private array $links = [];

    /** @param array<int, int> $seed appointment id => encounter id */
    public function __construct(private readonly int $tenantId, array $seed = [])
    {
        foreach ($seed as $appointmentId => $encounterId) {
            $this->links[$appointmentId] = ['encounter_id' => $encounterId, 'created_by_system_user_id' => 1];
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function link(int $appointmentId, int $encounterId, int $createdBySystemUserId): void
    {
        $this->links[$appointmentId] ??= [
            'encounter_id' => $encounterId,
            'created_by_system_user_id' => $createdBySystemUserId,
        ];
    }

    public function isFollowup(int $appointmentId): bool
    {
        return isset($this->links[$appointmentId]);
    }

    /** @return array<int, array{encounter_id: int, created_by_system_user_id: int}> */
    public function links(): array
    {
        return $this->links;
    }
}

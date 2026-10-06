<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for CommunicationPreferenceRepositoryInterface (T-06):
 * one row per tenant, tutor and channel; upsert() overwrites status, consent
 * source, author and change time; findForTutor() returns fresh copies.
 */
final class FakeCommunicationPreferenceRepository implements CommunicationPreferenceRepositoryInterface
{
    /** @var array<string, array<string, mixed>> keyed by "tenant:tutor:channel" */
    private array $rows = [];
    private int $nextId = 1;

    /** Number of upsert() calls after construction (seed not counted). */
    public int $upsertCount = 0;

    public function __construct(private readonly int $tenantId, CommunicationPreference ...$seed)
    {
        foreach ($seed as $preference) {
            $this->store($preference);
        }
    }

    public function findForTutor(int $tutorId): array
    {
        $map = [];

        foreach ($this->rows as $row) {
            if ($row['tenant_id'] === $this->tenantId && $row['tutor_id'] === $tutorId) {
                $map[$row['channel']] = CommunicationPreference::reconstitute($row);
            }
        }

        ksort($map);

        return $map;
    }

    public function upsert(CommunicationPreference $preference): void
    {
        if ($preference->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('Communication preference belongs to another tenant');
        }

        $this->store($preference);
        $this->upsertCount++;
    }

    private function store(CommunicationPreference $p): void
    {
        $key = "{$p->tenantId()}:{$p->tutorId()}:{$p->channel()}";
        $existing = $this->rows[$key] ?? null;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        if ($existing === null && $p->id() === null) {
            $p->assignId($this->nextId++);
        }

        $this->rows[$key] = [
            'id' => $existing['id'] ?? $p->id(),
            'tenant_id' => $p->tenantId(),
            'tutor_id' => $p->tutorId(),
            'channel' => $p->channel(),
            'status' => $p->status(),
            'consent_source' => $p->consentSource(),
            'changed_by_system_user_id' => $p->changedBySystemUserId(),
            'changed_at' => $p->changedAt()->format('Y-m-d H:i:s.u'),
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\CopilotClinicalQueryInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for CopilotClinicalQueryInterface (T-06).
 *
 * seed('patient' | 'encounter', $rowsById) stores rows keyed by id;
 * seed(<other method>, $rows) stores the list that method returns (cut to
 * $limit). tutorNames takes a map patient id => list<string>. setInUnit()
 * answers patientInUnit()/encounterInUnit() (default false). Nothing is
 * filtered by unit here: the caller decides what to seed. calls() records
 * every call with its arguments, in order.
 */
final class FakeCopilotClinicalQuery implements CopilotClinicalQueryInterface
{
    private const BY_ID = ['patient', 'encounter', 'tutorNames'];
    private const LISTS = ['searchPatients', 'encounters', 'prescriptions', 'exams', 'vaccinations'];

    /** @var array<string, array<int|string, mixed>> */
    private array $seeded = [];

    /** @var array{patient: array<int, bool>, encounter: array<int, bool>} */
    private array $inUnit = ['patient' => [], 'encounter' => []];

    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];

    /** @param array<int|string, mixed> $rows */
    public function seed(string $method, array $rows): void
    {
        if (!in_array($method, [...self::BY_ID, ...self::LISTS], true)) {
            throw new InvalidArgumentException("Unknown method to seed: {$method}");
        }

        $this->seeded[$method] = in_array($method, self::BY_ID, true) ? $rows : array_values($rows);
    }

    public function setInUnit(string $kind, int $id, bool $inUnit): void
    {
        if (!isset($this->inUnit[$kind])) {
            throw new InvalidArgumentException("Unknown kind: {$kind}");
        }

        $this->inUnit[$kind][$id] = $inUnit;
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function patientInUnit(int $patientId): bool
    {
        $this->record(__FUNCTION__, [$patientId]);

        return $this->inUnit['patient'][$patientId] ?? false;
    }

    public function encounterInUnit(int $encounterId): bool
    {
        $this->record(__FUNCTION__, [$encounterId]);

        return $this->inUnit['encounter'][$encounterId] ?? false;
    }

    public function searchPatients(string $term, int $limit): array
    {
        $this->record(__FUNCTION__, [$term, $limit]);

        return $this->list(__FUNCTION__, $limit);
    }

    public function patient(int $patientId): ?array
    {
        $this->record(__FUNCTION__, [$patientId]);

        return $this->seeded['patient'][$patientId] ?? null;
    }

    public function encounters(int $patientId, int $limit): array
    {
        $this->record(__FUNCTION__, [$patientId, $limit]);

        return $this->list(__FUNCTION__, $limit);
    }

    public function encounter(int $encounterId): ?array
    {
        $this->record(__FUNCTION__, [$encounterId]);

        return $this->seeded['encounter'][$encounterId] ?? null;
    }

    public function prescriptions(int $patientId, bool $onlyActive, DateTimeImmutable $today, int $limit): array
    {
        $this->record(__FUNCTION__, [$patientId, $onlyActive, $today->format('Y-m-d'), $limit]);

        return $this->list(__FUNCTION__, $limit);
    }

    public function exams(int $patientId, string $situacao, int $limit): array
    {
        if (!in_array($situacao, [self::EXAMS_PENDING, self::EXAMS_RELEASED, self::EXAMS_ALL], true)) {
            throw new InvalidArgumentException('Unknown exam situation');
        }

        $this->record(__FUNCTION__, [$patientId, $situacao, $limit]);

        return $this->list(__FUNCTION__, $limit);
    }

    public function vaccinations(int $patientId, int $limit): array
    {
        $this->record(__FUNCTION__, [$patientId, $limit]);

        return $this->list(__FUNCTION__, $limit);
    }

    public function tutorNames(int $patientId): array
    {
        $this->record(__FUNCTION__, [$patientId]);

        return array_values($this->seeded['tutorNames'][$patientId] ?? []);
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    /** @return list<array<string, mixed>> */
    private function list(string $method, int $limit): array
    {
        return array_slice($this->seeded[$method] ?? [], 0, max(1, $limit));
    }
}

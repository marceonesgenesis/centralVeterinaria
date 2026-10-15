<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\AiClinicalContextQueryInterface;

/**
 * In-memory double for AiClinicalContextQueryInterface (T-05). Arrays are
 * keyed by encounter id, patient id and exam result id; an unknown id
 * returns null (patientHistory: empty lists).
 */
final class FakeAiClinicalContextQuery implements AiClinicalContextQueryInterface
{
    /** @var list<array{method: string, id: int}> */
    private array $calls = [];

    /**
     * @param array<int, array<string, mixed>> $encounters
     * @param array<int, array<string, mixed>> $histories
     * @param array<int, array<string, mixed>> $examResults
     */
    public function __construct(
        private readonly array $encounters = [],
        private readonly array $histories = [],
        private readonly array $examResults = [],
    ) {
    }

    public function encounterContext(int $encounterId): ?array
    {
        $this->calls[] = ['method' => 'encounterContext', 'id' => $encounterId];

        return $this->encounters[$encounterId] ?? null;
    }

    public function patientHistory(int $patientId, ?int $excludeEncounterId): array
    {
        $this->calls[] = ['method' => 'patientHistory', 'id' => $patientId];

        return $this->histories[$patientId] ?? ['encounters' => [], 'prescriptions' => [], 'exam_results' => []];
    }

    public function examResultContext(int $examResultId): ?array
    {
        $this->calls[] = ['method' => 'examResultContext', 'id' => $examResultId];

        return $this->examResults[$examResultId] ?? null;
    }

    /** @return list<array{method: string, id: int}> */
    public function calls(): array
    {
        return $this->calls;
    }
}

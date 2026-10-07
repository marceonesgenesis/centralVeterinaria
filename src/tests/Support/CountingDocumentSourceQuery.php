<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\DocumentSourceQueryInterface;

/**
 * Decorator of DocumentSourceQueryInterface that counts the calls per
 * method (T-23), so a test can prove a service reads a source once.
 */
final class CountingDocumentSourceQuery implements DocumentSourceQueryInterface
{
    /** @var array<string, int> */
    private array $calls = [];

    public function __construct(private readonly DocumentSourceQueryInterface $inner)
    {
    }

    public function calls(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function patientSummary(int $patientId): ?array
    {
        $this->count(__FUNCTION__);

        return $this->inner->patientSummary($patientId);
    }

    public function vaccinations(int $patientId): array
    {
        $this->count(__FUNCTION__);

        return $this->inner->vaccinations($patientId);
    }

    public function prescription(int $prescriptionId): ?array
    {
        $this->count(__FUNCTION__);

        return $this->inner->prescription($prescriptionId);
    }

    public function surgery(int $surgeryId): ?array
    {
        $this->count(__FUNCTION__);

        return $this->inner->surgery($surgeryId);
    }

    public function tutorContact(int $tutorId): ?array
    {
        $this->count(__FUNCTION__);

        return $this->inner->tutorContact($tutorId);
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}

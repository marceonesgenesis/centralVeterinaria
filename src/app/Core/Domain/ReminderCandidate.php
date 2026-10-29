<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * A reminder the scheduler may generate (Fase 7A) — read by
 * {@see Contract\ReminderSourceQueryInterface} from appointments, vaccines
 * and open receivables.
 *
 * Carries the tutor contact in memory only: e-mail and phone must never be
 * logged, queued or put in an exception message. `variables` arrive already
 * formatted (`appointment_date` d/m/Y, `appointment_time` H:i, `due_date`
 * d/m/Y, `amount_due` `R$ 1.234,56`). No Adianti dependency (ADR 0001).
 */
final class ReminderCandidate
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        private readonly string $purpose,
        private readonly string $sourceType,
        private readonly int $sourceId,
        private readonly int $systemUnitId,
        private readonly int $tutorId,
        private readonly ?int $patientId,
        private readonly ?string $tutorEmail,
        private readonly string $tutorPhone,
        private readonly array $variables,
    ) {
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceId(): int
    {
        return $this->sourceId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function patientId(): ?int
    {
        return $this->patientId;
    }

    public function tutorEmail(): ?string
    {
        return $this->tutorEmail;
    }

    public function tutorPhone(): string
    {
        return $this->tutorPhone;
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Keeps the contact out of var_dump/print_r/debug output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'purpose' => $this->purpose,
            'sourceType' => $this->sourceType,
            'sourceId' => $this->sourceId,
            'systemUnitId' => $this->systemUnitId,
            'tutorId' => $this->tutorId,
            'patientId' => $this->patientId,
        ];
    }
}

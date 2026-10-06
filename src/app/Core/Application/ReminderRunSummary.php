<?php

declare(strict_types=1);

namespace CentralVet\Application;

/**
 * Result of one {@see ReminderGenerationService::generate()} run (Fase 7A):
 * counters plus the ids of the e-mail messages created, which the scheduler
 * publishes to the queue. `toArray()` carries only the counters (no ids, no
 * contact), so it can be logged or printed as JSON.
 */
final class ReminderRunSummary
{
    /**
     * @param list<int> $emailMessageIds
     */
    public function __construct(
        private readonly int $created,
        private readonly int $duplicates,
        private readonly int $skippedNoConsent,
        private readonly int $skippedNoContact,
        private readonly int $skippedOptedOut,
        private readonly array $emailMessageIds,
    ) {
    }

    public function created(): int
    {
        return $this->created;
    }

    public function duplicates(): int
    {
        return $this->duplicates;
    }

    public function skippedNoConsent(): int
    {
        return $this->skippedNoConsent;
    }

    public function skippedNoContact(): int
    {
        return $this->skippedNoContact;
    }

    public function skippedOptedOut(): int
    {
        return $this->skippedOptedOut;
    }

    /** @return list<int> */
    public function emailMessageIds(): array
    {
        return $this->emailMessageIds;
    }

    /** @return array{created: int, duplicates: int, skipped_no_consent: int, skipped_no_contact: int, skipped_opted_out: int} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'duplicates' => $this->duplicates,
            'skipped_no_consent' => $this->skippedNoConsent,
            'skipped_no_contact' => $this->skippedNoContact,
            'skipped_opted_out' => $this->skippedOptedOut,
        ];
    }
}

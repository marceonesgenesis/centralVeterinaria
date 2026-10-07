<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Landing\Contract\LeadStoreInterface;
use CentralVet\Landing\LeadSubmission;

/**
 * In-memory double for LeadStoreInterface, used by LeadSubmissionHandlerTest.
 * Records every insert (lead, consent IP, consent instant); when built with a
 * Throwable, insert() throws it instead, to prove the handler never leaks the
 * exception message.
 */
final class FakeLeadStore implements LeadStoreInterface
{
    /** @var list<array{lead: LeadSubmission, ip: string, at: \DateTimeImmutable}> */
    public array $inserted = [];

    private int $nextId = 1;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->inserted[] = ['lead' => $lead, 'ip' => $consentIp, 'at' => $consentAt];

        return $this->nextId++;
    }
}

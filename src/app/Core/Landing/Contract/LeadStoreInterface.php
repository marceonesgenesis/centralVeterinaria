<?php

declare(strict_types=1);

namespace CentralVet\Landing\Contract;

use CentralVet\Landing\LeadSubmission;

interface LeadStoreInterface
{
    /** Grava o lead com a prova do consentimento e devolve o id gravado. */
    public function insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int;
}

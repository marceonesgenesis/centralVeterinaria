<?php

declare(strict_types=1);

namespace CentralVet\Landing;

use CentralVet\Landing\Contract\LeadStoreInterface;

/**
 * Adia a criação do store real (e a conexão MySQL) até o primeiro insert:
 * pedidos recusados antes da gravação não abrem conexão. A fábrica roda no
 * máximo uma vez; o store criado é reaproveitado.
 */
final class LazyLeadStore implements LeadStoreInterface
{
    private ?LeadStoreInterface $store = null;

    /** @param \Closure(): LeadStoreInterface $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int
    {
        $this->store ??= ($this->factory)();

        return $this->store->insert($lead, $consentIp, $consentAt);
    }
}

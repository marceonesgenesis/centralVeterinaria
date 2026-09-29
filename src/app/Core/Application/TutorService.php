<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Tutor;
use InvalidArgumentException;

/**
 * Tutor use cases. Pure Core application service: no Adianti page/record
 * class, no framework coupling — depends only on
 * CentralVet\Domain\Contract\TutorRepositoryInterface (T-02), so it is
 * testable in isolation with a fake repository.
 */
final class TutorService
{
    public function __construct(private readonly TutorRepositoryInterface $repository)
    {
    }

    /**
     * Searches tutors by name, CPF/CNPJ or phone within the current tenant.
     *
     * @return list<Tutor>
     */
    public function search(string $query): array
    {
        $term = trim($query);

        if ($term === '') {
            return [];
        }

        /** @var list<Tutor> $results */
        $results = $this->repository->search($term);

        return $results;
    }

    /**
     * @param array{
     *     tenant_id: int,
     *     full_name: string,
     *     phone: string,
     *     document?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     * } $data
     */
    public function create(array $data): Tutor
    {
        $tenantId = $data['tenant_id'] ?? null;
        $fullName = isset($data['full_name']) ? trim((string) $data['full_name']) : '';
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
        $document = isset($data['document']) && $data['document'] !== '' ? (string) $data['document'] : null;
        $email = isset($data['email']) && $data['email'] !== '' ? (string) $data['email'] : null;
        $address = isset($data['address']) && $data['address'] !== '' ? (string) $data['address'] : null;

        if (!is_int($tenantId) || $tenantId <= 0) {
            throw new InvalidArgumentException('tenant_id is required and must be a positive integer');
        }

        if ($fullName === '') {
            throw new InvalidArgumentException('full_name is required');
        }

        if ($phone === '') {
            throw new InvalidArgumentException('phone is required');
        }

        if ($document !== null && $this->repository->findByDocument($document) !== null) {
            throw new InvalidArgumentException('A tutor with this document already exists in this tenant');
        }

        $tutor = Tutor::register(
            tenantId: $tenantId,
            fullName: $fullName,
            phone: $phone,
            document: $document,
            email: $email,
            address: $address,
        );

        /** @var Tutor $saved */
        $saved = $this->repository->save($tutor);

        return $saved;
    }

    public function findById(int $id): ?Tutor
    {
        /** @var Tutor|null $tutor */
        $tutor = $this->repository->findById($id);

        return $tutor;
    }
}

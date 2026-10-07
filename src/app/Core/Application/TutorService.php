<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\NameText;
use CentralVet\Domain\Tutor;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Tutor use cases. Pure Core application service: no Adianti page/record
 * class, no framework coupling — depends only on
 * CentralVet\Domain\Contract\TutorRepositoryInterface (T-02) and the
 * TenantContext, so it is testable in isolation with a fake repository.
 * The tenant of a new tutor always comes from the context, never from
 * the input data.
 */
final class TutorService
{
    public function __construct(
        private readonly TutorRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
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
     * Registers a tutor in the tenant of the TenantContext; any
     * 'tenant_id' key in $data is ignored.
     *
     * @param array{
     *     full_name: string,
     *     phone: string,
     *     document?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     * } $data
     */
    public function create(array $data): Tutor
    {
        [
            'full_name' => $fullName,
            'phone' => $phone,
            'document' => $document,
            'email' => $email,
            'address' => $address,
        ] = self::normalize($data);

        if ($fullName === '') {
            throw new InvalidArgumentException('full_name is required');
        }

        NameText::assertNoMarkup($fullName);

        if ($phone === '') {
            throw new InvalidArgumentException('phone is required');
        }

        if ($document !== null && $this->repository->findByDocument($document) !== null) {
            throw new InvalidArgumentException('A tutor with this document already exists in this tenant');
        }

        $tutor = Tutor::register(
            tenantId: $this->context->tenantId(),
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

    /**
     * Updates the registration data of an existing tutor of the current
     * tenant. id, tenantId and publicId never change. A document is only a
     * duplicate when it belongs to another tutor of the tenant.
     *
     * @param array{
     *     full_name: string,
     *     phone: string,
     *     document?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     * } $data
     */
    public function update(int $id, array $data): Tutor
    {
        [
            'full_name' => $fullName,
            'phone' => $phone,
            'document' => $document,
            'email' => $email,
            'address' => $address,
        ] = self::normalize($data);

        if ($fullName === '') {
            throw new InvalidArgumentException('full_name is required');
        }

        NameText::assertNoMarkup($fullName);

        if ($phone === '') {
            throw new InvalidArgumentException('phone is required');
        }

        $tutor = $this->findById($id);

        if ($tutor === null) {
            throw new InvalidArgumentException("Tutor {$id} not found for this tenant");
        }

        if ($document !== null) {
            $holder = $this->repository->findByDocument($document);

            if ($holder instanceof Tutor && $holder->id !== $tutor->id) {
                throw new InvalidArgumentException('A tutor with this document already exists in this tenant');
            }
        }

        /** @var Tutor $saved */
        $saved = $this->repository->save($tutor->withDetails(
            fullName: $fullName,
            phone: $phone,
            document: $document,
            email: $email,
            address: $address,
        ));

        return $saved;
    }

    /**
     * Shared normalization of create()/update() input: full_name and phone
     * are trimmed ('' when absent); document, email and address become null
     * when absent or ''.
     *
     * @param array<string, mixed> $data
     * @return array{full_name: string, phone: string, document: ?string, email: ?string, address: ?string}
     */
    private static function normalize(array $data): array
    {
        $optional = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== ''
            ? (string) $data[$key]
            : null;

        return [
            'full_name' => isset($data['full_name']) ? trim((string) $data['full_name']) : '',
            'phone' => isset($data['phone']) ? trim((string) $data['phone']) : '',
            'document' => $optional('document'),
            'email' => $optional('email'),
            'address' => $optional('address'),
        ];
    }

    public function findById(int $id): ?Tutor
    {
        /** @var Tutor|null $tutor */
        $tutor = $this->repository->findById($id);

        return $tutor;
    }
}

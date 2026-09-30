<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Domain\Contract\ServiceRepositoryInterface;
use CentralVet\Domain\Service;
use CentralVet\Tenancy\TenantContext;
use DomainException;
use InvalidArgumentException;

/**
 * Use cases for the service (catalog) aggregate: registering a new catalog
 * entry and listing the ones currently offered by the tenant.
 *
 * Named ServiceCatalogService — not ServiceService — to avoid a naming
 * collision with the Service domain entity (CentralVet\Domain\Service),
 * which represents a veterinary procedure/item, not this application layer.
 */
final class ServiceCatalogService
{
    public const CSV_HEADER = 'name;category;duration_minutes;price';

    public function __construct(
        private readonly ServiceRepositoryInterface $repository,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * With `active` => false the service is stored inactive in the same
     * single save() (no insert-then-update).
     *
     * @param array{name: string, category?: string|null, duration_minutes: int, price_cents: int, active?: bool} $data
     */
    public function create(array $data): Service
    {
        if (!array_key_exists('name', $data)) {
            throw new InvalidArgumentException('name is required');
        }

        if (!array_key_exists('duration_minutes', $data)) {
            throw new InvalidArgumentException('duration_minutes is required');
        }

        if (!array_key_exists('price_cents', $data)) {
            throw new InvalidArgumentException('price_cents is required');
        }

        $name = (string) $data['name'];
        $category = isset($data['category']) && $data['category'] !== '' ? (string) $data['category'] : null;
        $durationMinutes = (int) $data['duration_minutes'];
        $priceCents = (int) $data['price_cents'];

        if ($this->repository->findByName(trim($name)) !== null) {
            throw new InvalidArgumentException("A service named \"{$name}\" already exists for this tenant");
        }

        $service = Service::create(
            tenantId: $this->context->tenantId(),
            name: $name,
            category: $category,
            durationMinutes: $durationMinutes,
            priceCents: $priceCents,
        );

        if (array_key_exists('active', $data) && !(bool) $data['active']) {
            $service->deactivate();
        }

        /** @var Service $saved */
        $saved = $this->repository->save($service);

        return $saved;
    }

    /**
     * Copies a service of the current tenant (same category, duration and
     * price) as a new, inactive entry named "{name} ({copyLabel})", or
     * "{name} ({copyLabel} N)" with the first free N >= 2.
     */
    public function duplicate(int $id, string $copyLabel = 'copy'): Service
    {
        $original = $this->findOwned($id);

        $name = "{$original->name()} ({$copyLabel})";

        for ($n = 2; $this->repository->findByName($name) !== null; $n++) {
            $name = "{$original->name()} ({$copyLabel} {$n})";
        }

        return $this->create([
            'name' => $name,
            'category' => $original->category(),
            'duration_minutes' => $original->durationMinutes(),
            'price_cents' => $original->priceCents(),
            'active' => false,
        ]);
    }

    /**
     * Deletes a service of the current tenant. A service referenced by an
     * appointment (FK RESTRICT) cannot be deleted and must be deactivated.
     */
    public function delete(int $id): void
    {
        $service = $this->findOwned($id);

        if ($this->repository->hasAppointments($id)) {
            throw new DomainException("Service {$id} has appointments; deactivate it instead");
        }

        $this->repository->remove($service);
    }

    /**
     * Imports services from a `;`-separated CSV whose first line is exactly
     * CSV_HEADER. UTF-8 BOM and CRLF are accepted; `price` is in reais with
     * comma or dot (120,50 → 12050 cents). Blank lines are ignored. Each
     * valid line becomes an active service; the others are reported with
     * their file line number (header = 1).
     *
     * @return array{created: int, skipped: list<array{line: int, reason: string}>}
     */
    public function importCsv(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];

        if (trim($lines[0] ?? '') !== self::CSV_HEADER) {
            throw new InvalidArgumentException('Invalid header: expected ' . self::CSV_HEADER);
        }

        $created = 0;
        $skipped = [];
        $seen = [];

        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $lineNumber = $index + 1;
            [$name, $category, $duration, $price] = array_pad(
                array_map('trim', str_getcsv($line, ';', '"', '')),
                4,
                '',
            );

            $reason = null;
            $priceCents = self::parsePriceCents($price);
            $nameKey = mb_strtolower($name);

            if ($name === '') {
                $reason = 'name is required';
            } elseif (!ctype_digit($duration) || (int) $duration <= 0) {
                $reason = 'invalid duration_minutes';
            } elseif ($priceCents === null) {
                $reason = 'invalid price';
            } elseif (isset($seen[$nameKey]) || $this->repository->findByName($name) !== null) {
                $reason = 'duplicated name';
            }

            if ($reason !== null) {
                $skipped[] = ['line' => $lineNumber, 'reason' => $reason];
                continue;
            }

            $this->create([
                'name' => $name,
                'category' => $category,
                'duration_minutes' => (int) $duration,
                'price_cents' => $priceCents,
            ]);
            $seen[$nameKey] = true;
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Updates an existing service of the current tenant in place (never inserts).
     *
     * @param array{name: string, category?: string|null, duration_minutes: int, price_cents: int, active?: bool} $data
     */
    public function update(int $id, array $data): Service
    {
        $service = $this->findOwned($id);

        foreach (['name', 'duration_minutes', 'price_cents'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("{$required} is required");
            }
        }

        $name = (string) $data['name'];
        $category = isset($data['category']) && $data['category'] !== '' ? (string) $data['category'] : null;

        $sameName = $this->repository->findByName(trim($name));

        if ($sameName instanceof Service && $sameName->id() !== $service->id()) {
            throw new InvalidArgumentException("A service named \"{$name}\" already exists for this tenant");
        }

        $service->changeDetails($name, $category, (int) $data['duration_minutes'], (int) $data['price_cents']);

        if (array_key_exists('active', $data)) {
            (bool) $data['active'] ? $service->activate() : $service->deactivate();
        }

        /** @var Service $saved */
        $saved = $this->repository->save($service);

        return $saved;
    }

    /** @return list<Service> active and inactive services of the tenant, ordered by name */
    public function listAll(): array
    {
        /** @var list<Service> $services */
        $services = $this->repository->listAll();

        return $services;
    }

    /** @return list<Service> */
    public function listActive(): array
    {
        /** @var list<Service> $services */
        $services = $this->repository->listActive();

        return $services;
    }

    public function findById(int $id): ?Service
    {
        /** @var Service|null $service */
        $service = $this->repository->findById($id);

        return $service;
    }

    private function findOwned(int $id): Service
    {
        $service = $this->repository->findById($id);

        if (!$service instanceof Service || $service->tenantId() !== $this->context->tenantId()) {
            throw new InvalidArgumentException('Service not found for this tenant');
        }

        return $service;
    }

    /** Reais with comma or dot and up to 2 decimals ("120,50", "80.00", "150") → cents; null when invalid. */
    private static function parsePriceCents(string $price): ?int
    {
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $price, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}

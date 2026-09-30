<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Named, reusable prescription template of a tenant (rodada 2, T-13):
 * header in `prescription_template` (migration 0007), medication lines in
 * `prescription_template_item` (same six free-text fields as
 * `prescription_item`, ordered by `position` 1..n).
 *
 * Items are kept as plain arrays (not PrescriptionItem) because a template
 * line is never issued on its own: PrescriptionForm (T-19) copies them into
 * the `items` list PrescriptionService::create() already accepts.
 *
 * Plain PHP entity, no Adianti dependency (ADR 0001).
 */
final class PrescriptionTemplate
{
    public const ITEM_FIELDS = ['medication_name', 'dose', 'dose_unit', 'route', 'frequency', 'duration'];

    /** Column widths of `prescription_template`/`_item` (migration 0007), in characters. */
    public const NAME_MAX_LENGTH = 190;

    /** @var array<string, int> */
    public const ITEM_FIELD_MAX_LENGTHS = [
        'medication_name' => 190,
        'dose' => 40,
        'dose_unit' => 20,
        'route' => 40,
        'frequency' => 60,
        'duration' => 60,
    ];

    /**
     * @param list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}> $items
     */
    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly string $name,
        private readonly ?string $orientationText,
        private readonly int $createdBySystemUserId,
        private readonly array $items,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items each with the six
     *        ITEM_FIELDS keys, in the order they must be applied.
     *
     * @throws InvalidArgumentException "name is required",
     *         "items must be a non-empty list", "items[].{field} is required"
     *         (missing or blank after trim) or "{field} must have at most
     *         {max} characters" (name and the six item fields).
     */
    public static function create(
        int $tenantId,
        string $name,
        ?string $orientationText,
        array $items,
        int $createdBySystemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }

        self::assertMaxLength('name', $name, self::NAME_MAX_LENGTH);

        if ($createdBySystemUserId <= 0) {
            throw new InvalidArgumentException('created_by_system_user_id must be positive');
        }

        if ($items === []) {
            throw new InvalidArgumentException('items must be a non-empty list');
        }

        $normalized = self::normalizeItems($items);

        foreach ($normalized as $line) {
            foreach (self::ITEM_FIELD_MAX_LENGTHS as $field => $max) {
                if (trim($line[$field]) === '') {
                    throw new InvalidArgumentException("items[].{$field} is required");
                }

                self::assertMaxLength($field, $line[$field], $max);
            }
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            name: $name,
            orientationText: $orientationText !== null && trim($orientationText) !== '' ? $orientationText : null,
            createdBySystemUserId: $createdBySystemUserId,
            items: $normalized,
        );
    }

    /**
     * Rebuilds a persisted template. Repositories only.
     *
     * @param array<string, mixed> $row `prescription_template` columns.
     * @param list<array<string, mixed>> $itemRows `prescription_template_item`
     *        rows, already ordered by position.
     */
    public static function reconstitute(array $row, array $itemRows): self
    {
        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            name: (string) $row['name'],
            orientationText: $row['orientation_text'] !== null ? (string) $row['orientation_text'] : null,
            createdBySystemUserId: (int) $row['created_by_system_user_id'],
            items: self::normalizeItems($itemRows),
        );
    }

    /** Assigns the identifier generated on first persistence. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Prescription template already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function orientationText(): ?string
    {
        return $this->orientationText;
    }

    public function createdBySystemUserId(): int
    {
        return $this->createdBySystemUserId;
    }

    /** @return list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}> */
    public function items(): array
    {
        return $this->items;
    }

    private static function assertMaxLength(string $field, string $value, int $max): void
    {
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} must have at most {$max} characters");
        }
    }

    /**
     * @param array<mixed> $items
     * @return list<array{medication_name: string, dose: string, dose_unit: string, route: string, frequency: string, duration: string}>
     */
    private static function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $line = [];

            foreach (self::ITEM_FIELDS as $field) {
                if (!is_array($item) || !array_key_exists($field, $item)) {
                    throw new InvalidArgumentException("items[].{$field} is required");
                }

                $line[$field] = (string) $item[$field];
            }

            $normalized[] = $line;
        }

        return $normalized;
    }
}

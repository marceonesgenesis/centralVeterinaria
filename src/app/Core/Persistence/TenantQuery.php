<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use InvalidArgumentException;

/**
 * Immutable SQL predicate builder that always starts with a tenant boundary.
 */
final class TenantQuery
{
    /** @param array<string, int|string|float|bool|null> $parameters */
    private function __construct(
        private readonly array $predicates,
        private readonly array $parameters,
        private readonly int $nextParameter,
    ) {
    }

    public static function forTenant(int $tenantId, ?string $alias = null): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        $prefix = self::columnPrefix($alias);

        return new self(["{$prefix}tenant_id = :tenant_scope_id"], [':tenant_scope_id' => $tenantId], 0);
    }

    public function andEquals(string $column, int|string|float|bool|null $value, ?string $alias = null): self
    {
        if ($column === 'tenant_id') {
            throw new InvalidArgumentException('Tenant predicate cannot be replaced');
        }

        self::assertIdentifier($column);
        $parameter = ':tenant_filter_' . $this->nextParameter;
        $predicates = [...$this->predicates, self::columnPrefix($alias) . $column . " = {$parameter}"];
        $parameters = $this->parameters;
        $parameters[$parameter] = $value;

        return new self($predicates, $parameters, $this->nextParameter + 1);
    }

    public function whereSql(): string
    {
        return implode(' AND ', $this->predicates);
    }

    /** @return array<string, int|string|float|bool|null> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    private static function columnPrefix(?string $alias): string
    {
        if ($alias === null || $alias === '') {
            return '';
        }

        self::assertIdentifier($alias);
        return $alias . '.';
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier');
        }
    }
}

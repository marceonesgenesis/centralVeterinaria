<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * S7: pure pre-filter for the Adianti search services.
 *
 * AdiantiMultiSearchService::onSearch (framework, protected by hash) only
 * signs database/key/column/model; operator, operator_idsearch,
 * orderColumn, mask and criteria come straight from the request and reach
 * SQL (TFilter, setProperty('order')) or the rendered label. This guard
 * accepts only the shapes the app's TDBUniqueSearch/TDBMultiSearch widgets
 * actually produce. AdiantiAutocompleteService is unused by the app
 * (no TDBEntry) and its hash uses a fixed seed, so it is always denied.
 *
 * check() returns null when allowed, or a reason for the log. The reason
 * never echoes the received value.
 */
final class SearchParameterGuard
{
    public const TENANT_MODELS = ['Tutor', 'Patient', 'Product', 'ProcedureCatalogItem'];
    public const GLOBAL_MODELS = ['SystemUser', 'SystemProgram'];
    public const OPERATORS = ['', 'like', 'ilike', '='];
    public const ID_OPERATORS = ['', '='];

    private const MULTI_SEARCH = 'AdiantiMultiSearchService';
    private const AUTOCOMPLETE = 'AdiantiAutocompleteService';
    private const DATABASE = 'permission';
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const MASK = '/^(<span>)?\{([A-Za-z_][A-Za-z0-9_]*)\}(<\/span>)?$/';
    private const FLAGS = ['idsearch', 'idtextsearch', 'onlyidsearch', 'jsonvalue'];

    private const CRITERIA_CLASS = 'Adianti\\Database\\TCriteria';
    private const FILTER_CLASS = 'Adianti\\Database\\TFilter';
    private const AND_OPERATOR = 'AND ';

    public static function check(string $class, array $request, ?int $tenantId): ?string
    {
        if (strcasecmp($class, self::AUTOCOMPLETE) === 0) {
            return 'AdiantiAutocompleteService is disabled';
        }

        if (strcasecmp($class, self::MULTI_SEARCH) !== 0) {
            return null;
        }

        $param = [];
        foreach (['database', 'model', 'key', 'column', 'orderColumn', 'operator', 'operator_idsearch', 'mask', 'criteria', 'minlength', ...self::FLAGS] as $name) {
            $value = $request[$name] ?? '';
            if (!is_string($value) && !is_int($value)) {
                return "parameter {$name} must be a scalar string";
            }
            $param[$name] = (string) $value;
        }

        if ($param['database'] !== self::DATABASE) {
            return 'database not allowed';
        }

        $model = $param['model'];
        $isTenantModel = in_array($model, self::TENANT_MODELS, true);
        if (!$isTenantModel && !in_array($model, self::GLOBAL_MODELS, true)) {
            return 'model not allowed';
        }

        if (!in_array(strtolower($param['operator']), self::OPERATORS, true)) {
            return 'operator not allowed';
        }

        if (!in_array($param['operator_idsearch'], self::ID_OPERATORS, true)) {
            return 'operator_idsearch not allowed';
        }

        foreach (['key', 'orderColumn'] as $name) {
            if ($param[$name] !== '' && !self::isIdentifier($param[$name])) {
                return "{$name} is not an identifier";
            }
        }

        $columns = explode(',', $param['column']);
        foreach ($columns as $column) {
            if (!self::isIdentifier($column)) {
                return 'column is not a list of identifiers';
            }
        }

        if (preg_match(self::MASK, $param['mask'], $match) !== 1) {
            return 'mask not allowed';
        }
        if ((($match[1] ?? '') === '') !== (($match[3] ?? '') === '')) {
            return 'mask span must wrap both ends or none';
        }
        $maskAttribute = $match[2];
        $allowedAttributes = $columns;
        foreach ($columns as $column) {
            $allowedAttributes[] = $column . '_safe';
        }
        if (!in_array($maskAttribute, $allowedAttributes, true)) {
            return 'mask attribute is not a searched column';
        }

        foreach (self::FLAGS as $name) {
            if (!in_array($param[$name], ['', '0', '1'], true)) {
                return "{$name} must be empty, 0 or 1";
            }
        }

        if ($param['minlength'] !== '' && !ctype_digit($param['minlength'])) {
            return 'minlength must be digits';
        }

        if (!$isTenantModel) {
            return $param['criteria'] === '' ? null : 'global model does not accept criteria';
        }

        if ($tenantId === null) {
            return 'tenant model requires a session tenant';
        }

        return self::checkTenantCriteria($param['criteria'], $tenantId);
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match(self::IDENTIFIER, $value) === 1;
    }

    /**
     * The criteria must be the app's $tenant_criteria: first expression
     * TFilter('tenant_id', '=', $tenantId), optionally followed by
     * AND-joined TFilter(<identifier>, '=', <integer>) (StockBatchForm adds
     * active = 1), with no order/group/direction/offset properties.
     */
    private static function checkTenantCriteria(string $encoded, int $tenantId): ?string
    {
        if ($encoded === '') {
            return 'tenant model requires criteria';
        }

        $criteria = self::decodeObject($encoded);
        if ($criteria === null || ($criteria['__PHP_Incomplete_Class_Name'] ?? null) !== self::CRITERIA_CLASS) {
            return 'criteria is not a TCriteria';
        }

        $expressions = self::property($criteria, self::CRITERIA_CLASS, 'expressions');
        $operators = self::property($criteria, self::CRITERIA_CLASS, 'operators');
        $properties = self::property($criteria, self::CRITERIA_CLASS, 'properties');

        if (!is_array($expressions) || !array_is_list($expressions) || $expressions === []
            || !is_array($operators) || !array_is_list($operators) || count($operators) !== count($expressions)) {
            return 'criteria has an unexpected shape';
        }

        if ($properties !== null) {
            if (!is_array($properties)) {
                return 'criteria has an unexpected shape';
            }
            foreach ($properties as $value) {
                if ($value !== null && $value !== '' && $value !== 0) {
                    return 'criteria must not carry properties';
                }
            }
        }

        foreach ($expressions as $index => $expression) {
            $filter = is_object($expression) ? (array) $expression : null;
            if ($filter === null || ($filter['__PHP_Incomplete_Class_Name'] ?? null) !== self::FILTER_CLASS) {
                return 'criteria expression is not a TFilter';
            }

            $expectedOperator = $index === 0 ? null : self::AND_OPERATOR;
            if ($operators[$index] !== $expectedOperator) {
                return 'criteria expressions must be joined by AND';
            }

            $variable = self::property($filter, self::FILTER_CLASS, 'variable');
            $operator = self::property($filter, self::FILTER_CLASS, 'operator');
            $value = self::property($filter, self::FILTER_CLASS, 'value');
            $value2 = self::property($filter, self::FILTER_CLASS, 'value2');

            if (!is_string($variable) || !self::isIdentifier($variable) || $operator !== '=' || $value2 !== null) {
                return 'criteria filter not allowed';
            }

            if ($index === 0) {
                if ($variable !== 'tenant_id' || !self::isInteger($value) || (int) $value !== $tenantId) {
                    return 'criteria does not restrict to the session tenant';
                }
                continue;
            }

            if ($variable === 'tenant_id' || !self::isInteger($value)) {
                return 'criteria filter not allowed';
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function decodeObject(string $encoded): ?array
    {
        $raw = base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded), true);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $value = @unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }

        return $value instanceof \__PHP_Incomplete_Class ? (array) $value : null;
    }

    /** @param array<string, mixed> $object */
    private static function property(array $object, string $class, string $name): mixed
    {
        return $object["\0{$class}\0{$name}"] ?? null;
    }

    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\SearchParameterGuard;
use CentralVet\Tests\Support\Assert;

/**
 * S7: pre-filter for the Adianti search services, which put request
 * parameters outside the hash (operator, orderColumn, mask, criteria)
 * straight into SQL / rendering.
 *
 * Fixtures were generated in the app container with the Adianti loader:
 * serialize(new TCriteria + TFilter('tenant_id', '=', 7)) with the same
 * transform as TDBMultiSearch.php:343 (base64, '+'->'-', '/'->'_').
 */
final class SearchParameterGuardTest
{
    private const SERVICE = 'AdiantiMultiSearchService';

    private static function fixture(string $name): string
    {
        return trim((string) file_get_contents(dirname(__DIR__) . '/Support/Fixtures/' . $name));
    }

    /** @return array<string, string> */
    private static function tutorRequest(array $overrides = []): array
    {
        return array_merge([
            'class' => self::SERVICE,
            'method' => 'onSearch',
            'static' => '1',
            'database' => 'permission',
            'key' => 'id',
            'column' => 'full_name',
            'model' => 'Tutor',
            'orderColumn' => 'full_name',
            'criteria' => self::fixture('multisearch-tenant-7-criteria.txt'),
            'operator' => '',
            'mask' => '<span>{full_name_safe}</span>',
            'idsearch' => '1',
            'idtextsearch' => '0',
            'minlength' => '1',
            'hash' => 'irrelevant',
            'value' => 'ana',
        ], $overrides);
    }

    /** @return array<string, string> */
    private static function systemUserRequest(array $overrides = []): array
    {
        return array_merge([
            'class' => self::SERVICE,
            'method' => 'onSearch',
            'static' => '1',
            'database' => 'permission',
            'key' => 'id',
            'column' => 'name',
            'model' => 'SystemUser',
            'orderColumn' => 'name',
            'criteria' => '',
            'operator' => '',
            'mask' => '<span>{name_safe}</span>',
            'idsearch' => '1',
            'idtextsearch' => '0',
            'minlength' => '1',
            'value' => 'jo',
        ], $overrides);
    }

    private static function assertDenied(?string $reason, string $message): void
    {
        Assert::notNull($reason, $message);
        Assert::true(is_string($reason) && $reason !== '', $message . ' (reason must be a non-empty string)');
    }

    public function testLegitimateTutorSearchFromSaleFormIsAllowed(): void
    {
        Assert::null(SearchParameterGuard::check(self::SERVICE, self::tutorRequest(), 7));
    }

    public function testLegitimateSystemUserSearchWithoutCriteriaIsAllowed(): void
    {
        Assert::null(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(), 7));
    }

    public function testOperatorInjectionIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['operator' => '= 1 OR 1=1 --']), 7),
            'operator outside the allowlist must be denied'
        );
    }

    public function testIdOperatorInjectionIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['operator_idsearch' => 'IN (SELECT 1)']), 7),
            'operator_idsearch outside the allowlist must be denied'
        );
    }

    public function testOrderColumnSubqueryIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['orderColumn' => '(SELECT password FROM system_users LIMIT 1)']), 7),
            'orderColumn that is not an identifier must be denied'
        );
    }

    public function testMaskExposingPasswordIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['mask' => '{password}']), null),
            'mask naming an attribute outside column must be denied'
        );
    }

    public function testTenantModelWithoutCriteriaIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['criteria' => '']), 7),
            'tenant model without criteria must be denied'
        );
    }

    public function testTenantModelWithCriteriaOfAnotherTenantIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(), 8),
            'criteria for tenant 7 must be denied when the session tenant is 8'
        );
    }

    public function testTenantModelWithoutSessionTenantIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(), null),
            'tenant model must be denied without a session tenant'
        );
    }

    public function testModelOutsideAllowlistIsDenied(): void
    {
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['model' => 'SystemUnit']), 7),
            'model outside the allowlist must be denied'
        );
    }

    public function testAutocompleteServiceIsAlwaysDenied(): void
    {
        self::assertDenied(SearchParameterGuard::check('AdiantiAutocompleteService', [], 7), 'autocomplete with no params');
        self::assertDenied(
            SearchParameterGuard::check('AdiantiAutocompleteService', self::systemUserRequest(['class' => 'AdiantiAutocompleteService']), 7),
            'autocomplete with legitimate-looking params'
        );
    }

    public function testOtherClassesAreNotFiltered(): void
    {
        Assert::null(SearchParameterGuard::check('SaleForm', ['operator' => '= 1 OR 1=1 --'], null));
    }

    public function testReasonDoesNotEchoReceivedValue(): void
    {
        $reason = SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['operator' => 'EVIL_MARKER_42']), 7);
        self::assertDenied($reason, 'operator must be denied');
        Assert::false(str_contains((string) $reason, 'EVIL_MARKER_42'), 'reason must not echo the received value');
    }

    public function testStockBatchCriteriaWithTenantAndActiveFilterIsAllowed(): void
    {
        // StockBatchForm.php:74-76 adds tenant_id = ? AND active = 1.
        $request = self::tutorRequest([
            'model' => 'Product',
            'column' => 'name',
            'orderColumn' => 'name',
            'mask' => '<span>{name_safe}</span>',
            'criteria' => self::fixture('multisearch-tenant-7-active-criteria.txt'),
        ]);

        Assert::null(SearchParameterGuard::check(self::SERVICE, $request, 7));
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, $request, 8), 'tenant+active criteria for another tenant');
    }

    public function testMaskSpanMustWrapBothEndsOrNone(): void
    {
        Assert::null(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['mask' => '{name}']), null));
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['mask' => '<span>{name_safe}']), null),
            'unbalanced span must be denied'
        );
    }

    public function testFlagsAndLengthMustBeSimple(): void
    {
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['idsearch' => '2']), null), 'idsearch');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['minlength' => '1 ']), null), 'minlength');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['database' => 'other']), null), 'database');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['column' => 'name,(select 1)']), null), 'column');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['criteria' => 'x']), null), 'global model with criteria');
    }
}

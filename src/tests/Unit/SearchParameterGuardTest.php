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

    /** Decoded serialized payload of a fixture (inverse of TDBMultiSearch.php:343). */
    private static function rawFixture(string $name): string
    {
        return (string) base64_decode(str_replace(['-', '_'], ['+', '/'], self::fixture($name)), true);
    }

    private static function encode(string $raw): string
    {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($raw));
    }

    /** Replaces the first occurrence of $search, failing loudly if it is absent. */
    private static function replaceOnce(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);
        Assert::true($position !== false, 'fixture does not contain the fragment to tamper with');

        return substr_replace($subject, $replace, (int) $position, strlen($search));
    }

    private static function assertTamperedDenied(string $fixture, string $raw, string $message, int $tenantId = 7): void
    {
        Assert::true($raw !== self::rawFixture($fixture), $message . ' (payload was not tampered)');
        self::assertDenied(
            SearchParameterGuard::check(self::SERVICE, self::tutorRequest(['criteria' => self::encode($raw)]), $tenantId),
            $message
        );
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

    public function testUnmangledPropertyOverridingTenantFilterIsDenied(): void
    {
        // Reviewer payload: the real unserialize maps an unmangled "variable"
        // key onto TFilter::$variable (last one wins), so the SQL became
        // (1=1 OR tenant_id = 7 AND ...).
        $fixture = 'multisearch-tenant-7-criteria.txt';
        $raw = self::replaceOnce('s:9:"tenant_id";', 's:9:"tenant_id";s:8:"variable";s:16:"1=1 OR tenant_id";', self::rawFixture($fixture));
        $raw = self::replaceOnce('"Adianti\\Database\\TFilter":6:', '"Adianti\\Database\\TFilter":7:', $raw);

        self::assertTamperedDenied($fixture, $raw, 'unmangled TFilter key must be denied');
    }

    public function testUnmangledCriteriaPropertyIsDenied(): void
    {
        $fixture = 'multisearch-tenant-7-criteria.txt';
        $raw = self::rawFixture($fixture);
        $raw = self::replaceOnce('"Adianti\\Database\\TCriteria":4:{', '"Adianti\\Database\\TCriteria":5:{', $raw);
        $raw = substr($raw, 0, -1) . 's:10:"properties";a:1:{s:5:"group";s:7:"id; --x";}}';

        self::assertTamperedDenied($fixture, $raw, 'unmangled TCriteria key must be denied');
    }

    public function testDuplicatedMangledKeyIsDenied(): void
    {
        $fixture = 'multisearch-tenant-7-criteria.txt';
        $variableKey = "s:34:\"\0Adianti\\Database\\TFilter\0variable\";";
        $raw = self::replaceOnce('s:9:"tenant_id";', 's:9:"tenant_id";' . $variableKey . 's:9:"tenant_id";', self::rawFixture($fixture));
        $raw = self::replaceOnce('"Adianti\\Database\\TFilter":6:', '"Adianti\\Database\\TFilter":7:', $raw);

        self::assertTamperedDenied($fixture, $raw, 'duplicated key must be denied');
    }

    public function testExtraPublicPropertyIsDenied(): void
    {
        $fixture = 'multisearch-tenant-7-criteria.txt';
        $raw = self::replaceOnce('s:9:"tenant_id";', 's:9:"tenant_id";s:5:"extra";i:1;', self::rawFixture($fixture));
        $raw = self::replaceOnce('"Adianti\\Database\\TFilter":6:', '"Adianti\\Database\\TFilter":7:', $raw);

        self::assertTamperedDenied($fixture, $raw, 'extra public property must be denied');
    }

    public function testClassOutsideCriteriaClassesIsDenied(): void
    {
        $fixture = 'multisearch-tenant-7-criteria.txt';
        $raw = str_replace('Adianti\\Database\\TCriteria', 'Adianti\\Database\\TCritXria', self::rawFixture($fixture));

        self::assertTamperedDenied($fixture, $raw, 'class other than TCriteria must be denied');
    }

    public function testExtraFilterRuleNegativeBranchesAreDenied(): void
    {
        $fixture = 'multisearch-tenant-7-active-criteria.txt';
        $raw = self::rawFixture($fixture);

        self::assertTamperedDenied($fixture, self::replaceOnce('s:4:"AND ";', 's:3:"OR ";', $raw), 'OR between filters');
        self::assertTamperedDenied($fixture, self::replaceOnce('s:6:"active";', 's:9:"tenant_id";', $raw), 'repeated tenant_id');
        self::assertTamperedDenied($fixture, self::replaceOnce('s:6:"active";', 's:6:"act ve";', $raw), 'variable not an identifier');
        self::assertTamperedDenied($fixture, self::replaceOnce('s:6:"active";', "s:7:\"active\n\";", $raw), 'variable with trailing newline');
        self::assertTamperedDenied($fixture, self::replaceOnce('value";i:1;', 'value";s:8:"(SELECT)";', $raw), 'non-integer value');
        self::assertTamperedDenied($fixture, self::replaceOnce('s:5:"order";s:0:"";', 's:5:"order";s:2:"id";', $raw), 'order property');
        self::assertTamperedDenied($fixture, self::replaceOnce('s:5:"group";s:0:"";', 's:5:"group";s:2:"id";', $raw), 'group property');

        // Nested TCriteria in place of the second TFilter.
        $second = strpos($raw, 'i:1;O:24:"Adianti\\Database\\TFilter"');
        $end = strpos($raw, 'b:0;}', (int) $second);
        Assert::true($second !== false && $end !== false, 'fixture shape changed');
        $nested = substr_replace($raw, 'i:1;' . self::rawFixture('multisearch-tenant-7-criteria.txt'), (int) $second, (int) $end + 5 - (int) $second);
        self::assertTamperedDenied($fixture, $nested, 'nested TCriteria');
    }

    public function testIdentifiersRejectTrailingNewlineAndClassIsNormalized(): void
    {
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['orderColumn' => "name\n"]), null), 'orderColumn with newline');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['key' => "id\n"]), null), 'key with newline');
        self::assertDenied(SearchParameterGuard::check(self::SERVICE, self::systemUserRequest(['mask' => "{name}\n"]), null), 'mask with newline');
        self::assertDenied(
            SearchParameterGuard::check('\\' . self::SERVICE, self::systemUserRequest(['operator' => '= 1 OR 1=1 --']), null),
            'leading backslash must not skip the guard'
        );
        self::assertDenied(SearchParameterGuard::check('\\AdiantiAutocompleteService', [], 7), 'autocomplete with leading backslash');
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

final class MutationRequestPolicyTest
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testGetPaymentSaveWithValidTokenIsDenied(): void
    {
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'GET', 'PaymentForm', 'onSave', self::TOKEN, self::TOKEN)
        );
    }

    public function testPostSaveWithoutTokenIsDenied(): void
    {
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'POST', 'SaleForm', 'onSave', null, self::TOKEN)
        );
    }

    public function testPostWithDifferentTokenIsDenied(): void
    {
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'POST', 'SaleForm', 'onSave', 'other-token', self::TOKEN)
        );
    }

    public function testPostWithEmptySessionTokenIsDenied(): void
    {
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'POST', 'SaleForm', 'onSave', '', '')
        );
    }

    public function testPostSaveWithMatchingTokenIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('engine.php', 'POST', 'SaleForm', 'onSave', self::TOKEN, self::TOKEN)
        );
    }

    public function testGetReloadWithoutTokenIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('engine.php', 'GET', 'PatientList', 'onReload', null, self::TOKEN)
        );
    }

    public function testGetShellContextIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('engine.php', 'GET', 'CvShellController', 'onContext', null, self::TOKEN)
        );
    }

    public function testGetCopilotAskWithoutTokenIsDenied(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('AiCopilotPanel', 'onAsk'));
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'GET', 'AiCopilotPanel', 'onAsk', null, self::TOKEN)
        );
    }

    public function testIndexGetDeleteStripsMethod(): void
    {
        Assert::same(
            MutationRequestPolicy::STRIP_METHOD,
            MutationRequestPolicy::decide('index.php', 'GET', 'SystemUserList', 'onDelete', null, self::TOKEN)
        );
    }

    public function testIndexGetReloadIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('index.php', 'GET', 'PatientList', 'onReload', null, self::TOKEN)
        );
    }

    public function testUploaderPostWithoutTokenIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('engine.php', 'POST', 'AdiantiUploaderService', 'show', null, self::TOKEN)
        );
    }

    public function testDecideFromRequestPostDeleteWithHeaderTokenIsAllowed(): void
    {
        $server = [
            'SCRIPT_NAME' => '/engine.php',
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_CSRF_TOKEN' => self::TOKEN,
        ];
        $request = ['class' => 'PatientList', 'method' => 'Delete'];

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, [], [], self::resolver())
        );
    }

    public function testIndexLogoutIsAllowed(): void
    {
        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('index.php', 'GET', 'LoginForm', 'onLogout', null, self::TOKEN)
        );
    }

    public function testDecideFromRequestUploaderPostWithoutHeaderIsAllowed(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST'];
        $request = ['class' => 'AdiantiUploaderService', 'method' => 'show'];

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, [], [], self::resolver())
        );
    }

    public function testDecideFromRequestNonStringMethodIsTreatedAsEmpty(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];
        $request = ['class' => 'PatientList', 'method' => ['onDelete']];

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, [], [], self::resolver())
        );
    }

    public function testDecideFromRequestPostDeleteWithoutHeaderIsDenied(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST'];
        $request = ['class' => 'PatientList', 'method' => 'onDelete'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, [], [], self::resolver())
        );
    }

    public function testOnCloseIsNotReadOnly(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('CashSessionForm', 'onClose'));
        Assert::true(MutationRequestPolicy::isReadOnly('PatientList', ''));
        Assert::true(MutationRequestPolicy::isReadOnly('PatientList', 'onSearchByName'));
    }

    public function testConstantsMatchContract(): void
    {
        Assert::same('cv_shell_csrf', MutationRequestPolicy::SESSION_KEY);
        Assert::same('cv_csrf', MutationRequestPolicy::COOKIE_NAME);
        Assert::same('X-CSRF-Token', MutationRequestPolicy::HEADER_NAME);
        Assert::same('HTTP_X_CSRF_TOKEN', MutationRequestPolicy::SERVER_HEADER_KEY);
        Assert::same('strip_method', MutationRequestPolicy::STRIP_METHOD);
    }

    /**
     * Fake of the Reflection resolver: canonical names of the classes and
     * methods that "exist", matched case-insensitively like PHP does.
     */
    private static function resolver(): \Closure
    {
        $classes = [
            'PatientList' => ['onReload', 'onDelete', 'Delete'],
            'AdiantiUploaderService' => ['show'],
            'SaleForm' => ['onSave', 'onReload', 'onClearCart'],
            'CashSessionForm' => ['onOpen', 'onReload'],
            'LoginForm' => ['onLogout'],
        ];

        return static function (string $class, string $method) use ($classes): ?array {
            foreach ($classes as $name => $methods) {
                if (strcasecmp($name, $class) !== 0) {
                    continue;
                }
                if ($method === '') {
                    return [$name, ''];
                }
                foreach ($methods as $m) {
                    if (strcasecmp($m, $method) === 0) {
                        return [$name, $m];
                    }
                }
                return null;
            }
            return null;
        };
    }

    public function testMethodCaseVariantOfOverrideIsNotReadOnly(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('SaleForm', 'onClearcart'));
        Assert::false(MutationRequestPolicy::isReadOnly('SaleForm', 'onclearCart'));
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'GET', 'SaleForm', 'onClearcart', null, self::TOKEN)
        );
        Assert::same(
            MutationRequestPolicy::STRIP_METHOD,
            MutationRequestPolicy::decide('index.php', 'GET', 'SaleForm', 'onclearCart', null, self::TOKEN)
        );
    }

    public function testClassCaseVariantOfOverrideIsNotReadOnly(): void
    {
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'GET', 'cashsessionform', 'onOpen', null, self::TOKEN)
        );
    }

    public function testDecideFromRequestDeniesNonCanonicalCase(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];

        foreach ([['SaleForm', 'onClearcart'], ['cashsessionform', 'onOpen'], ['patientlist', 'onReload'], ['LoginForm', 'onlogout']] as [$class, $method]) {
            $request = ['class' => $class, 'method' => $method];
            Assert::same(
                MutationRequestPolicy::DENY,
                MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $request, [], self::resolver()),
                "{$class}::{$method} must be denied"
            );
        }
    }

    public function testDecideFromRequestDeniesUnknownClassOrMethod(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];

        foreach ([['NoSuchClass', 'onReload'], ['PatientList', 'onReloadMissing'], ['NoSuchClass', '']] as [$class, $method]) {
            $request = ['class' => $class, 'method' => $method];
            Assert::same(
                MutationRequestPolicy::DENY,
                MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $request, [], self::resolver()),
                "{$class}::{$method} must be denied"
            );
        }
    }

    public function testDecideFromRequestDeniesBodyMethodDivergingFromQuery(): void
    {
        // $_REQUEST (EGPCS) takes the body, but the page dispatch runs $_GET.
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST'];
        $query = ['class' => 'SaleForm', 'method' => 'onSave'];
        $body = ['method' => 'onReload'];
        $request = ['class' => 'SaleForm', 'method' => 'onReload'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $query, $body, self::resolver())
        );
    }

    public function testDecideFromRequestDeniesBodyClassDivergingFromQuery(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST'];
        $query = ['class' => 'SaleForm', 'method' => 'onReload'];
        $body = ['class' => 'PatientList'];
        $request = ['class' => 'PatientList', 'method' => 'onReload'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $query, $body, self::resolver())
        );
    }

    public function testDecideFromRequestDeniesRequestMethodFromCookieOrBodyAbsentInQuery(): void
    {
        // method only in $_REQUEST (cookie/body) while the query names another one.
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];
        $query = ['class' => 'SaleForm', 'method' => 'onSave'];
        $request = ['class' => 'SaleForm', 'method' => 'onReload'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $query, [], self::resolver())
        );
    }

    public function testDecideFromRequestAllowsMatchingQueryAndBody(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => self::TOKEN];
        $query = ['class' => 'SaleForm', 'method' => 'onSave'];
        $request = ['class' => 'SaleForm', 'method' => 'onSave', 'x' => '1'];

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $query, ['class' => 'SaleForm'], self::resolver())
        );
    }

    public function testSqlPanelGenerateRequiresToken(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('SystemSQLPanel', 'onGenerate'));
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decide('engine.php', 'POST', 'SystemSQLPanel', 'onGenerate', null, self::TOKEN)
        );
    }

    public function testRawSqlAdminToolsRequireToken(): void
    {
        // Build SQL from request params (table, filter column): stacked queries possible.
        foreach (['SystemDataBrowser::onLoad', 'SystemTableList::onExportCSV', 'SystemTableList::onExportSQL', 'SystemSQLPanel::onExportCSV'] as $action) {
            [$class, $method] = explode('::', $action);
            Assert::same(
                MutationRequestPolicy::DENY,
                MutationRequestPolicy::decide('engine.php', 'POST', $class, $method, null, self::TOKEN),
                "{$action} must require POST + token"
            );
        }
    }

    public function testSqlPanelExportCsvRequiresTokenThroughRequest(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];
        $request = ['class' => 'SystemSQLPanel', 'method' => 'onExportCSV', 'static' => '1'];
        $resolver = static fn (string $class, string $method): array => [$class, $method];

        Assert::false(MutationRequestPolicy::isReadOnly('SystemSQLPanel', 'onExportCSV'));
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $request, [], $resolver)
        );
    }

    public function testRealResolverReturnsCanonicalNamesOrNull(): void
    {
        Assert::same(
            ['CentralVet\\Security\\CsrfToken', 'isValid'],
            MutationRequestPolicy::resolveCanonical('centralvet\\security\\csrftoken', 'ISVALID')
        );
        Assert::same(
            ['CentralVet\\Security\\CsrfToken', ''],
            MutationRequestPolicy::resolveCanonical('CentralVet\\Security\\CsrfToken', '')
        );
        Assert::same(null, MutationRequestPolicy::resolveCanonical('CentralVet\\Security\\CsrfToken', 'noSuchMethod'));
        Assert::same(null, MutationRequestPolicy::resolveCanonical('NoSuchClassAnywhere', 'onReload'));
    }

    public function testRealResolverDeniesWrongCaseThroughRequest(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];
        $request = ['class' => 'centralvet\\security\\csrftoken', 'method' => 'isValid'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN, $request, [])
        );
    }

    public function testAddedOverridesAndExemptions(): void
    {
        foreach (['CashSessionForm::onOpen', 'SystemMessageFormView::onView', 'SaleForm::onClearCart', 'PrescriptionForm::onClear'] as $action) {
            [$class, $method] = explode('::', $action);
            Assert::same(
                MutationRequestPolicy::DENY,
                MutationRequestPolicy::decide('engine.php', 'GET', $class, $method, null, self::TOKEN),
                "{$action} must require POST + token"
            );
        }

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decide('index.php', 'GET', 'LoginForm', 'reloadPermissions', null, self::TOKEN)
        );
    }
}

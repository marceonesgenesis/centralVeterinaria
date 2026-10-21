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
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN)
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
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN)
        );
    }

    public function testDecideFromRequestNonStringMethodIsTreatedAsEmpty(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'GET'];
        $request = ['class' => 'PatientList', 'method' => ['onDelete']];

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN)
        );
    }

    public function testDecideFromRequestPostDeleteWithoutHeaderIsDenied(): void
    {
        $server = ['SCRIPT_NAME' => '/engine.php', 'REQUEST_METHOD' => 'POST'];
        $request = ['class' => 'PatientList', 'method' => 'onDelete'];

        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, self::TOKEN)
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
}

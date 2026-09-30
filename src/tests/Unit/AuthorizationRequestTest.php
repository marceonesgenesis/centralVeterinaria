<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use InvalidArgumentException;

final class AuthorizationRequestTest
{
    public function testAcceptsClassMethodAndDottedKeyActions(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $valid = [
            'EncounterAccountForm::onApplyDiscount',
            'CentralVet\\Application\\X::run',
            'test::encounter_account',
            'patient.view',
            'schedule.manage',
        ];

        foreach ($valid as $action) {
            $request = new AuthorizationRequest($context, $action);
            Assert::same($action, $request->action());
        }
    }

    public function testRejectsMalformedActions(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $invalid = [
            '',
            ' ',
            'Classe::',
            '::metodo',
            'Classe::metodo:extra',
            'Classe::metodo; DROP',
            "Classe::metodo\n",
        ];

        foreach ($invalid as $action) {
            Assert::throws(
                InvalidArgumentException::class,
                static fn () => new AuthorizationRequest($context, $action),
                sprintf('Expected InvalidArgumentException for action %s', json_encode($action)),
            );
        }
    }

    public function testMalformedActionMessageIsJsonEscaped(): void
    {
        $context = TenantContext::authenticated(101, 1, 5);
        $message = null;

        try {
            new AuthorizationRequest(context: $context, action: "bad\naction");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Invalid authorization action format: "bad\\naction"', $message);
        Assert::false(str_contains((string) $message, "\n"), 'Message must not contain a raw line break');
    }
}

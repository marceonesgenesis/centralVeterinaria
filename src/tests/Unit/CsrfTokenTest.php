<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\CsrfToken;
use CentralVet\Tests\Support\Assert;

final class CsrfTokenTest
{
    public function testPostWithMatchingTokenIsValid(): void
    {
        $token = CsrfToken::generate();

        Assert::true(CsrfToken::isValid('POST', $token, $token));
        Assert::true(CsrfToken::isValid('post', $token, $token), 'Request method comparison must be case-insensitive');
    }

    public function testGetIsRejectedEvenWithMatchingToken(): void
    {
        $token = CsrfToken::generate();

        Assert::false(CsrfToken::isValid('GET', $token, $token));
    }

    public function testWrongMissingOrEmptySentTokenIsRejected(): void
    {
        $token = CsrfToken::generate();

        Assert::false(CsrfToken::isValid('POST', 'x', $token));
        Assert::false(CsrfToken::isValid('POST', null, $token));
        Assert::false(CsrfToken::isValid('POST', '', $token));
        Assert::false(CsrfToken::isValid('POST', ['x'], $token));
    }

    public function testMissingExpectedTokenIsRejected(): void
    {
        $token = CsrfToken::generate();

        Assert::false(CsrfToken::isValid('POST', $token, null));
        Assert::false(CsrfToken::isValid('POST', '', ''));
    }

    public function testGenerateReturns64HexCharsAndDiffersBetweenCalls(): void
    {
        $first = CsrfToken::generate();
        $second = CsrfToken::generate();

        Assert::same(1, preg_match('/^[0-9a-f]{64}$/', $first));
        Assert::same(1, preg_match('/^[0-9a-f]{64}$/', $second));
        Assert::false($first === $second, 'Two generated tokens must differ');
    }
}

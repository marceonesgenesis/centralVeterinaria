<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LandingPage;
use CentralVet\Tests\Support\Assert;

/**
 * T-03 (ajustes de deploy): só `GET` emite o token do formulário; `HEAD /`
 * responde os mesmos headers sem gravar `centralvet:lead-token:*` no Redis.
 */
final class LandingPageHeadTest
{
    public function testIssuesTokenForGetInAnyCase(): void
    {
        $page = new LandingPage();

        Assert::same(true, $page->issuesToken('GET'));
        Assert::same(true, $page->issuesToken('get'));
    }

    public function testDoesNotIssueTokenForHeadOrPost(): void
    {
        $page = new LandingPage();

        Assert::same(false, $page->issuesToken('HEAD'));
        Assert::same(false, $page->issuesToken('head'));
        Assert::same(false, $page->issuesToken('POST'));
    }
}

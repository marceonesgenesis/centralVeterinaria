<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LandingPage;
use CentralVet\Tests\Support\Assert;

/**
 * Landing pública, T-06: decisão landing/sistema em `/` e montagem do
 * template (token e catálogo), sem código inline e sem preço fora do catálogo.
 */
final class LandingPageTest
{
    private const TEMPLATE = 'app/view/landing/landing.html';
    private const SCRIPT = 'landing/landing.js';

    public function testEntryForAnonymousGetAndHeadIsLanding(): void
    {
        $page = new LandingPage();

        Assert::same('landing', $page->entryFor('GET', '', false));
        Assert::same('landing', $page->entryFor('HEAD', '', false));
    }

    public function testEntryForSessionOrQueryIsSystem(): void
    {
        $page = new LandingPage();

        Assert::same('system', $page->entryFor('GET', '', true));
        Assert::same('system', $page->entryFor('GET', 'class=LoginForm', false));
    }

    public function testEntryForOtherMethodIsNotAllowed(): void
    {
        Assert::same('method_not_allowed', (new LandingPage())->entryFor('POST', '', false));
    }

    public function testRenderEscapesTokenAndInjectsCatalog(): void
    {
        $html = (new LandingPage())->render($this->read(self::TEMPLATE), 't"<');

        Assert::stringContains('content="t&quot;&lt;"', $html);
        Assert::stringContains('"price_cents":9700', $html);
        Assert::false(str_contains($html, '{{'), 'rendered page must not keep {{ markers');
    }

    public function testTemplateHasNoInlineCodeAndHasMarkers(): void
    {
        $template = $this->read(self::TEMPLATE);

        Assert::false(str_contains($template, '<style'), 'template must not have <style>');
        Assert::false(str_contains($template, ' style='), 'template must not have style=');
        Assert::same(0, preg_match('/\son[a-z]+\s*=/i', $template), 'template must not have on*= handlers');

        preg_match_all('/<script\b([^>]*)>/i', $template, $scripts);
        foreach ($scripts[1] as $attributes) {
            $external = (bool) preg_match('/\ssrc=/i', $attributes);
            $json = str_contains($attributes, 'type="application/json"');
            Assert::true($external || $json, "inline executable <script{$attributes}> is not allowed");
        }

        foreach ([
            '<meta name="cv-lead-token" content="{{LEAD_TOKEN}}">',
            '<script type="application/json" id="cv-landing-data">{{LANDING_DATA}}</script>',
            '<link rel="stylesheet" href="/landing/landing.css">',
            '<script src="/landing/landing.js" defer></script>',
            '<a href="/index.php?class=LoginForm">Entrar</a>',
        ] as $marker) {
            Assert::stringContains($marker, $template);
        }
    }

    public function testScriptHasNoDraftStoreNorHardcodedPrice(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::false(str_contains($script, 'claude.'), 'landing.js must not use claude.*');
        Assert::false(str_contains($script, '9700'), 'landing.js must not hardcode 9700');
        Assert::false(str_contains($script, 'R$ 97'), 'landing.js must not hardcode R$ 97');
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }
}

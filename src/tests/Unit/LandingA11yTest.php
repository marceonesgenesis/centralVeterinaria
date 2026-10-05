<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Landing pública, a11y do drawer do carrinho e do formulário de lead.
 * Não há harness de JS no projeto: os marcadores estáticos garantem o
 * contrato (inert, aria-invalid/aria-describedby, foco após 201) e o
 * comportamento no navegador é provado pelo Playwright MCP no gate.
 */
final class LandingA11yTest
{
    private const TEMPLATE = 'app/view/landing/landing.html';
    private const SCRIPT = 'landing/landing.js';
    private const STYLE = 'landing/landing.css';

    public function testCartStartsInertWithoutAriaHidden(): void
    {
        $template = $this->read(self::TEMPLATE);

        Assert::stringContains(
            '<aside class="drawer" id="cart" role="dialog" aria-modal="true" aria-labelledby="cart-title" inert>',
            $template,
        );
        Assert::same(0, preg_match('/<aside[^>]*id="cart"[^>]*aria-hidden/', $template), '#cart must not have aria-hidden');
    }

    public function testTemplateUsesA11yCacheBusters(): void
    {
        $template = $this->read(self::TEMPLATE);

        foreach ([
            'landing/landing.css?v=20261005-a11y',
            'landing/translations.js?v=20261005-a11y',
            'landing/landing.js?v=20261005-a11y',
        ] as $marker) {
            Assert::stringContains($marker, $template);
        }
    }

    public function testScriptTogglesInertAndRestoresFocus(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::stringContains('.inert = ', $script);
        Assert::stringContains('header.top', $script);
        Assert::stringContains('$("inicio")', $script);
        Assert::stringContains('body > footer', $script);
        Assert::stringContains('isConnected', $script);
        Assert::stringContains('$("open-cart")', $script);
        Assert::false(str_contains($script, 'aria-hidden", "'), 'landing.js must not toggle aria-hidden on #cart');
    }

    public function testScriptWiresFieldErrors(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::stringContains('aria-describedby="\' + id + \'-err"', $script);
        Assert::stringContains('aria-describedby="consent-err"', $script);
        Assert::stringContains('setAttribute("aria-invalid", "true")', $script);
        Assert::stringContains('removeAttribute("aria-invalid")', $script);
    }

    public function testScriptFocusesDoneTitleAfterSuccess(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::stringContains('<h3 id="lead-done-title" tabindex="-1">Pedido recebido</h3>', $script);
        Assert::stringContains('$("lead-done-title").focus()', $script);
    }

    public function testStyleHidesClosedDrawerAndMarksInvalidFields(): void
    {
        $style = $this->read(self::STYLE);

        Assert::stringContains('visibility: hidden', $style);
        Assert::stringContains('visibility 0s linear .25s', $style);
        Assert::stringContains('visibility: visible', $style);
        Assert::stringContains('.field [aria-invalid="true"]', $style);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }
}

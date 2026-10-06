<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Fase 7B, T-25: os controles globais do cabeçalho (Alternar menu,
 * Notificações e Ajuda: `.cv-topbar-toggle` e `.cv-topbar-icon` de
 * layout.html) são alvos de toque de pelo menos 44 px (--cv-touch-target).
 *
 * Lê as folhas na ordem do layout (custom.css e depois cv-components.css) e
 * confere a última largura/altura declarada para cada seletor, inclusive
 * dentro de @media.
 */
final class TopbarTouchTargetTest
{
    private const TEMPLATE = '/app/templates/adminbs5/';

    public function testTouchTargetTokenIsAtLeast44Px(): void
    {
        $css = $this->stylesheets();

        Assert::true(preg_match('/--cv-touch-target:\s*([0-9.]+)rem\s*;/', $css, $m) === 1, '--cv-touch-target must be declared in rem');
        Assert::true((float) $m[1] * 16 >= 44, '--cv-touch-target must be at least 44 px, got ' . $m[1] . 'rem');
    }

    public function testTopbarControlsAreTouchTargets(): void
    {
        foreach (['.cv-topbar-toggle', '.cv-topbar-icon'] as $selector) {
            $final = $this->finalDeclarations($selector);

            foreach (['width', 'height'] as $property) {
                Assert::same(
                    'var(--cv-touch-target)',
                    $final[$property] ?? null,
                    "{$selector} {$property} must end as var(--cv-touch-target)"
                );
            }
        }
    }

    public function testLayoutKeepsTheThreeHeaderControls(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . self::TEMPLATE . 'layout.html');

        Assert::true(preg_match('/id="sidebar-toggle"[^>]*cv-topbar-toggle|cv-topbar-toggle[^>]*id="sidebar-toggle"/', $layout) === 1, 'menu toggle uses cv-topbar-toggle');
        Assert::same(2, preg_match_all('/<button[^>]*class="[^"]*\bcv-topbar-icon\b/', $layout), 'notifications and help use cv-topbar-icon');
    }

    /** @return array<string, string> última declaração de cada propriedade para o seletor */
    private function finalDeclarations(string $selector): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->stylesheets(), $rules, PREG_SET_ORDER);

        $final = [];
        foreach ($rules as $rule) {
            $selectors = array_map('trim', explode(',', $rule[1]));
            $selectors = array_map(static fn (string $s): string => trim((string) preg_replace('#/\*.*?\*/#s', '', $s)), $selectors);

            if (!in_array($selector, $selectors, true)) {
                continue;
            }

            preg_match_all('/([a-z-]+)\s*:\s*([^;]+);/', (string) preg_replace('#/\*.*?\*/#s', '', $rule[2]), $declarations, PREG_SET_ORDER);
            foreach ($declarations as $declaration) {
                $final[$declaration[1]] = trim($declaration[2]);
            }
        }

        return $final;
    }

    private function stylesheets(): string
    {
        $dir = dirname(__DIR__, 2) . self::TEMPLATE;

        return (string) file_get_contents($dir . 'custom.css') . "\n" . (string) file_get_contents($dir . 'cv-components.css');
    }
}

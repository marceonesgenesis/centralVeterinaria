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
            '<link rel="stylesheet" href="landing/landing.css?v=20261002-i18n">',
            '<script src="landing/landing.js?v=20261002-cart" defer></script>',
            '<a href="index.php?class=LoginForm">Entrar</a>',
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

    /**
     * A landing só lê a sessão: com cookie forjado/expirado (id inexistente)
     * o handler não recebe write/destroy, e sem cookie nem é tocado. Roda em
     * subprocesso porque o runner já imprimiu saída (session_start recusaria).
     */
    public function testReadLoggedNeverWritesTheSession(): void
    {
        Assert::true(method_exists(LandingPage::class, 'readLogged'), 'LandingPage::readLogged must exist');

        $script = <<<'PHP'
            require $argv[1];
            ini_set('session.serialize_handler', 'php');
            $handler = new class implements SessionHandlerInterface {
                public array $calls = [];
                public function open(string $path, string $name): bool { $this->calls[] = 'open'; return true; }
                public function close(): bool { $this->calls[] = 'close'; return true; }
                public function read(string $id): string|false {
                    $this->calls[] = 'read:' . $id;
                    return $id === 'validsession1' ? 'centralvet|a:1:{s:6:"logged";b:1;}' : '';
                }
                public function write(string $id, string $data): bool { $this->calls[] = 'write:' . $id; return true; }
                public function destroy(string $id): bool { $this->calls[] = 'destroy:' . $id; return true; }
                public function gc(int $max): int|false { return 0; }
            };
            $page = new CentralVet\Landing\LandingPage();
            $out = [];
            foreach (['none' => [], 'forged' => ['PHPSESSID_centralvet' => 'deadbeef'], 'valid' => ['PHPSESSID_centralvet' => 'validsession1']] as $case => $cookies) {
                $handler->calls = [];
                $logged = $page->readLogged($handler, 'PHPSESSID_centralvet', $cookies, 'centralvet');
                $out[$case] = ['logged' => $logged, 'calls' => $handler->calls, 'status' => session_status()];
            }
            echo json_encode($out);
            PHP;

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' ' . escapeshellarg($autoload) . ' 2>&1';
        $output = (string) shell_exec($command);
        $result = json_decode($output, true);
        Assert::true(is_array($result), 'subprocess output must be JSON: ' . $output);

        Assert::same(['logged' => false, 'calls' => [], 'status' => PHP_SESSION_NONE], $result['none'], 'no cookie: handler untouched');
        Assert::false($result['forged']['logged'], 'forged cookie is anonymous');
        Assert::true($result['valid']['logged'], 'valid session with logged=true is logged');

        foreach (['forged', 'valid'] as $case) {
            Assert::same(PHP_SESSION_NONE, $result[$case]['status'], "{$case}: session must be closed after read");
            Assert::same([], array_values(array_filter(
                $result[$case]['calls'],
                static fn (string $call): bool => str_starts_with($call, 'write:') || str_starts_with($call, 'destroy:'),
            )), "{$case}: handler must never write or destroy");
        }
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }
}

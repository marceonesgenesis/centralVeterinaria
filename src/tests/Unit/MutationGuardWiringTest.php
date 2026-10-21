<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-06 (S2/S7): o guard central de mutação roda em checkMultiSession(), que
 * engine.php e index.php chamam antes do despacho, e cv-shell.js envia as
 * requisições de engine.php como POST com X-CSRF-Token. Lê o fonte: o serviço
 * depende da sessão do Adianti e do exit, que o teste unitário não sobe.
 */
final class MutationGuardWiringTest
{
    public function testCheckMultiSessionCallsTheGuardAfterTheLoggedCheck(): void
    {
        $body = $this->checkMultiSessionBody();

        $logged = strpos($body, "if (!TSession::getValue('logged'))");
        $guard = strpos($body, 'self::enforceMutationGuard();');
        $concurrent = strpos($body, 'concurrent_sessions');

        Assert::true($logged !== false, 'checkMultiSession não confere logged');
        Assert::true($guard !== false, 'checkMultiSession não chama enforceMutationGuard()');
        Assert::true($concurrent !== false, 'checkMultiSession não lê concurrent_sessions');
        Assert::true($logged < $guard && $guard < $concurrent, 'enforceMutationGuard() fora da posição entre logged e concurrent_sessions');
    }

    public function testGuardUsesThePolicyTheSearchGuardAndAnswers403(): void
    {
        $source = $this->service();

        Assert::stringContains('public static function enforceMutationGuard(): void', $source);
        Assert::stringContains('MutationRequestPolicy::decideFromRequest(', $source);
        Assert::stringContains('SearchParameterGuard::check(', $source);
        Assert::stringContains('http_response_code(403)', $source);
        Assert::stringContains("_t('The request could not be verified. Reload the page and try again.')", $source);
        Assert::stringContains('MutationRequestPolicy::COOKIE_NAME', $source);
        Assert::stringContains('MutationRequestPolicy::STRIP_METHOD', $source);
    }

    public function testShellSendsTheTokenHeaderOnEngineRequests(): void
    {
        $source = $this->shell();

        Assert::stringContains('ajaxPrefilter', $source);
        Assert::stringContains('X-CSRF-Token', $source);
        Assert::stringContains('cv_csrf', $source);
    }

    public function testSwitchUnitFetchSendsTheTokenHeader(): void
    {
        $source = $this->shell();
        $start = strpos($source, 'fetch(SWITCH_URL');

        Assert::true($start !== false, 'fetch de SWITCH_URL não encontrado');
        $call = substr($source, $start, (int) strpos($source, '})', $start) - $start);
        Assert::stringContains('X-CSRF-Token', $call, 'fetch de SWITCH_URL sem X-CSRF-Token');
    }

    private function checkMultiSessionBody(): string
    {
        $source = $this->service();
        $start = strpos($source, 'public static function checkMultiSession()');
        Assert::true($start !== false, 'checkMultiSession() não encontrado');

        $next = strpos($source, "\n    public static function ", $start + 1);
        $nextPrivate = strpos($source, "\n    private static function ", $start + 1);
        $ends = array_filter([$next, $nextPrivate], static fn ($v) => $v !== false);
        $end = $ends === [] ? strlen($source) : min($ends);

        return substr($source, $start, $end - $start);
    }

    private function service(): string
    {
        return $this->source(dirname(__DIR__, 2) . '/app/service/auth/ApplicationAuthenticationService.php');
    }

    private function shell(): string
    {
        return $this->source(dirname(__DIR__, 2) . '/app/templates/adminbs5/js/cv-shell.js');
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        Assert::true(is_string($source), 'não foi possível ler ' . $path);

        return (string) $source;
    }
}

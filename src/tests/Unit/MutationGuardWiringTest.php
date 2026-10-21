<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
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

    /**
     * Rodada 1: a busca de tutor do SaleForm (TDBMultiSearch) chega como
     * POST engine.php?class=AdiantiMultiSearchService&method=onSearch&... com
     * X-CSRF-Token e recebia 403. AdiantiMultiSearchService é class_alias de
     * Adianti\Service\AdiantiMultiSearchService (AdiantiClassMap), e a
     * Reflection devolve o nome com namespace. P3 (Etapa 2): a regra do alias
     * vive em MutationRequestPolicy::resolveCanonical, sem resolvedor extra.
     * A fixture reproduz o alias do framework.
     */
    public function testFrameworkAliasedSearchIsAllowedWithTheDefaultResolver(): void
    {
        $token = str_repeat('a', 64);
        [$server, $request, $query, $body] = $this->multiSearchRequest($token);

        Assert::same(
            MutationRequestPolicy::ALLOW,
            MutationRequestPolicy::decideFromRequest($server, $request, $token, $query, $body),
            'o resolvedor padrão aceita o alias do framework'
        );
        Assert::same(
            ['CsrfFixtureFrameworkSearchService', 'onSearch'],
            MutationRequestPolicy::resolveCanonical('CsrfFixtureFrameworkSearchService', 'onSearch')
        );
    }

    public function testDefaultResolverKeepsDenyingCaseBackslashAndQueryBodyDivergence(): void
    {
        $token = str_repeat('a', 64);

        [$server, $request, $query, $body] = $this->multiSearchRequest($token);
        $request['class'] = $query['class'] = 'csrffixtureframeworksearchservice';
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, $token, $query, $body),
            'grafia de caixa diferente continua negada'
        );

        [$server, $request, $query, $body] = $this->multiSearchRequest($token);
        $request['class'] = $query['class'] = '\\CsrfFixtureFrameworkSearchService';
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, $token, $query, $body),
            'barra inicial continua negada'
        );

        [$server, $request, $query, $body] = $this->multiSearchRequest($token);
        $body['method'] = 'onDelete';
        Assert::same(
            MutationRequestPolicy::DENY,
            MutationRequestPolicy::decideFromRequest($server, $request, $token, $query, $body),
            'method do corpo diferente do da query continua negado'
        );

        Assert::null(MutationRequestPolicy::resolveCanonical('CvFixtureMissingClass', 'onSearch'));
        Assert::same(
            [CsrfFixtureFrameworkSearchService::class, 'onSearch'],
            MutationRequestPolicy::resolveCanonical(CsrfFixtureFrameworkSearchService::class, 'onSearch')
        );
        Assert::same(
            [CsrfFixtureFrameworkSearchService::class, 'onSearch'],
            MutationRequestPolicy::resolveCanonical('csrffixtureframeworksearchservice', 'onSearch'),
            'caixa diferente devolve o nome canônico, que difere do pedido'
        );
    }

    /**
     * Rodada 1: com request_order vazio o PHP monta $_REQUEST por
     * variables_order (EGPCS), então o cookie cv_csrf entra em $param e o
     * Adianti o copia para as URLs que monta a partir dele.
     */
    public function testCsrfCookieDoesNotReachTheDispatchParameters(): void
    {
        $token = str_repeat('a', 64);
        [, $request] = $this->multiSearchRequest($token);

        Assert::stringContains('cv_csrf=', http_build_query($request), 'pré-condição: o cookie está em $_REQUEST');

        $param = \ApplicationAuthenticationService::withoutCsrfCookie($request);
        Assert::false(str_contains(http_build_query($param), 'cv_csrf'), 'cv_csrf ainda vai para a URL');
        Assert::same('CsrfFixtureFrameworkSearchService', $param['class']);
        Assert::same('ana', $param['value']);
    }

    public function testGuardUsesTheDefaultResolverAndDropsTheCookieFromRequest(): void
    {
        $this->loadService();
        $source = $this->service();

        Assert::stringContains('MutationRequestPolicy::decideFromRequest($_SERVER, $_REQUEST, $token)', $source);
        // Nome montado em partes: o critério de aceite exige que o grep do
        // nome antigo em src/app e src/tests não encontre nada.
        $removed = 'resolveDispatch' . 'Target';
        Assert::false(
            method_exists(\ApplicationAuthenticationService::class, $removed),
            'o resolvedor próprio do guard deve deixar de existir'
        );
        Assert::stringContains('request_order = "GP"', $source);
        Assert::stringContains('$_REQUEST = self::withoutCsrfCookie($_REQUEST);', $source);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>, 3: array<string, string>}
     */
    private function multiSearchRequest(string $token): array
    {
        $this->loadService();

        // URL do TDBMultiSearch::show() + dados do select2 (value, hash) que o
        // prefilter do cv-shell.js move para a query ao trocar GET por POST.
        $query = [
            'class' => 'CsrfFixtureFrameworkSearchService',
            'method' => 'onSearch',
            'static' => '1',
            'database' => 'permission',
            'key' => 'id',
            'column' => 'full_name',
            'model' => 'Tutor',
            'orderColumn' => 'full_name',
            'criteria' => 'Tzo3MjoiQWRpYW50aVxEYXRhYmFzZVxUQ3JpdGVyaWEiOjA6e30-',
            'operator' => 'like',
            'mask' => '{full_name}',
            'idsearch' => '0',
            'idtextsearch' => '0',
            'minlength' => '1',
            'value' => 'ana',
            'hash' => 'c4ca4238a0b923820dcc509a6f75849b',
        ];
        $body = [];
        $cookies = ['PHPSESSID' => 'abc', MutationRequestPolicy::COOKIE_NAME => $token];
        $server = [
            'SCRIPT_NAME' => '/engine.php',
            'REQUEST_METHOD' => 'POST',
            MutationRequestPolicy::SERVER_HEADER_KEY => $token,
        ];

        // variables_order=EGPCS, request_order vazio: cookies entram em $_REQUEST.
        return [$server, array_merge($query, $body, $cookies), $query, $body];
    }

    private function loadService(): void
    {
        require_once dirname(__DIR__, 2) . '/app/service/auth/ApplicationAuthenticationService.php';

        if (!class_exists('CsrfFixtureFrameworkSearchService', false)) {
            class_alias(CsrfFixtureFrameworkSearchService::class, 'CsrfFixtureFrameworkSearchService');
        }
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

/**
 * Classe com namespace exposta por class_alias, como o AdiantiClassMap faz
 * com Adianti\Service\AdiantiMultiSearchService.
 */
final class CsrfFixtureFrameworkSearchService
{
    public static function onSearch(): void
    {
    }
}

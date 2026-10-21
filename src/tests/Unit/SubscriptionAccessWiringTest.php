<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\AccessDecision;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-19: o bloqueio por assinatura e plano roda em
 * checkMultiSession() logo depois do guard de mutação (engine.php e
 * index.php, protegidos por hash, chamam checkMultiSession antes do
 * despacho), e o primeiro login ativa o vínculo convidado. A fiação lê o
 * fonte, como o MutationGuardWiringTest; o cache da decisão é testado pelas
 * funções puras.
 */
final class SubscriptionAccessWiringTest
{
    public function testCheckMultiSessionCallsTheSubscriptionGuardRightAfterTheMutationGuard(): void
    {
        $body = $this->checkMultiSessionBody();

        $logged = strpos($body, "if (!TSession::getValue('logged'))");
        $mutation = strpos($body, 'self::enforceMutationGuard();');
        $subscription = strpos($body, 'self::enforceSubscriptionAccess();');
        $concurrent = strpos($body, 'concurrent_sessions');

        Assert::true($logged !== false && $mutation !== false && $concurrent !== false, 'checkMultiSession mudou de forma');
        Assert::true($subscription !== false, 'checkMultiSession não chama enforceSubscriptionAccess()');
        Assert::true($logged < $mutation && $mutation < $subscription && $subscription < $concurrent, 'enforceSubscriptionAccess() fora da posição logo depois de enforceMutationGuard()');

        $between = substr($body, $mutation + strlen('self::enforceMutationGuard();'), $subscription - $mutation - strlen('self::enforceMutationGuard();'));
        Assert::same('', trim($between), 'enforceSubscriptionAccess() deve vir logo depois de enforceMutationGuard()');
    }

    public function testGuardIsBuiltFromTheRepositoryAndDeniesWith403AndTheStatusLink(): void
    {
        $source = $this->method('enforceSubscriptionAccess');

        Assert::stringContains('public static function enforceSubscriptionAccess(): void', $source);
        Assert::stringContains('new SubscriptionAccessGuard(new SubscriptionRepository(PdoConnectionFactory::fromEnvironment()))', $source);
        Assert::stringContains('->decide(', $source);
        Assert::stringContains('self::SUBSCRIPTION_ACCESS_SESSION_KEY', $source);
        Assert::stringContains('TSession::setValue(self::SUBSCRIPTION_ACCESS_SESSION_KEY', $source);
        Assert::stringContains('self::denyRequest(', $source);
        Assert::stringContains('(string) $entry[\'message_key\']', $source, 'o 403 usa a chave da decisão');
        Assert::stringContains("'index.php?class=SubscriptionStatusView'", $source, 'o 403 leva o link da assinatura');
        Assert::stringContains("public const SUBSCRIPTION_ACCESS_SESSION_KEY = 'cv_subscription_access';", $this->service());
        Assert::stringContains('public const SUBSCRIPTION_ACCESS_TTL = 60;', $this->service());

        $deny = $this->method('denyRequest');
        Assert::stringContains('http_response_code(403)', $deny);
        Assert::stringContains('exit;', $deny);
    }

    /**
     * Correção 1: em GET de página inteira (index.php) o denyRequest sai
     * antes do layout, sem adianti.js; o TMessage viraria só um <script>
     * e a tela ficaria em branco. Lá vai uma página HTML autônoma; no
     * engine.php (AJAX) segue o TMessage.
     */
    public function testDenyRequestRendersAStandalonePageOnFullPageRequestsAndTMessageOtherwise(): void
    {
        $deny = $this->method('denyRequest');

        Assert::stringContains('self::isFullPageRequest($_SERVER)', $deny);
        Assert::stringContains('echo self::subscriptionBlockedPage(', $deny);
        Assert::stringContains("_t('Subscription')", $deny);
        Assert::stringContains("_t('Exit')", $deny);
        Assert::stringContains('index.php?class=LoginForm&method=onLogout', $deny);
        Assert::stringContains("new TMessage('error', \$message)", $deny, 'engine.php/AJAX mantém o TMessage');
        Assert::stringContains("_t('The request could not be verified. Reload the page and try again.')", $deny);
    }

    public function testFullPageRequestIsOnlyIndexPhp(): void
    {
        $this->loadService();

        Assert::true(\ApplicationAuthenticationService::isFullPageRequest(['SCRIPT_NAME' => '/index.php']));
        Assert::true(\ApplicationAuthenticationService::isFullPageRequest(['SCRIPT_NAME' => '/centralvet/index.php']));
        Assert::false(\ApplicationAuthenticationService::isFullPageRequest(['SCRIPT_NAME' => '/engine.php']));
        Assert::false(\ApplicationAuthenticationService::isFullPageRequest([]));
    }

    public function testBlockedPageIsEscapedHtmlWithTheMessageAndBothLinks(): void
    {
        $this->loadService();

        $html = \ApplicationAuthenticationService::subscriptionBlockedPage(
            'Sua assinatura está suspensa <b>"x"</b>',
            'Assinatura & plano',
            'index.php?class=SubscriptionStatusView',
            'Sair',
            'index.php?class=LoginForm&method=onLogout'
        );

        Assert::stringContains('<!DOCTYPE html>', $html);
        Assert::stringContains('<meta charset="utf-8">', $html);
        Assert::stringContains('Sua assinatura está suspensa &lt;b&gt;&quot;x&quot;&lt;/b&gt;', $html, 'mensagem escapada');
        Assert::stringContains('href="index.php?class=SubscriptionStatusView">Assinatura &amp; plano</a>', $html);
        Assert::stringContains('href="index.php?class=LoginForm&amp;method=onLogout">Sair</a>', $html);
        Assert::false(str_contains($html, '__adianti'), 'a página não depende do adianti.js');
        Assert::false(str_contains($html, '<b>'), 'nenhum HTML da mensagem passa sem escape');
        Assert::stringContains('min-height: 44px', $html, 'alvo de toque de 44 px');
        Assert::stringContains('text-align: left', $html, 'rótulos à esquerda');
        Assert::false(str_contains($html, 'text-align: center') || str_contains($html, 'text-align: right'));
    }

    public function testOnAfterLoginActivatesTheInvitedLink(): void
    {
        $source = $this->method('onAfterLogin');

        Assert::stringContains('TenantUserActivation::activateInvited(', $source);
        Assert::stringContains("TSession::getValue('userid')", $source);
        Assert::stringContains('TTransaction::rollback();', $source, 'rollback() só da transação aberta aqui');
        Assert::false(str_contains($source, 'rollbackAll'), 'rollbackAll desfaria uma transação externa');
    }

    public function testCachedDecisionIsKeyedByTenantAndController(): void
    {
        $this->loadService();
        $now = 1_000_000;

        $cache = \ApplicationAuthenticationService::rememberSubscriptionAccess([], 7, 'SaleList', AccessDecision::blockedPlan(), $now);

        $hit = \ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'SaleList', $now + 30);
        Assert::notNull($hit, 'decisão do mesmo tenant e controller vem do cache');
        Assert::false($hit['allowed']);
        Assert::same('Your plan does not include this feature', $hit['message_key']);

        Assert::null(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'TutorList', $now + 30), 'o 403 de SaleList não pode bloquear TutorList');
        Assert::null(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 8, 'SaleList', $now + 30), 'a decisão de um tenant não vale para outro');
        Assert::false(
            \ApplicationAuthenticationService::subscriptionAccessCacheKey(7, 'SaleList') === \ApplicationAuthenticationService::subscriptionAccessCacheKey(7, 'TutorList'),
            'a chave inclui o controller'
        );
        Assert::false(
            \ApplicationAuthenticationService::subscriptionAccessCacheKey(7, 'SaleList') === \ApplicationAuthenticationService::subscriptionAccessCacheKey(8, 'SaleList'),
            'a chave inclui o tenant'
        );
    }

    public function testCachedDecisionExpiresAfterSixtySeconds(): void
    {
        $this->loadService();
        $now = 1_000_000;

        $cache = \ApplicationAuthenticationService::rememberSubscriptionAccess([], 7, 'TutorList', AccessDecision::full(), $now);

        $hit = \ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'TutorList', $now + 59);
        Assert::notNull($hit);
        Assert::true($hit['allowed']);
        Assert::null($hit['message_key']);
        Assert::null(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'TutorList', $now + 60), 'decisão vencida em 60 s');
    }

    public function testRememberDropsExpiredEntriesAndKeepsOthers(): void
    {
        $this->loadService();
        $now = 1_000_000;

        $cache = \ApplicationAuthenticationService::rememberSubscriptionAccess([], 7, 'SaleList', AccessDecision::blockedStatus(), $now);
        $cache = \ApplicationAuthenticationService::rememberSubscriptionAccess($cache, 7, 'TutorList', AccessDecision::full(), $now + 30);
        $cache = \ApplicationAuthenticationService::rememberSubscriptionAccess($cache, 7, 'PatientList', AccessDecision::full(), $now + 70);

        Assert::count(2, $cache, 'a entrada vencida sai do cache');
        Assert::null(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'SaleList', $now + 70));
        Assert::notNull(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'TutorList', $now + 70));
        Assert::notNull(\ApplicationAuthenticationService::cachedSubscriptionAccess($cache, 7, 'PatientList', $now + 70));
    }

    private function loadService(): void
    {
        require_once dirname(__DIR__, 2) . '/app/service/auth/ApplicationAuthenticationService.php';
    }

    private function checkMultiSessionBody(): string
    {
        return $this->method('checkMultiSession');
    }

    /** Corpo do método, do cabeçalho até o próximo método da classe. */
    private function method(string $name): string
    {
        $source = $this->service();
        $match = preg_match('/\n    (?:public|private) static function ' . preg_quote($name, '/') . '\(/', $source, $found, PREG_OFFSET_CAPTURE);
        Assert::true($match === 1, $name . '() não encontrado');

        $start = (int) $found[0][1] + 1;
        $ends = [];
        foreach (["\n    public static function ", "\n    private static function ", "\n    /**"] as $marker) {
            $position = strpos($source, $marker, $start + 1);
            if ($position !== false) {
                $ends[] = $position;
            }
        }
        $end = $ends === [] ? strlen($source) : min($ends);

        return substr($source, $start, $end - $start);
    }

    private function service(): string
    {
        $path = dirname(__DIR__, 2) . '/app/service/auth/ApplicationAuthenticationService.php';
        $source = file_get_contents($path);
        Assert::true(is_string($source), 'não foi possível ler ' . $path);

        return (string) $source;
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\RequestScheme;
use CentralVet\Tests\Support\Assert;

/**
 * T-07: o esquema da requisição vem de HTTPS (que o nginx preenche a partir
 * do proxy da frente); X-Forwarded-Proto só conta com TRUST_FORWARDED_PROTO.
 * O cookie de sessão ganha Secure em SessionHandlerFactory, chamado por
 * index.php e engine.php antes de new TSession.
 */
final class RequestSchemeTest
{
    public function testForwardedProtoCountsOnlyWhenTrusted(): void
    {
        Assert::false(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https'], false), 'X-Forwarded-Proto sem confiança não vale');
        Assert::true(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https'], true), 'X-Forwarded-Proto confiável vale');
        Assert::true(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true), 'X-Forwarded-Proto sem diferenciar maiúsculas');
        Assert::false(RequestScheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'http'], true), 'X-Forwarded-Proto http não vale');
    }

    public function testHttpsServerVariable(): void
    {
        Assert::true(RequestScheme::isHttps(['HTTPS' => 'on'], false), 'HTTPS=on');
        Assert::true(RequestScheme::isHttps(['HTTPS' => 'ON'], false), 'HTTPS=ON');
        Assert::false(RequestScheme::isHttps(['HTTPS' => 'off'], false), 'HTTPS=off');
        Assert::false(RequestScheme::isHttps(['HTTPS' => 'OFF'], true), 'HTTPS=OFF');
        Assert::false(RequestScheme::isHttps(['HTTPS' => ''], false), 'HTTPS vazio');
        Assert::false(RequestScheme::isHttps([], false), 'sem HTTPS');
    }

    public function testSecureSessionCookieSetsTheIniOnlyWhenHttps(): void
    {
        $result = $this->subprocess(<<<'PHP'
            $before = (string) ini_get('session.cookie_secure');
            CentralVet\Security\RequestScheme::secureSessionCookie(false);
            $off = (string) ini_get('session.cookie_secure');
            CentralVet\Security\RequestScheme::secureSessionCookie(true);
            $on = (string) ini_get('session.cookie_secure');
            echo json_encode(['before' => $before, 'off' => $off, 'on' => $on]);
            PHP);

        Assert::same($result['before'], $result['off'], 'false não altera session.cookie_secure');
        Assert::true(in_array($result['off'], ['', '0'], true), 'valor anterior vazio ou 0: ' . $result['off']);
        Assert::same('1', $result['on'], 'true liga session.cookie_secure');
    }

    public function testPlainHttpWithoutTrustKeepsTheCookieNotSecure(): void
    {
        $result = $this->subprocess(<<<'PHP'
            putenv('TRUST_FORWARDED_PROTO');
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $current = CentralVet\Security\RequestScheme::current();
            CentralVet\Security\RequestScheme::secureSessionCookie($current);
            echo json_encode(['current' => $current, 'secure' => (string) ini_get('session.cookie_secure')]);
            PHP);

        Assert::false($result['current'], 'HTTP sem HTTPS e sem TRUST_FORWARDED_PROTO não é https');
        Assert::true($result['secure'] !== '1', 'session.cookie_secure não pode virar 1 em HTTP: ' . $result['secure']);
    }

    public function testAuthenticationServiceNoLongerReadsForwardedProto(): void
    {
        $source = $this->read('app/service/auth/ApplicationAuthenticationService.php');

        Assert::false(str_contains($source, 'HTTP_X_FORWARDED_PROTO'), 'ApplicationAuthenticationService ainda lê HTTP_X_FORWARDED_PROTO');
        Assert::stringContains('RequestScheme::current()', $source);
    }

    public function testSessionFactorySecuresTheCookieBeforeTheFirstReturn(): void
    {
        $source = $this->read('app/Core/Session/SessionHandlerFactory.php');
        $start = strpos($source, 'public static function createFromEnvironment()');
        Assert::true($start !== false, 'createFromEnvironment não encontrado');

        $call = strpos($source, 'RequestScheme::secureSessionCookie(RequestScheme::current())', $start);
        $return = strpos($source, 'return', $start);

        Assert::true($call !== false, 'createFromEnvironment não chama secureSessionCookie(current())');
        Assert::true($call < $return, 'secureSessionCookie precisa vir antes do primeiro return');
        Assert::stringContains("PHP_SAPI !== 'cli'", substr($source, $start, $call - $start));
    }

    /**
     * @return array<string, mixed>
     */
    private function subprocess(string $body): array
    {
        $script = 'require $argv[1];' . "\n" . $body;
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' ' . escapeshellarg($autoload) . ' 2>&1';
        $output = (string) shell_exec($command);
        $result = json_decode($output, true);
        Assert::true(is_array($result), 'saída do subprocesso precisa ser JSON: ' . $output);

        return $result;
    }

    private function read(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        Assert::true(is_string($source), 'não leu ' . $relative);

        return (string) $source;
    }
}

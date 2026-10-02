<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Landing pública servida em `/` (`landing.php`): decide entre a landing e o
 * sistema e monta o template com o token do formulário e o catálogo.
 *
 * - `landing`: GET/HEAD sem query string e sem sessão logada;
 * - `system`: sessão logada ou query string não vazia (302 para /index.php);
 * - `method_not_allowed`: qualquer outro método (405).
 */
final class LandingPage
{
    public const ENTRY_LANDING = 'landing';
    public const ENTRY_SYSTEM = 'system';
    public const ENTRY_METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const TEMPLATE = 'app/view/landing/landing.html';

    public function entryFor(string $method, string $queryString, bool $logged): string
    {
        $method = strtoupper($method);

        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::ENTRY_METHOD_NOT_ALLOWED;
        }

        if ($logged || $queryString !== '') {
            return self::ENTRY_SYSTEM;
        }

        return self::ENTRY_LANDING;
    }

    /**
     * Lê `logged` da sessão sem nunca gravá-la: só abre quando o cookie
     * $sessionName veio no pedido, em `read_and_close` (open/read/close, sem
     * write), sem Set-Cookie e sem cache headers. Cookie forjado ou expirado
     * resulta em anônimo e nada é criado no backend de sessão.
     *
     * @param array<string, mixed> $cookies normalmente $_COOKIE
     * @param string|null $applicationName APPLICATION_NAME do Adianti (TSession guarda os valores sob essa chave)
     */
    public function readLogged(
        ?\SessionHandlerInterface $handler,
        string $sessionName,
        array $cookies,
        ?string $applicationName,
    ): bool {
        $sessionId = $cookies[$sessionName] ?? null;
        if (!is_string($sessionId) || preg_match('/^[A-Za-z0-9,-]{1,256}$/', $sessionId) !== 1) {
            return false;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            return false;
        }

        if ($handler !== null) {
            session_set_save_handler($handler, false);
        }

        session_name($sessionName);
        session_id($sessionId);

        if (!session_start([
            'read_and_close' => true,
            'use_cookies' => false,
            'use_only_cookies' => true,
            'cache_limiter' => '',
        ])) {
            return false;
        }

        $data = $_SESSION;
        $_SESSION = [];

        $values = $applicationName !== null ? ($data[$applicationName] ?? []) : $data;

        return is_array($values) && (bool) ($values['logged'] ?? false);
    }

    public function render(string $template, string $leadToken): string
    {
        return strtr($template, [
            '{{LEAD_TOKEN}}' => htmlspecialchars($leadToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{LANDING_DATA}}' => LandingCatalog::publicJson(),
        ]);
    }
}

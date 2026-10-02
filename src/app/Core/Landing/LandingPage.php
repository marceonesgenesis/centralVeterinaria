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

    public function render(string $template, string $leadToken): string
    {
        return strtr($template, [
            '{{LEAD_TOKEN}}' => htmlspecialchars($leadToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{LANDING_DATA}}' => LandingCatalog::publicJson(),
        ]);
    }
}

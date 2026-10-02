<?php

declare(strict_types=1);

/**
 * Landing pública em `/` (o nginx manda `/` sem query string para cá).
 *
 * Visitante anônimo recebe a landing; quem tem sessão logada vai para o
 * sistema (`/index.php`). A sessão só é aberta quando o cookie da sessão já
 * existe, para que o visitante sem cookie não crie sessão no Redis.
 */

use CentralVet\Landing\LandingPage;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Session\SessionHandlerFactory;

chdir(__DIR__);
require_once __DIR__ . '/init.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');

$logged = false;
if (isset($_COOKIE[session_name()])) {
    new TSession(SessionHandlerFactory::createFromEnvironment());
    $logged = (bool) TSession::getValue('logged');
    session_write_close();
}

$page = new LandingPage();

switch ($page->entryFor($method, $queryString, $logged)) {
    case LandingPage::ENTRY_SYSTEM:
        http_response_code(302);
        header('Location: /index.php' . ($queryString !== '' ? '?' . $queryString : ''));
        exit;

    case LandingPage::ENTRY_METHOD_NOT_ALLOWED:
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Method Not Allowed\n";
        exit;
}

$leadToken = '';
try {
    $leadToken = (new LeadFormToken(RedisConnectionFactory::fromEnvironment()))->issue(time());
} catch (\Throwable $e) {
    error_log('landing.php: lead form token unavailable: ' . $e->getMessage());
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

if ($method === 'HEAD') {
    exit;
}

echo $page->render((string) file_get_contents(__DIR__ . '/' . LandingPage::TEMPLATE), $leadToken);

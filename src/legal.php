<?php

declare(strict_types=1);

/**
 * Página pública dos documentos legais: `legal.php?doc=termos|privacidade`.
 *
 * Sem sessão e sem o bootstrap do app: só o autoload do Composer. A CSP sai
 * daqui porque a location genérica `\.php$` do nginx não a envia.
 */

use CentralVet\Legal\LegalPage;

require_once __DIR__ . '/vendor/autoload.php';

$doc = $_GET['doc'] ?? '';
$page = LegalPage::render(is_string($doc) ? $doc : '');

http_response_code($page['status']);
header('Content-Type: text/html; charset=UTF-8');
header('Content-Security-Policy: ' . LegalPage::CSP);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

echo $page['body'];

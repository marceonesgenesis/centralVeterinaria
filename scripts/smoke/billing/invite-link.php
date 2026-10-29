<?php

declare(strict_types=1);

/**
 * Fumaça da contratação (Etapa 4, T-06): imprime o link de primeiro acesso
 * do responsável de um pedido `ZZ-E3-` já provisionado, no mesmo formato do
 * convite (AccountInviteService: token de redefinição de senha de 72 h e
 * SystemPasswordResetForm). O driver `log` do e-mail registra só o
 * destinatário, não o link. Só lê o banco (SELECT); não envia e-mail.
 *
 * No container app (o diretório scripts/ não vai para a imagem, então o
 * arquivo entra pela entrada padrão, a partir da raiz de src/):
 *   docker compose exec -T app php -- zz-e3-a@example.test < scripts/smoke/billing/invite-link.php
 * Com o checkout montado (hospedagem, CI): php scripts/smoke/billing/invite-link.php <email>
 *
 * Sai 2 com e-mail fora do padrão zz-e3-*, 1 sem pedido provisionado ou usuário.
 */

use CentralVet\Billing\AccountInviteService;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!preg_match('/^zz-e3-[a-z0-9.+-]*@example\.test$/', $email)) {
    fwrite(STDERR, 'invite-link: informe o e-mail de um pedido da fumaça (zz-e3-...@example.test)' . PHP_EOL);
    exit(2);
}

// Arquivo: <raiz>/scripts/smoke/billing → <raiz>/src. Entrada padrão: a raiz de src/ é o diretório atual.
$srcDir = is_file(__FILE__) ? dirname(__DIR__, 3) . '/src' : getcwd();
if (!is_string($srcDir) || !is_file($srcDir . '/init.php')) {
    fwrite(STDERR, 'invite-link: rode a partir da raiz de src/ (init.php não encontrado)' . PHP_EOL);
    exit(1);
}
chdir($srcDir);

require $srcDir . '/init.php';

$config = require $srcDir . '/config/environment.php';
date_default_timezone_set($config['app']['timezone']);

$database = $config['database'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
    $database['username'],
    $database['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$statement = $pdo->prepare(
    "SELECT o.id AS order_id, o.status, u.login, u.password
       FROM billing_order o
       JOIN tenant_user tu ON tu.tenant_id = o.tenant_id
       JOIN system_users u ON u.id = tu.system_user_id AND u.login = o.responsible_email
      WHERE o.responsible_email = ? AND o.clinic_name LIKE 'ZZ-E3-%' AND o.status = 'provisioned'
      ORDER BY o.id DESC
      LIMIT 1"
);
$statement->execute([$email]);
$row = $statement->fetch(PDO::FETCH_ASSOC);

if (!is_array($row)) {
    fwrite(STDERR, 'invite-link: nenhum pedido ZZ-E3- provisionado com usuário para ' . $email . PHP_EOL);
    exit(1);
}

$ini = AdiantiApplicationConfig::get();
$tokens = new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']);
$jwt = $tokens->issue((string) $row['login'], (string) $row['password'], time(), AccountInviteService::TTL_SECONDS);

fwrite(STDOUT, 'order_id=' . $row['order_id'] . PHP_EOL);
fwrite(STDOUT, 'link=' . PublicUrl::fromEnvironment()->to('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=' . rawurlencode($jwt)) . PHP_EOL);

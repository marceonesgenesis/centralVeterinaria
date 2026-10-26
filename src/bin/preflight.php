<?php

declare(strict_types=1);

/**
 * Preflight de produção e paridade de ambiente (Etapa 4, T-09), a partir da
 * raiz de src/, no container ou no CLI da hospedagem compartilhada:
 *
 *   php bin/preflight.php [--trusted-proxies-file=<caminho>] [--with-db]
 *
 * Carrega init.php (que lê app/config/hosting-environment.php quando existe),
 * monta CentralVet\Operations\ProductionPreflight com getenv(), o ini, a
 * versão e as extensões do PHP e imprime `<NÍVEL> <código>` por achado e,
 * por último, `preflight fail=<n> warn=<n> pass=<n>`. Nunca imprime valores.
 * Com --with-db conecta pelo DB_* do ambiente (usuário de runtime) e confere
 * a versão do MySQL e as migrations pendentes. Sai 1 com algum FAIL, 0 sem
 * FAIL e 2 em argumento inválido.
 */

use CentralVet\Operations\PreflightFinding;
use CentralVet\Operations\ProductionPreflight;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$trustedProxiesFile = null;
$withDb = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--with-db') {
        $withDb = true;
    } elseif (str_starts_with($argument, '--trusted-proxies-file=') && strlen($argument) > strlen('--trusted-proxies-file=')) {
        $trustedProxiesFile = substr($argument, strlen('--trusted-proxies-file='));
    } else {
        fwrite(STDERR, 'usage: php bin/preflight.php [--trusted-proxies-file=<caminho>] [--with-db]' . PHP_EOL);
        exit(2);
    }
}

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/init.php';

$preflight = new ProductionPreflight(
    getenv(),
    ini_get_all(null, false),
    PHP_VERSION,
    get_loaded_extensions(),
);

$pdo = null;
$dbUnavailable = false;
if ($withDb) {
    try {
        $config = require dirname(__DIR__) . '/config/environment.php';
        $database = $config['database'];
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
            $database['username'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (Throwable $exception) {
        fwrite(STDERR, 'preflight: db error=' . $exception::class . PHP_EOL);
        $dbUnavailable = true;
    }
}

$findings = $preflight->run($trustedProxiesFile, $pdo);
if ($dbUnavailable) {
    $findings[] = new PreflightFinding(PreflightFinding::FAIL, 'db_version');
    $findings[] = new PreflightFinding(PreflightFinding::FAIL, 'migrations_pending');
}

$counts = [PreflightFinding::FAIL => 0, PreflightFinding::WARN => 0, PreflightFinding::PASS => 0];
foreach ($findings as $finding) {
    $counts[$finding->level]++;
    fwrite(STDOUT, $finding->level . ' ' . $finding->code . PHP_EOL);
}
fwrite(STDOUT, sprintf(
    'preflight fail=%d warn=%d pass=%d',
    $counts[PreflightFinding::FAIL],
    $counts[PreflightFinding::WARN],
    $counts[PreflightFinding::PASS],
) . PHP_EOL);

exit($counts[PreflightFinding::FAIL] > 0 ? 1 : 0);

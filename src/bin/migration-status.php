<?php

declare(strict_types=1);

/**
 * Registro de migrations aplicadas (Etapa 4, T-03) para a hospedagem sem
 * Docker nem cliente mysql (a partir da raiz de src/):
 *
 *   php bin/migration-status.php
 *
 * Conecta pelo DB_* do ambiente (o usuário de runtime basta), só lê, e
 * imprime a mesma saída de scripts/db/migration-status.sh: `applied`/
 * `pending` por arquivo de app/database/migrations/, `unknown` e a linha
 * `migration-status database=<nome> applied=<n> pending=<n> unknown=<n>`.
 * Sai 0 com pending=0 unknown=0, 1 com algum pendente ou desconhecido e 2
 * em argumento, banco sem schema_migrations ou erro de conexão (só a classe
 * da exceção vai para a saída de erro). Regras em
 * CentralVet\Operations\MigrationStatus.
 */

use CentralVet\Operations\MigrationStatus;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if ($argc > 1) {
    fwrite(STDERR, 'usage: php bin/migration-status.php' . PHP_EOL);
    exit(2);
}

chdir(dirname(__DIR__));

try {
    require dirname(__DIR__) . '/init.php';
    $config = require dirname(__DIR__) . '/config/environment.php';

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $report = (new MigrationStatus($pdo, dirname(__DIR__) . '/app/database/migrations'))->report();
} catch (Throwable $exception) {
    fwrite(STDERR, 'migration-status: error=' . $exception::class . PHP_EOL);
    exit(2);
}

foreach (MigrationStatus::lines($report) as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}

exit($report['pending'] === [] && $report['unknown'] === [] ? 0 : 1);

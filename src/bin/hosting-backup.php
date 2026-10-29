<?php

declare(strict_types=1);

/**
 * Backup da hospedagem compartilhada (Etapa 4, T-17), para o agendador do
 * painel, sem Docker, `gpg` nem `mysqldump` (a partir da raiz de src/):
 *
 *   php bin/hosting-backup.php [--no-heartbeat]
 *   php bin/hosting-backup.php --decrypt <entrada> <saída>
 *
 * Gera `$BACKUP_DIR/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk` com o mesmo
 * MANIFEST v1 de scripts/backup.sh (T-08), cifrado no formato CVBK1
 * (CentralVet\Operations\BackupCipher; regras em HostingBackup). Variáveis:
 * `BACKUP_DIR` (padrão `var/backups` na raiz do projeto; caminho relativo
 * vale a partir dela), `BACKUP_PASSPHRASE_FILE` (obrigatória; vale a
 * primeira linha), `BACKUP_RETENTION_DAYS` (padrão 14), `BACKUP_OFFSITE_DIR`
 * (opcional, diretório montado), `DOCUMENT_STORAGE_LOCAL_ROOT` (pasta de
 * documentos fora do public_html) e `DB_*`.
 *
 * Saída como a de T-08: `backup: pruned <arquivo>`, `backup: offsite copy
 * <destino>`, `backup: heartbeat not recorded` (stderr) e, por último,
 * `Backup created: <caminho> parts=<n> bytes=<n>`. Códigos: 0 criado; 2
 * configuração (frase ausente ou ilegível, opção inválida); 3 falha de dump
 * ou de arquivo (nada parcial fica em BACKUP_DIR); 4 cópia externa falhou
 * (o arquivo local fica; sai antes do heartbeat e sem `Backup created`). A retenção só apaga `centralvet-*.tar.gz.cvbk`
 * mais antigos que BACKUP_RETENTION_DAYS, nunca o recém-criado. Sem
 * `--no-heartbeat`, grava `ops_heartbeat` (`job = 'backup'`) pelo PDO da
 * aplicação; a falha desse INSERT não muda o código. `--decrypt` sai 0, 2
 * (argumento ou frase), 3 (arquivo) ou 5 (autenticação falhou: frase errada
 * ou arquivo alterado; a saída não é criada). Só a classe da exceção vai
 * para a saída de erro.
 */

use CentralVet\Operations\BackupCipher;
use CentralVet\Operations\HostingBackup;
use CentralVet\Storage\DocumentStorageFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

umask(0077);

$args = array_slice($argv, 1);
$decrypt = ($args[0] ?? null) === '--decrypt';
if ($decrypt ? count($args) !== 3 : ($args !== [] && $args !== ['--no-heartbeat'])) {
    fwrite(STDERR, 'usage: php bin/hosting-backup.php [--no-heartbeat] | --decrypt <input> <output>' . PHP_EOL);
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
$cwd = (string) getcwd();
chdir(dirname(__DIR__));

try {
    require dirname(__DIR__) . '/init.php';
} catch (Throwable $exception) {
    fwrite(STDERR, 'backup: error=' . $exception::class . PHP_EOL);
    exit(3);
}

$resolve = static fn (string $path, string $base): string => str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;

$passphraseFile = (string) (getenv('BACKUP_PASSPHRASE_FILE') ?: '');
$passphrase = '';
if ($passphraseFile !== '') {
    $passphraseFile = $resolve($passphraseFile, $projectRoot);
    $content = is_file($passphraseFile) && is_readable($passphraseFile) ? @file_get_contents($passphraseFile) : false;
    $passphrase = $content === false ? '' : rtrim(strtok($content, "\n") ?: '', "\r");
}
if ($passphrase === '') {
    fwrite(STDERR, 'backup: BACKUP_PASSPHRASE_FILE missing, unreadable or empty' . PHP_EOL);
    exit(2);
}

if ($decrypt) {
    $input = $resolve($args[1], $cwd);
    $output = $resolve($args[2], $cwd);
    if (!is_file($input) || file_exists($output)) {
        fwrite(STDERR, 'backup: decrypt needs an existing input and a new output path' . PHP_EOL);
        exit(2);
    }
    try {
        BackupCipher::decryptFile($input, $output, $passphrase);
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'backup authentication failed') {
            fwrite(STDERR, 'backup: authentication failed' . PHP_EOL);
            exit(5);
        }
        fwrite(STDERR, 'backup: error=' . $exception::class . PHP_EOL);
        exit(3);
    }
    fwrite(STDOUT, 'Backup decrypted: ' . $output . PHP_EOL);
    exit(0);
}

$retentionRaw = (string) (getenv('BACKUP_RETENTION_DAYS') ?: '14');
if (!ctype_digit($retentionRaw) || (int) $retentionRaw < 1) {
    fwrite(STDERR, 'backup: BACKUP_RETENTION_DAYS must be a positive integer' . PHP_EOL);
    exit(2);
}
$retentionDays = (int) $retentionRaw;
$backupDir = $resolve((string) (getenv('BACKUP_DIR') ?: 'var/backups'), $projectRoot);
$offsiteDir = (string) (getenv('BACKUP_OFFSITE_DIR') ?: '');
$documentsDir = (string) (getenv('DOCUMENT_STORAGE_LOCAL_ROOT') ?: DocumentStorageFactory::DEFAULT_LOCAL_ROOT);

$lockHandle = fopen(sys_get_temp_dir() . '/centralvet-hosting-backup-' . md5(__DIR__) . '.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, 'backup: skipped_locked' . PHP_EOL);
    exit(0);
}

try {
    $config = require dirname(__DIR__) . '/config/environment.php';
    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $path = (new HostingBackup($pdo, dirname(__DIR__) . '/files', $documentsDir, $backupDir))->create($passphrase);
} catch (Throwable $exception) {
    error_log(sprintf('hosting-backup.php: %s', $exception::class));
    fwrite(STDERR, 'backup: error=' . $exception::class . PHP_EOL);
    exit(3);
}

$cutoff = time() - $retentionDays * 86400;
foreach (glob(rtrim($backupDir, '/') . '/centralvet-*.tar.gz.cvbk') ?: [] as $old) {
    if ($old !== $path && is_file($old) && filemtime($old) < $cutoff && @unlink($old)) {
        fwrite(STDOUT, 'backup: pruned ' . $old . PHP_EOL);
    }
}

if ($offsiteDir !== '') {
    $destination = rtrim($offsiteDir, '/') . '/' . basename($path);
    $partial = $destination . '.part';
    if (is_dir($offsiteDir) && @copy($path, $partial) && hash_file('sha256', $partial) === hash_file('sha256', $path) && @rename($partial, $destination)) {
        fwrite(STDOUT, 'backup: offsite copy ' . $destination . PHP_EOL);
    } else {
        @unlink($partial);
        fwrite(STDERR, 'backup: offsite copy failed ' . $destination . PHP_EOL);
        // Como em T-08: sem heartbeat nem `Backup created`, para o backup_stale avisar.
        fwrite(STDERR, 'backup: local backup kept at ' . $path . PHP_EOL);
        exit(4);
    }
}

if (!in_array('--no-heartbeat', $args, true)) {
    try {
        $pdo->prepare(
            "INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES ('backup', NOW(6), ?)"
            . ' ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), detail = VALUES(detail)',
        )->execute([basename($path)]);
    } catch (Throwable) {
        fwrite(STDERR, 'backup: heartbeat not recorded' . PHP_EOL);
    }
}

fwrite(STDOUT, sprintf('Backup created: %s parts=%d bytes=%d', $path, count(HostingBackup::PARTS), (int) filesize($path)) . PHP_EOL);
exit(0);

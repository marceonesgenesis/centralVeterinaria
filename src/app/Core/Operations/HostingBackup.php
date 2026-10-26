<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use PDO;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Backup completo da hospedagem compartilhada (Etapa 4, T-17), onde não há
 * Docker, `gpg` nem `mysqldump`: só PDO, PharData, zlib e openssl.
 *
 * Gera `$backupDir/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk` (cifra
 * BackupCipher) de um tar.gz com as partes do formato
 * `centralvet-backup-v1` de T-08 (`scripts/backup.sh`): `MANIFEST`,
 * `db.sql.gz`, `files.tar.gz` e `documents.tar.gz`; `project=hosting` e
 * `minio=skipped`. O trabalho intermediário fica num diretório oculto de
 * `$backupDir`, apagado no fim; em falha nada parcial fica para trás.
 *
 * O dump: `SET FOREIGN_KEY_CHECKS=0`, `SHOW CREATE TABLE` de cada tabela
 * base, `INSERT` em lotes de 500 linhas (leitura sem buffer, valores não
 * UTF-8 em hexadecimal) e os triggers (`SHOW TRIGGERS` + `SHOW CREATE
 * TRIGGER`, sem `DEFINER`, entre `DELIMITER ;;`), que o preparador do 5.7
 * usa no lugar dos CHECK. A leitura é consistente com `START TRANSACTION
 * WITH CONSISTENT SNAPSHOT`, aberto só quando o PDO não está em transação
 * (o teste de integração roda dentro da sua e não pode sofrer COMMIT).
 * Só lê o banco.
 */
final class HostingBackup
{
    public const PARTS = ['db.sql.gz', 'files.tar.gz', 'documents.tar.gz'];

    /** Tabelas com `rows <tabela>=<n>` no MANIFEST, como em T-08. */
    public const COUNTED_TABLES = ['tenant', 'system_users', 'billing_order', 'communication_message', 'generated_document'];

    private const BATCH_ROWS = 500;

    private readonly Closure $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $filesDir,
        private readonly string $documentsDir,
        private readonly string $backupDir,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function create(string $passphrase): string
    {
        if ($passphrase === '') {
            throw new RuntimeException('backup passphrase is empty');
        }
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0700, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('backup directory not writable');
        }

        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
        $target = rtrim($this->backupDir, '/') . '/centralvet-' . $now->format('Ymd\THis\Z') . '.tar.gz.cvbk';
        if (file_exists($target)) {
            throw new RuntimeException('backup already exists');
        }

        $work = rtrim($this->backupDir, '/') . '/.centralvet-work-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0700)) {
            throw new RuntimeException('backup work directory not writable');
        }

        try {
            $database = $this->dumpDatabase($work . '/db.sql.gz');
            $filesCount = $this->archiveDirectory($this->filesDir, $work . '/files.tar');
            $documentsCount = $this->archiveDirectory($this->documentsDir, $work . '/documents.tar');

            $manifest = [
                'format=centralvet-backup-v1',
                'created_at=' . $now->format('Y-m-d\TH:i:s\Z'),
                'commit=' . $this->commit(),
                'project=hosting',
                'mysql_database=' . $database['name'],
                'minio=skipped',
                'table_count=' . $database['table_count'],
            ];
            foreach (self::COUNTED_TABLES as $table) {
                $manifest[] = sprintf('rows %s=%d', $table, $database['rows'][$table] ?? 0);
            }
            $manifest[] = 'files_count=' . $filesCount;
            $manifest[] = 'documents_count=' . $documentsCount;
            foreach ($database['migrations'] as $version) {
                $manifest[] = 'migration ' . $version;
            }
            foreach (self::PARTS as $part) {
                $manifest[] = sprintf('sha256 %s %s', hash_file('sha256', $work . '/' . $part), $part);
            }
            self::put($work . '/MANIFEST', implode("\n", $manifest) . "\n");

            $package = new PharData($work . '/package.tar');
            $package->startBuffering();
            $package->addFile($work . '/MANIFEST', 'MANIFEST');
            foreach (self::PARTS as $part) {
                $package->addFile($work . '/' . $part, $part);
            }
            $package->stopBuffering();
            $package->compress(Phar::GZ);
            unset($package);

            BackupCipher::encryptFile($work . '/package.tar.gz', $target, $passphrase);
        } catch (Throwable $exception) {
            if (is_file($target)) {
                @unlink($target);
            }
            throw $exception;
        } finally {
            self::removeTree($work);
        }

        return $target;
    }

    /**
     * @return array{name: string, table_count: int, rows: array<string, int>, migrations: list<string>}
     */
    private function dumpDatabase(string $path): array
    {
        $snapshot = !$this->pdo->inTransaction();
        if ($snapshot) {
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('backup dump not writable');
        }

        try {
            $name = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);
            sort($tables, SORT_STRING);

            self::gzWrite($gz, sprintf(
                "-- Central Vet Pro hosting backup (centralvet-backup-v1)\n-- database: %s\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n",
                $name,
            ));

            $rows = [];
            foreach ($tables as $table) {
                $create = $this->pdo->query('SHOW CREATE TABLE ' . self::ident($table))->fetch(PDO::FETCH_NUM);
                self::gzWrite($gz, sprintf("DROP TABLE IF EXISTS %s;\n%s;\n\n", self::ident($table), $create[1]));
                $rows[$table] = $this->dumpRows($gz, $table);
            }

            foreach ($this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
                $definition = $this->pdo->query('SHOW CREATE TRIGGER ' . self::ident((string) $trigger['Trigger']))->fetch(PDO::FETCH_ASSOC);
                $statement = (string) ($definition['SQL Original Statement'] ?? '');
                $statement = (string) preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\S+)@(`[^`]*`|\S+)/', '', $statement, 1);
                self::gzWrite($gz, sprintf("DELIMITER ;;\n%s;;\nDELIMITER ;\n\n", $statement));
            }

            self::gzWrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");

            $migrations = in_array('schema_migrations', $tables, true)
                ? $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
                : [];
        } finally {
            gzclose($gz);
            if ($snapshot) {
                $this->pdo->exec('COMMIT');
            }
        }

        return [
            'name' => $name,
            'table_count' => count($tables),
            'rows' => $rows,
            'migrations' => array_map('strval', $migrations),
        ];
    }

    /** @param resource $gz */
    private function dumpRows($gz, string $table): int
    {
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $count = 0;

        try {
            $statement = $this->pdo->query('SELECT * FROM ' . self::ident($table));
            $columns = null;
            $batch = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($columns === null) {
                    $columns = implode(', ', array_map([self::class, 'ident'], array_keys($row)));
                }
                $values = [];
                foreach ($row as $value) {
                    $values[] = $this->literal($value);
                }
                $batch[] = '(' . implode(', ', $values) . ')';
                $count++;
                if (count($batch) === self::BATCH_ROWS) {
                    self::gzWrite($gz, sprintf("INSERT INTO %s (%s) VALUES\n%s;\n", self::ident($table), $columns, implode(",\n", $batch)));
                    $batch = [];
                }
            }
            $statement->closeCursor();
            if ($batch !== []) {
                self::gzWrite($gz, sprintf("INSERT INTO %s (%s) VALUES\n%s;\n", self::ident($table), $columns, implode(",\n", $batch)));
            }
            if ($count > 0) {
                self::gzWrite($gz, "\n");
            }
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        return $count;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;
        if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            return '0x' . bin2hex($value);
        }

        return $this->pdo->quote($value);
    }

    /** Empacota os arquivos regulares de $dir (caminhos relativos) em $tar.gz; devolve a contagem. */
    private function archiveDirectory(string $dir, string $tar): int
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            throw new RuntimeException('backup source directory not readable');
        }

        $base = rtrim($dir, '/');
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $files[substr($file->getPathname(), strlen($base) + 1)] = $file->getPathname();
            }
        }
        ksort($files, SORT_STRING);

        if ($files === []) {
            // O PharData não grava tar vazio: só os blocos zerados de fim de arquivo.
            self::put($tar . '.gz', (string) gzencode(str_repeat("\0", 10240), 6));

            return 0;
        }

        $archive = new PharData($tar);
        $archive->startBuffering();
        foreach ($files as $relative => $absolute) {
            $archive->addFile($absolute, $relative);
        }
        $archive->stopBuffering();
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tar);

        return count($files);
    }

    private function commit(): string
    {
        $file = dirname(__DIR__, 3) . '/buildid';
        $value = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[0-9a-f]{7,40}$/', $value) === 1 ? $value : 'unknown';
    }

    private static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** @param resource $gz */
    private static function gzWrite($gz, string $data): void
    {
        if (gzwrite($gz, $data) !== strlen($data)) {
            throw new RuntimeException('backup dump write failed');
        }
    }

    private static function put(string $path, string $data): void
    {
        if (file_put_contents($path, $data) !== strlen($data)) {
            throw new RuntimeException('backup write failed');
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

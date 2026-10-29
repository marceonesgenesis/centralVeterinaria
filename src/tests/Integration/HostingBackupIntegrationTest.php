<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Operations\BackupCipher;
use CentralVet\Operations\HostingBackup;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\TestDatabase;
use DateTimeImmutable;
use DateTimeZone;
use PharData;

/**
 * Backup da hospedagem compartilhada por PHP CLI (Etapa 4, T-17) contra o
 * `centralvet_test`: dentro da transação do teste (o backup não abre
 * snapshot nem faz COMMIT), com diretórios temporários de arquivos (2) e
 * documentos (1). Decifrado, o pacote traz o MANIFEST v1 de T-08 com
 * `project=hosting`, `minio=skipped`, contagens e um sha256 por parte.
 */
final class HostingBackupIntegrationTest extends MysqlIntegrationTestCase
{
    private const PASSPHRASE = 'frase-de-teste-etapa4-hosting';

    private string $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/cv-hosting-backup-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/files/tenant-1', 0700, true);
        mkdir($this->root . '/documents/1', 0700, true);
        mkdir($this->root . '/backups', 0700, true);
        file_put_contents($this->root . '/files/logo.png', 'png-bytes');
        file_put_contents($this->root . '/files/tenant-1/foto.jpg', 'jpg-bytes');
        file_put_contents($this->root . '/documents/1/receita.pdf', '%PDF-1.4 teste');
    }

    public function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testCreateWritesEncryptedPackageWithManifestV1AndVerifiableParts(): void
    {
        $backup = new HostingBackup(
            $this->pdo,
            $this->root . '/files',
            $this->root . '/documents',
            $this->root . '/backups',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-26 12:00:00', new DateTimeZone('UTC')),
        );

        $path = $backup->create(self::PASSPHRASE);

        Assert::same($this->root . '/backups/centralvet-20261026T120000Z.tar.gz.cvbk', $path);
        Assert::true(is_file($path));
        Assert::same('CVBK1', (string) file_get_contents($path, false, null, 0, 5));
        Assert::true($this->pdo->inTransaction(), 'backup left the test transaction');

        BackupCipher::decryptFile($path, $this->root . '/package.tar.gz', self::PASSPHRASE);
        (new PharData($this->root . '/package.tar.gz'))->extractTo($this->root . '/out');

        $manifest = (string) file_get_contents($this->root . '/out/MANIFEST');
        $lines = explode("\n", trim($manifest));
        $tenantCount = (int) $this->pdo->query('SELECT COUNT(*) FROM tenant')->fetchColumn();

        Assert::same('format=centralvet-backup-v1', $lines[0]);
        Assert::true(in_array('project=hosting', $lines, true), $manifest);
        Assert::true(in_array('minio=skipped', $lines, true), $manifest);
        Assert::true(in_array('rows tenant=' . $tenantCount, $lines, true), $manifest);
        Assert::true(in_array('files_count=2', $lines, true), $manifest);
        Assert::true(in_array('documents_count=1', $lines, true), $manifest);
        $migrations = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
        Assert::same(
            array_map(static fn (string $version): string => 'migration ' . $version, $migrations),
            array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'migration '))),
        );
        Assert::same(1, preg_match('/^created_at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/m', $manifest));
        Assert::same(1, preg_match('/^commit=\S+$/m', $manifest));

        foreach (['db.sql.gz', 'files.tar.gz', 'documents.tar.gz'] as $part) {
            $hash = hash_file('sha256', $this->root . '/out/' . $part);
            Assert::true(in_array('sha256 ' . $hash . ' ' . $part, $lines, true), "sha256 of {$part} missing or wrong");
        }

        $sql = (string) gzdecode((string) file_get_contents($this->root . '/out/db.sql.gz'));
        Assert::stringContains('SET FOREIGN_KEY_CHECKS=0;', $sql);
        Assert::stringContains('CREATE TABLE `ops_heartbeat`', $sql);
        Assert::stringContains('INSERT INTO `tenant`', $sql);

        (new PharData($this->root . '/out/files.tar.gz'))->extractTo($this->root . '/files-out');
        Assert::same('jpg-bytes', file_get_contents($this->root . '/files-out/tenant-1/foto.jpg'));
        (new PharData($this->root . '/out/documents.tar.gz'))->extractTo($this->root . '/documents-out');
        Assert::same('%PDF-1.4 teste', file_get_contents($this->root . '/documents-out/1/receita.pdf'));

        Assert::same(['centralvet-20261026T120000Z.tar.gz.cvbk'], array_values(array_diff(scandir($this->root . '/backups'), ['.', '..'])));
    }

    /**
     * Como em T-08: cópia externa com falha sai 4 antes do heartbeat e sem
     * `Backup created`, para o `backup_stale` (T-12) avisar. A linha
     * `backup` de ops_heartbeat fica travada pela transação deste teste:
     * qualquer tentativa de heartbeat do subprocesso esperaria o lock e
     * terminaria em `backup: heartbeat not recorded`, sem persistir nada.
     */
    public function testOffsiteFailureExitsFourWithoutHeartbeatOrSuccessLine(): void
    {
        $this->pdo->exec(
            "INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES ('backup', NOW(6), 'held-by-test')"
            . " ON DUPLICATE KEY UPDATE detail = 'held-by-test'",
        );
        file_put_contents($this->root . '/passphrase', self::PASSPHRASE . "\n");
        mkdir($this->root . '/tmp', 0700);

        $env = getenv();
        $env['DB_DATABASE'] = TestDatabase::resolveName(getenv());
        $env['BACKUP_DIR'] = $this->root . '/backups';
        $env['BACKUP_PASSPHRASE_FILE'] = $this->root . '/passphrase';
        $env['BACKUP_OFFSITE_DIR'] = $this->root . '/offsite-missing';
        $env['DOCUMENT_STORAGE_LOCAL_ROOT'] = $this->root . '/documents';
        $env['TMPDIR'] = $this->root . '/tmp';

        $process = proc_open(
            [PHP_BINARY, '-d', 'sys_temp_dir=' . $this->root . '/tmp', '-d', 'display_errors=0', dirname(__DIR__, 2) . '/bin/hosting-backup.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        Assert::true(is_resource($process), 'subprocess started');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        Assert::same(4, $code, "stdout: {$stdout} stderr: {$stderr}");
        Assert::stringContains('backup: offsite copy failed ' . $this->root . '/offsite-missing/centralvet-', $stderr);
        Assert::false(str_contains($stdout, 'Backup created'), 'success line printed after offsite failure');
        Assert::false(str_contains($stderr, 'heartbeat not recorded'), 'heartbeat attempted after offsite failure');
        Assert::count(1, glob($this->root . '/backups/centralvet-*.tar.gz.cvbk') ?: [], 'local backup must be kept');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

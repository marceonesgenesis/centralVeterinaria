<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;

/**
 * Fase 8B pendências, T-28: `bin/ai-conversation-retention.php` distingue a
 * trava indisponível (fopen falhou → log e exit 1) da execução sobreposta
 * (flock ocupado → {"skipped":"locked"} e exit 0).
 *
 * Os dois casos saem antes de abrir a conexão; nenhum roda o bin com a trava
 * livre (o ramo livre usaria o banco do ambiente, não o centralvet_test).
 * Por segurança, o subprocesso ainda recebe DB_HOST inválido.
 */
final class AiConversationRetentionBinTest
{
    /** @var list<string> */
    private array $cleanup = [];

    /** @var resource|null */
    private $heldLock = null;

    public function tearDown(): void
    {
        if (is_resource($this->heldLock)) {
            flock($this->heldLock, LOCK_UN);
            fclose($this->heldLock);
        }
        $this->heldLock = null;

        foreach (array_reverse($this->cleanup) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->cleanup = [];
    }

    public function testUnavailableLockFileLogsAndExitsOne(): void
    {
        $base = $this->makeTempDir();
        $missingTempDir = $base . '/missing-dir';
        $errorLog = $base . '/error.log';
        $this->cleanup[] = $errorLog;

        [$code, $stdout] = $this->runBin($missingTempDir, $errorLog);

        Assert::same(1, $code, 'exit code with lock file unavailable');
        Assert::false(str_contains($stdout, 'skipped'), 'stdout must not report skipped');
        Assert::same('', trim($stdout), 'stdout must be empty');
        $log = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
        Assert::stringContains('lock file unavailable', $log);
    }

    public function testHeldLockSkipsAndExitsZero(): void
    {
        $tempDir = $this->makeTempDir();
        $errorLog = $tempDir . '/error.log';
        $this->cleanup[] = $errorLog;

        $binDir = realpath(dirname(__DIR__, 2) . '/bin');
        Assert::true(is_string($binDir), 'bin dir resolved');
        $lockPath = $tempDir . '/centralvet-ai-conversation-retention-' . md5((string) $binDir) . '.lock';
        $this->cleanup[] = $lockPath;

        $handle = fopen($lockPath, 'c');
        Assert::true($handle !== false, 'test lock file opened');
        $this->heldLock = $handle;
        Assert::true(flock($handle, LOCK_EX | LOCK_NB), 'test holds the lock');

        [$code, $stdout] = $this->runBin($tempDir, $errorLog);

        Assert::same(0, $code, 'exit code with lock held elsewhere');
        Assert::same('{"skipped":"locked"}', trim($stdout));
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/cv-retention-bin-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('could not create temp dir');
        }
        $this->cleanup[] = $dir;

        return $dir;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runBin(string $sysTempDir, string $errorLog): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/ai-conversation-retention.php';
        $command = [
            PHP_BINARY,
            '-d', 'sys_temp_dir=' . $sysTempDir,
            '-d', 'error_log=' . $errorLog,
            '-d', 'log_errors=1',
            '-d', 'display_errors=0',
            $bin,
        ];

        $env = getenv();
        $env['DB_HOST'] = 'retention-bin-test.invalid';
        $env['DB_DATABASE'] = 'retention_bin_test_invalid';
        $env['TMPDIR'] = $sysTempDir;

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start subprocess');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout];
    }
}

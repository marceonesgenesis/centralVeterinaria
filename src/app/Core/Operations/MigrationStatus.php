<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Registro de migrations aplicadas (Etapa 4, T-03): compara as migrations
 * numeradas de um diretório (`2*_NNNN_*.sql`, sem os `*.verify.sql`) com
 * `schema_migrations` do banco da conexão. Só lê (`SELECT DATABASE()` e
 * `SELECT version FROM schema_migrations`); funciona com o usuário de
 * runtime, inclusive no MySQL 5.7 da hospedagem compartilhada. Mesma regra
 * de `scripts/db/migration-status.sh`.
 */
final class MigrationStatus
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * @return array{database: string, applied: list<string>, pending: list<string>, unknown: list<string>}
     *
     * @throws RuntimeException quando o banco não tem schema_migrations
     */
    public function report(): array
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        try {
            $rows = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            throw new RuntimeException(sprintf('Database %s has no readable schema_migrations table', $database), 0, $exception);
        }

        $inDatabase = [];
        foreach ($rows as $version) {
            $inDatabase[(string) $version] = true;
        }

        $expected = $this->expectedVersions();
        $applied = [];
        $pending = [];
        foreach ($expected as $version) {
            if (isset($inDatabase[$version])) {
                $applied[] = $version;
            } else {
                $pending[] = $version;
            }
        }

        $unknown = array_values(array_diff(array_keys($inDatabase), $expected));
        sort($unknown, SORT_STRING);

        return ['database' => $database, 'applied' => $applied, 'pending' => $pending, 'unknown' => $unknown];
    }

    /**
     * Formata o relatório como o shell: linhas applied/pending/unknown e o
     * resumo por último.
     *
     * @param array{database: string, applied: list<string>, pending: list<string>, unknown: list<string>} $report
     * @return list<string>
     */
    public static function lines(array $report): array
    {
        $applied = array_flip($report['applied']);
        $fileOrder = array_merge($report['applied'], $report['pending']);
        sort($fileOrder, SORT_STRING);
        $lines = [];
        foreach ($fileOrder as $version) {
            $lines[] = (isset($applied[$version]) ? 'applied ' : 'pending ') . $version;
        }
        foreach ($report['unknown'] as $version) {
            $lines[] = 'unknown ' . $version;
        }
        $lines[] = sprintf(
            'migration-status database=%s applied=%d pending=%d unknown=%d',
            $report['database'],
            count($report['applied']),
            count($report['pending']),
            count($report['unknown']),
        );

        return $lines;
    }

    /** @return list<string> versões esperadas, em ordem */
    private function expectedVersions(): array
    {
        $versions = [];
        foreach (glob(rtrim($this->migrationsDir, '/') . '/2*_[0-9][0-9][0-9][0-9]_*.sql') ?: [] as $file) {
            $name = basename($file);
            if (str_ends_with($name, '.verify.sql') || !is_file($file)) {
                continue;
            }
            $versions[] = substr($name, 0, -4);
        }
        sort($versions, SORT_STRING);

        return $versions;
    }
}

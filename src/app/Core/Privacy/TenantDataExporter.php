<?php

declare(strict_types=1);

namespace CentralVet\Privacy;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Exportação LGPD dos dados estruturados de uma clínica (tenant) num ZIP:
 * um CSV por tabela com coluna `tenant_id` (descobertas em
 * information_schema, sem lista manual), só as linhas do tenant e sem
 * colunas sensíveis; `users.csv` com a equipe; `manifest.json` descrevendo
 * o conteúdo. Arquivos do storage (fotos, PDFs) ficam fora nesta versão
 * (`files_included: false`). Síncrono: cada CSV é escrito em streaming num
 * temporário e anexado ao ZIP, removido depois do close().
 */
final class TenantDataExporter
{
    public const EXCLUDED_TABLES = ['billing_event', 'feature_flag'];

    public const SENSITIVE_COLUMN_PATTERN = '/(password|secret|hash|\btoken\b|auth_token|api_token|access_token|refresh_token|csrf_token)/i';

    private const USERS_HEADER = ['id', 'name', 'login', 'email', 'status'];

    private const BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->columnsByTable());
    }

    /**
     * @return array{tables: array<string, int>, users: int}
     */
    public function export(int $tenantId, string $zipPath, DateTimeImmutable $now): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo de exportação');
        }

        $tempFiles = [];
        $counts = [];
        $excludedColumns = [];

        try {
            foreach ($this->columnsByTable() as $table => $columns) {
                $kept = [];
                foreach ($columns as $column) {
                    if (preg_match(self::SENSITIVE_COLUMN_PATTERN, $column) === 1) {
                        $excludedColumns[$table][] = $column;
                        continue;
                    }
                    $kept[] = $column;
                }

                $sql = sprintf(
                    'SELECT %s FROM %s WHERE `tenant_id` = ?',
                    implode(', ', array_map([$this, 'quote'], $kept)),
                    $this->quote($table),
                );
                [$file, $rows] = $this->writeCsv($kept, $sql, [$tenantId]);
                $tempFiles[] = $file;
                $counts[$table] = $rows;
                $this->addFile($zip, $file, $table . '.csv');
            }

            [$file, $users] = $this->writeCsv(
                self::USERS_HEADER,
                'SELECT u.id, u.name, u.login, u.email, tu.status '
                . 'FROM tenant_user tu INNER JOIN system_users u ON u.id = tu.system_user_id '
                . 'WHERE tu.tenant_id = ? ORDER BY u.id',
                [$tenantId],
            );
            $tempFiles[] = $file;
            $this->addFile($zip, $file, 'users.csv');

            $manifest = [
                'tenant_id' => $tenantId,
                'generated_at' => $now->format(DATE_ATOM),
                'tables' => $counts,
                'users' => $users,
                'excluded_tables' => self::EXCLUDED_TABLES,
                'excluded_columns' => $excludedColumns,
                'files_included' => false,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!$zip->addFromString('manifest.json', $json)) {
                throw new RuntimeException('Não foi possível gravar o manifesto da exportação');
            }

            if (!$zip->close()) {
                throw new RuntimeException('Não foi possível concluir o arquivo de exportação');
            }
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    unlink($tempFile);
                }
            }
        }

        return ['tables' => $counts, 'users' => $users];
    }

    /**
     * Tabelas-base do schema atual com `tenant_id`, menos EXCLUDED_TABLES,
     * em ordem alfabética, com as colunas na ordem do schema.
     *
     * @return array<string, list<string>>
     */
    private function columnsByTable(): array
    {
        $statement = $this->pdo->query(
            'SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name '
            . 'FROM information_schema.COLUMNS c '
            . 'INNER JOIN information_schema.TABLES t '
            . "ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE' "
            . 'WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ('
            . "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'"
            . ') ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION',
        );

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['table_name'];
            if (in_array($table, self::EXCLUDED_TABLES, true)) {
                continue;
            }
            $tables[$table][] = (string) $row['column_name'];
        }
        ksort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * @param list<string> $header
     * @param list<int> $params
     * @return array{0: string, 1: int}
     */
    private function writeCsv(array $header, string $sql, array $params): array
    {
        $file = tempnam(sys_get_temp_dir(), 'cv-export-');
        $handle = $file === false ? false : fopen($file, 'wb');
        if ($file === false || $handle === false) {
            throw new RuntimeException('Não foi possível criar o temporário da exportação');
        }

        $rows = 0;
        try {
            fwrite($handle, self::BOM);
            fputcsv($handle, $header, ';', '"', '');

            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                fputcsv($handle, array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $row), ';', '"', '');
                $rows++;
            }
            $statement->closeCursor();
        } finally {
            fclose($handle);
        }

        return [$file, $rows];
    }

    private function addFile(ZipArchive $zip, string $file, string $name): void
    {
        if (!$zip->addFile($file, $name)) {
            throw new RuntimeException('Não foi possível anexar ' . $name . ' à exportação');
        }
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

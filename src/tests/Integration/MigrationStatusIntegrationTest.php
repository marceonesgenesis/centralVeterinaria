<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Operations\MigrationStatus;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 4, T-03: MigrationStatus compara as migrations numeradas de um
 * diretório com `schema_migrations` do banco da conexão, só lendo. O
 * diretório temporário traz 2 migrations reais (aplicadas no centralvet_test),
 * o `.verify.sql` de uma delas (ignorado), um arquivo fora do padrão
 * (ignorado) e `20990101_9999_x.sql` (pendente).
 */
final class MigrationStatusIntegrationTest extends MysqlIntegrationTestCase
{
    private const REAL = [
        '20261021_0017_etapa3_billing',
        '20261026_0018_billing_order_refund',
    ];

    private string $dir;

    public function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cv-migration-status-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        $source = dirname(__DIR__, 2) . '/app/database/migrations';
        foreach (self::REAL as $version) {
            copy($source . '/' . $version . '.sql', $this->dir . '/' . $version . '.sql');
        }
        copy($source . '/' . self::REAL[0] . '.verify.sql', $this->dir . '/' . self::REAL[0] . '.verify.sql');
        file_put_contents($this->dir . '/20990101_9999_x.sql', "SELECT 1;\n");
        file_put_contents($this->dir . '/20260919_add_missing_adianti_foreign_keys.sql', "SELECT 1;\n");
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testReportListsAppliedAndPendingVersionsOfTheTestDatabase(): void
    {
        $report = (new MigrationStatus($this->pdo, $this->dir))->report();

        Assert::same('centralvet_test', $report['database']);
        Assert::same(self::REAL, $report['applied']);
        Assert::same(['20990101_9999_x'], $report['pending']);
    }

    public function testVersionsInTheDatabaseWithoutFileAreUnknown(): void
    {
        $report = (new MigrationStatus($this->pdo, $this->dir))->report();

        Assert::true(in_array('20261021_0016_etapa2_sale_finance', $report['unknown'], true), 'versions without a file must be unknown');
        Assert::false(in_array('20261026_0018_billing_order_refund', $report['unknown'], true), 'applied versions are not unknown');
        $sorted = $report['unknown'];
        sort($sorted, SORT_STRING);
        Assert::same($sorted, $report['unknown'], 'unknown versions are sorted');
    }

    public function testDatabaseWithoutSchemaMigrationsThrows(): void
    {
        // information_schema: banco que existe e não tem schema_migrations.
        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=information_schema;charset=utf8mb4',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '3306',
            ),
            (string) (getenv('DB_USERNAME') ?: 'centralvet'),
            (string) (getenv('DB_PASSWORD') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $dir = $this->dir;

        Assert::throws(\RuntimeException::class, static function () use ($pdo, $dir): void {
            (new MigrationStatus($pdo, $dir))->report();
        });
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Privacy\TenantDataExporter;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 5, T-11: exportação LGPD dos dados da clínica em ZIP. Dois tenants
 * (A e B) com tutor e paciente cada, dois usuários em A; tudo na transação
 * do teste, desfeito no tearDown. O ZIP vai para um temporário removido no
 * fim de cada teste.
 */
final class TenantDataExporterIntegrationTest extends MysqlIntegrationTestCase
{
    private int $tenantA;
    private int $tenantB;
    private string $tutorNameA;
    private string $tutorNameB;
    private ?string $zipPath = null;

    public function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(4));
        $this->tenantA = $this->createTenant('a');
        $this->tenantB = $this->createTenant('b');
        $this->tutorNameA = 'T11 Tutor A ' . $suffix;
        $this->tutorNameB = 'T11 Tutor B ' . $suffix;

        $tutorA = $this->createTutor($this->tenantA, $this->tutorNameA);
        $tutorB = $this->createTutor($this->tenantB, $this->tutorNameB);
        $this->createPatient($this->tenantA, $tutorA, 'T11 Rex A ' . $suffix);
        $this->createPatient($this->tenantB, $tutorB, 'T11 Mia B ' . $suffix);

        $this->linkUser($this->tenantA, $this->createUser());
        $this->linkUser($this->tenantA, $this->createUser());
        $this->linkUser($this->tenantB, $this->createUser());
    }

    public function tearDown(): void
    {
        if ($this->zipPath !== null && is_file($this->zipPath)) {
            unlink($this->zipPath);
        }

        parent::tearDown();
    }

    public function testTablesAreDiscoveredFromInformationSchemaMinusExcluded(): void
    {
        $tables = (new TenantDataExporter($this->pdo))->tables();

        Assert::true(in_array('tutor', $tables, true), 'tables() deve conter tutor');
        Assert::true(in_array('patient', $tables, true), 'tables() deve conter patient');
        Assert::true(in_array('service', $tables, true), 'tables() deve conter service');
        Assert::false(in_array('billing_event', $tables, true), 'tables() não deve conter billing_event');
        Assert::false(in_array('feature_flag', $tables, true), 'tables() não deve conter feature_flag');

        $sorted = $tables;
        sort($sorted);
        Assert::same($sorted, $tables, 'tables() deve vir ordenado');
    }

    public function testExportWritesZipWithOnlyTenantRowsAndNoSensitiveColumns(): void
    {
        $exporter = new TenantDataExporter($this->pdo);
        $now = new \DateTimeImmutable('2026-10-26T15:00:00-03:00');
        $result = $exporter->export($this->tenantA, $this->tempZip(), $now);

        Assert::same(1, $result['tables']['tutor']);
        Assert::same(1, $result['tables']['patient']);
        Assert::same(2, $result['users']);

        $zip = new \ZipArchive();
        Assert::true($zip->open((string) $this->zipPath) === true, 'ZipArchive deve abrir o arquivo gerado');

        foreach (['manifest.json', 'users.csv', 'tutor.csv', 'patient.csv'] as $name) {
            Assert::true($zip->locateName($name) !== false, 'ZIP sem ' . $name);
        }

        $tutorCsv = (string) $zip->getFromName('tutor.csv');
        Assert::true(str_starts_with($tutorCsv, "\xEF\xBB\xBF"), 'CSV deve começar com BOM UTF-8');
        Assert::stringContains($this->tutorNameA, $tutorCsv);
        Assert::false(str_contains($tutorCsv, $this->tutorNameB), 'tutor.csv não pode ter o tutor de B');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '.csv')) {
                continue;
            }
            $header = $this->header((string) $zip->getFromName($name));
            foreach ($header as $column) {
                Assert::same(
                    0,
                    preg_match(TenantDataExporter::SENSITIVE_COLUMN_PATTERN, $column),
                    sprintf('%s tem coluna sensível %s', $name, $column),
                );
            }
        }

        $usersCsv = (string) $zip->getFromName('users.csv');
        $userHeader = $this->header($usersCsv);
        Assert::same(['id', 'name', 'login', 'email', 'status'], $userHeader);
        Assert::false(in_array('password', $userHeader, true), 'users.csv não pode ter password');
        Assert::count(2, $this->dataLines($usersCsv));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();

        Assert::same($this->tenantA, $manifest['tenant_id']);
        Assert::same(false, $manifest['files_included']);
        Assert::same(1, $manifest['tables']['tutor']);
        Assert::same(2, $manifest['users']);
        Assert::same($now->format(DATE_ATOM), $manifest['generated_at']);
        Assert::same(TenantDataExporter::EXCLUDED_TABLES, $manifest['excluded_tables']);
        Assert::true(is_array($manifest['excluded_columns']), 'manifest sem excluded_columns');
    }

    private function tempZip(): string
    {
        $this->zipPath = tempnam(sys_get_temp_dir(), 't11-export-') . '.zip';

        return $this->zipPath;
    }

    /** @return list<string> */
    private function header(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $firstLine = strtok($csv, "\r\n");

        return $firstLine === false ? [] : str_getcsv($firstLine, ';', '"', '');
    }

    /** @return list<string> */
    private function dataLines(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', $csv) ?: [], static fn (string $l): bool => $l !== ''));

        return array_slice($lines, 1);
    }

    private function createTenant(string $label): int
    {
        $slug = 't11-' . $label . '-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO tenant (public_id, slug, legal_name, status) VALUES (UUID(), :slug, :name, 'active')",
        )->execute(['slug' => $slug, 'name' => 'T-11 export tenant (' . $slug . ')']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTutor(int $tenantId, string $name): int
    {
        $this->pdo->prepare(
            'INSERT INTO tutor (tenant_id, public_id, full_name, phone) VALUES (:t, UUID(), :n, :p)',
        )->execute(['t' => $tenantId, 'n' => $name, 'p' => '11999990000']);

        return (int) $this->pdo->lastInsertId();
    }

    private function createPatient(int $tenantId, int $tutorId, string $name): void
    {
        $this->pdo->prepare(
            "INSERT INTO patient (tenant_id, tutor_id, name, species) VALUES (:t, :tu, :n, 'canina')",
        )->execute(['t' => $tenantId, 'tu' => $tutorId, 'n' => $name]);
    }

    /** system_users.id não é auto_increment (Adianti). */
    private function createUser(): int
    {
        $id = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM system_users')->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO system_users (id, name, login, email, password, active) VALUES (:id, :name, :login, :email, :password, 'Y')",
        )->execute([
            'id' => $id,
            'name' => 'T-11 user ' . $id,
            'login' => 't11-export-' . $id,
            'email' => 't11-export-' . $id . '@example.test',
            'password' => 'nao-exportar-' . $id,
        ]);

        return $id;
    }

    private function linkUser(int $tenantId, int $userId): void
    {
        $this->pdo->prepare(
            "INSERT INTO tenant_user (tenant_id, system_user_id, status) VALUES (:t, :u, 'active')",
        )->execute(['t' => $tenantId, 'u' => $userId]);
    }
}

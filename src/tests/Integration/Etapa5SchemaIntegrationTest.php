<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Etapa 5, T-01: a migration 20261026_0020_etapa5_piloto aplicada no
 * centralvet_test acrescenta o cancelamento agendado em `subscription` e cria
 * `legal_acceptance` (aceite versionado de termos e privacidade) e
 * `tenant_onboarding` (checklist de primeiros passos). Só lê
 * information_schema.
 */
final class Etapa5SchemaIntegrationTest extends MysqlIntegrationTestCase
{
    public function testSubscriptionHasScheduledCancellationColumns(): void
    {
        $columns = $this->columns('subscription');

        Assert::same('tinyint(1)|NO|0', $columns['cancel_at_period_end'] ?? null);
        Assert::same('timestamp(6)|YES|', $columns['cancel_requested_at'] ?? null);
        Assert::same('int|YES|', $columns['cancel_requested_by'] ?? null);

        $names = array_keys($columns);
        $cancelledAt = array_search('cancelled_at', $names, true);
        Assert::true(is_int($cancelledAt), 'subscription.cancelled_at must exist');
        Assert::same(
            ['cancel_at_period_end', 'cancel_requested_at', 'cancel_requested_by'],
            array_slice($names, $cancelledAt + 1, 3),
            'the new columns come right after cancelled_at'
        );
    }

    public function testLegalAcceptanceHasItsEightColumns(): void
    {
        Assert::same(
            ['id', 'tenant_id', 'system_user_id', 'document', 'version', 'accepted_at', 'ip', 'user_agent'],
            array_keys($this->columns('legal_acceptance'))
        );
    }

    public function testLegalAcceptanceHasUniqueUserDocumentVersionIndex(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT column_name, non_unique FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
             ORDER BY seq_in_index'
        );
        $statement->execute(['legal_acceptance', 'legal_acceptance_user_doc_version_uq']);
        $rows = $statement->fetchAll(\PDO::FETCH_NUM);

        Assert::same(
            ['system_user_id', 'document', 'version'],
            array_map(static fn (array $row): string => (string) $row[0], $rows)
        );
        foreach ($rows as $row) {
            Assert::same(0, (int) $row[1], 'legal_acceptance_user_doc_version_uq must be unique');
        }
    }

    public function testTenantOnboardingHasItsSixColumns(): void
    {
        Assert::same(
            ['tenant_id', 'clinic_confirmed_at', 'team_reviewed_at', 'guide_read_at', 'created_at', 'updated_at'],
            array_keys($this->columns('tenant_onboarding'))
        );
    }

    public function testMigrationIsRecorded(): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
        $statement->execute(['20261026_0020_etapa5_piloto']);

        Assert::same(1, (int) $statement->fetchColumn());
    }

    /**
     * @return array<string, string> column => "type|nullable|default", in ordinal order
     */
    private function columns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT column_name, column_type, is_nullable, column_default FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ?
             ORDER BY ordinal_position'
        );
        $statement->execute([$table]);

        $columns = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $columns[(string) $row[0]] = $row[1] . '|' . $row[2] . '|' . ($row[3] ?? '');
        }

        return $columns;
    }
}

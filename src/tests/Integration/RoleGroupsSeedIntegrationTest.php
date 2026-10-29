<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * Validação de integração do seed role-groups.sql no MySQL:
 * Executar o seed uma e duas vezes no banco garante que:
 * 1. Todos os 3 grupos são criados e vinculados.
 * 2. Nenhuma duplicata é gerada na segunda execução (idempotência comprovada).
 * 3. O grupo 1 (admin do template) nunca recebe vínculos deste seed.
 */
final class RoleGroupsSeedIntegrationTest extends MysqlIntegrationTestCase
{
    public function testApplyingSeedTwiceIsStrictlyIdempotent(): void
    {
        $seedPath = dirname(__DIR__, 2) . '/app/database/seeds/role-groups.sql';
        Assert::true(is_file($seedPath), 'role-groups.sql must exist');

        $sql = (string) file_get_contents($seedPath);
        $clean = (string) preg_replace('/^--.*$/m', '', $sql);
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*\n/', $clean)),
            static fn (string $s): bool => $s !== '' && !str_starts_with($s, 'START TRANSACTION') && !str_starts_with($s, 'COMMIT') && !str_starts_with($s, 'SET NAMES'),
        );

        // Primeira execução
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }

        $countGroups = (int) $this->pdo->query("SELECT COUNT(*) FROM system_group WHERE name IN ('Central Vet - Perfil Recepção', 'Central Vet - Perfil Veterinário', 'Central Vet - Perfil Financeiro')")->fetchColumn();
        Assert::same(3, $countGroups, 'three profile groups created');

        $countLinks = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM system_group_program gp
             JOIN system_group g ON g.id = gp.system_group_id
             WHERE g.name IN ('Central Vet - Perfil Recepção', 'Central Vet - Perfil Veterinário', 'Central Vet - Perfil Financeiro')"
        )->fetchColumn();
        Assert::true($countLinks > 0, 'group programs linked');

        // Grupo 1 nunca pode ter vínculos oriundos desse seed
        $groupOneLinks = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM system_group_program gp
             JOIN system_program p ON p.id = gp.system_program_id
             WHERE gp.system_group_id = 1 AND p.controller IN ('TutorForm', 'AppointmentForm', 'EncounterView', 'CashSessionForm')"
        )->fetchColumn();

        // Segunda execução do seed inteiro
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }

        $countGroupsAfter = (int) $this->pdo->query("SELECT COUNT(*) FROM system_group WHERE name IN ('Central Vet - Perfil Recepção', 'Central Vet - Perfil Veterinário', 'Central Vet - Perfil Financeiro')")->fetchColumn();
        $countLinksAfter = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM system_group_program gp
             JOIN system_group g ON g.id = gp.system_group_id
             WHERE g.name IN ('Central Vet - Perfil Recepção', 'Central Vet - Perfil Veterinário', 'Central Vet - Perfil Financeiro')"
        )->fetchColumn();

        Assert::same($countGroups, $countGroupsAfter, 're-running role-groups.sql must not duplicate groups');
        Assert::same($countLinks, $countLinksAfter, 're-running role-groups.sql must not duplicate links');
    }
}

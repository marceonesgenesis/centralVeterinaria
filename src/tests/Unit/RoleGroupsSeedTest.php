<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tests\Support\Assert;

/**
 * T-04 (Etapa 5): seed dos 3 grupos de perfil restrito. Cada grupo
 * "Central Vet - Perfil <Nome>" é criado por nome (idempotente) e liga
 * exatamente os controllers de ClinicRoleProfiles::programs(). Nunca o
 * grupo 1. Lê o SQL; o seed não é aplicado em banco.
 */
final class RoleGroupsSeedTest
{
    public function testHeaderAndTransaction(): void
    {
        $seed = $this->seed();

        Assert::true(str_starts_with($seed, '-- Prepared only; requires separate approval.'), 'header on first line');
        Assert::stringContains('START TRANSACTION;', $seed);
        Assert::stringContains('COMMIT;', $seed);
        Assert::true(strpos($seed, 'START TRANSACTION;') < strrpos($seed, 'COMMIT;'), 'COMMIT after START TRANSACTION');
        Assert::same(0, preg_match('/^\s*(DELETE|UPDATE|DROP|TRUNCATE|ALTER)\b/mi', $seed), 'seed must not delete or change');
    }

    public function testCreatesTheThreeGroupsByNameIdempotently(): void
    {
        $groupInserts = array_values(array_filter(
            $this->statements(),
            static fn (string $s): bool => str_starts_with($s, 'INSERT INTO system_group ('),
        ));
        Assert::count(3, $groupInserts, 'three group inserts');

        foreach (ClinicRoleProfiles::groupNames() as $name) {
            $found = false;
            foreach ($groupInserts as $statement) {
                if (str_contains($statement, "'" . $name . "' FROM DUAL")
                    && str_contains($statement, 'COALESCE(MAX(id),0)+1')
                    && str_contains($statement, "WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='" . $name . "')")
                ) {
                    $found = true;
                }
            }
            Assert::true($found, 'missing idempotent INSERT for group ' . $name);
        }
    }

    public function testEachGroupLinksExactlyTheProfilePrograms(): void
    {
        $links = $this->links();
        Assert::same(ClinicRoleProfiles::groupNames(), array_keys($links), 'only the three profile groups are linked');

        foreach (ClinicRoleProfiles::roles() as $role) {
            $group = ClinicRoleProfiles::GROUP_NAMES[$role];
            $actual = $links[$group] ?? [];
            sort($actual);
            Assert::same(ClinicRoleProfiles::programs($role), $actual, 'programs of ' . $group);
        }
    }

    public function testEveryLinkIsIdempotentAndNeverGroupOne(): void
    {
        $count = 0;
        foreach ($this->statements() as $statement) {
            if (!str_starts_with($statement, 'INSERT INTO system_group_program')) {
                continue;
            }
            $count++;
            Assert::stringContains('NOT EXISTS', $statement);
            Assert::stringContains('COALESCE(MAX(id),0)+1', $statement);
            Assert::same(0, preg_match('/system_group_id\s*=\s*1\b|,\s*1\s*,\s*p\.id/', $statement), 'no link to group 1');
        }
        Assert::true($count > 0, 'seed has link statements');
        Assert::same(0, preg_match('/system_group_id\s*=\s*1\b|,\s*1\s*,\s*p\.id/', $this->seed()), 'no reference to group 1');
    }

    /** @return array<string, list<string>> group name → controllers */
    private function links(): array
    {
        $links = [];
        foreach ($this->statements() as $statement) {
            if (!str_starts_with($statement, 'INSERT INTO system_group_program')) {
                continue;
            }
            if (preg_match("/g\.name='([^']+)'/", $statement, $group) !== 1
                || preg_match_all("/p\.controller='([^']+)'/", $statement, $controller) !== 1
            ) {
                throw new \RuntimeException('unparsable link statement: ' . $statement);
            }
            $links[$group[1]][] = $controller[1][0];
        }

        return $links;
    }

    /** @return list<string> */
    private function statements(): array
    {
        $lines = array_filter(
            explode("\n", $this->seed()),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $statements = array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
            explode(';', implode("\n", $lines)),
        );

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }

    private function seed(): string
    {
        $path = dirname(__DIR__, 2) . '/app/database/seeds/role-groups.sql';
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), 'missing file ' . $path);

        return (string) $source;
    }
}

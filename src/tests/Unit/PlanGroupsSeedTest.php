<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\PlanEntitlements;
use CentralVet\Tests\Support\Assert;

/**
 * T-11 (Etapa 3): seed dos 4 grupos de plano. Cada grupo
 * "Central Vet - Plano <Nome>" liga exatamente os controllers de
 * initial-application-programs.sql (menos LandingLeadList) que
 * PlanEntitlements::forPlan(<plano>)->allowsProgram() permite, mais
 * SystemUserList/SystemUserForm em todos e SystemUnitForm/SystemUnitList
 * onde o plano tem multi_unit. Lê o SQL; o seed não é aplicado em banco.
 */
final class PlanGroupsSeedTest
{
    private const GROUPS = [
        'starter' => 'Central Vet - Plano Starter',
        'pro' => 'Central Vet - Plano Pro',
        'business' => 'Central Vet - Plano Business',
        'enterprise' => 'Central Vet - Plano Enterprise',
    ];

    private const FORBIDDEN = ['LandingLeadList', 'SystemDatabaseExplorer', 'SystemSQLPanel', 'SystemGroupForm'];

    public function testHeaderTransactionAndIdempotency(): void
    {
        $seed = $this->seed();

        Assert::stringContains('Prepared only; requires separate approval', $seed);
        Assert::stringContains('START TRANSACTION;', $seed);
        Assert::stringContains('COMMIT;', $seed);
        Assert::stringContains('COALESCE(MAX(id),0)+1', $seed);
        Assert::true(substr_count($seed, 'NOT EXISTS') >= 4, 'idempotent inserts use NOT EXISTS');
        Assert::same(0, preg_match('/^\s*(DELETE|UPDATE)\b/mi', $seed), 'seed must not delete or update');
    }

    public function testCreatesTheFourGroupsIdempotently(): void
    {
        $statements = $this->statements();

        foreach (self::GROUPS as $name) {
            $found = false;
            foreach ($statements as $statement) {
                if (preg_match('/^INSERT INTO system_group \(/', $statement) === 1
                    && str_contains($statement, "'" . $name . "'")
                    && str_contains($statement, "WHERE NOT EXISTS")
                ) {
                    $found = true;
                }
            }
            Assert::true($found, 'missing idempotent INSERT for group ' . $name);
        }
    }

    public function testEachGroupLinksExactlyThePlanPrograms(): void
    {
        $links = $this->links();
        $base = array_values(array_diff($this->applicationControllers(), ['LandingLeadList']));

        foreach (self::GROUPS as $planId => $group) {
            $plan = PlanEntitlements::forPlan($planId);
            $expected = array_values(array_filter($base, static fn (string $c): bool => $plan->allowsProgram($c)));
            $expected[] = 'SystemUserList';
            $expected[] = 'SystemUserForm';
            if (in_array($planId, ['business', 'enterprise'], true)) {
                $expected[] = 'SystemUnitForm';
                $expected[] = 'SystemUnitList';
            }
            sort($expected);

            $actual = $links[$group] ?? [];
            sort($actual);

            Assert::same($expected, $actual, 'programs of ' . $group);
        }
    }

    public function testUnitProgramsOnlyInMultiUnitPlans(): void
    {
        $links = $this->links();

        foreach (['starter', 'pro'] as $planId) {
            $actual = $links[self::GROUPS[$planId]] ?? [];
            Assert::false(in_array('SystemUnitForm', $actual, true), $planId . ' must not link SystemUnitForm');
            Assert::false(in_array('SystemUnitList', $actual, true), $planId . ' must not link SystemUnitList');
        }
    }

    public function testNoGroupLinksPlatformAdministrationPrograms(): void
    {
        $links = $this->links();
        foreach (self::GROUPS as $group) {
            foreach (self::FORBIDDEN as $controller) {
                Assert::false(
                    in_array($controller, $links[$group] ?? [], true),
                    $group . ' must not link ' . $controller,
                );
            }
        }

        foreach ($this->statements() as $statement) {
            if (str_starts_with($statement, 'INSERT INTO system_group_program')) {
                Assert::same(0, preg_match('/system_group_id\s*=\s*1\b|,\s*1\s*,\s*p\.id/', $statement), 'no link to group 1');
            }
        }
    }

    public function testEveryLinkStatementIsIdempotent(): void
    {
        $count = 0;
        foreach ($this->statements() as $statement) {
            if (str_starts_with($statement, 'INSERT INTO system_group_program')) {
                $count++;
                Assert::stringContains('NOT EXISTS', $statement);
                Assert::stringContains('COALESCE(MAX(id),0)+1', $statement);
            }
        }
        Assert::true($count > 0, 'seed has link statements');
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
                || preg_match("/p\.controller='([^']+)'/", $statement, $controller) !== 1
            ) {
                throw new \RuntimeException('unparsable link statement: ' . $statement);
            }
            $links[$group[1]][] = $controller[1];
        }

        return $links;
    }

    /** @return list<string> */
    private function applicationControllers(): array
    {
        $source = $this->source(dirname(__DIR__, 2) . '/app/database/seeds/initial-application-programs.sql');
        preg_match_all("/INSERT INTO system_program \(id, name, controller\)\s*SELECT .*?, '[^']+', '([A-Za-z0-9_]+)'/s", $source, $matches);
        $controllers = array_values(array_unique($matches[1]));
        Assert::true(count($controllers) > 40, 'parsed application programs');
        Assert::true(in_array('LandingLeadList', $controllers, true), 'LandingLeadList parsed');

        return $controllers;
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
        return $this->source(dirname(__DIR__, 2) . '/app/database/seeds/plan-groups.sql');
    }

    private function source(string $path): string
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        Assert::true(is_string($source), 'missing file ' . $path);

        return (string) $source;
    }
}

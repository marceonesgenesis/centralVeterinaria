<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\TestDatabase;

final class TestDatabaseTest
{
    public function testFallsBackToApplicationDatabaseWhenNoTestDatabaseIsSet(): void
    {
        Assert::same('centralvet', TestDatabase::resolveName(['DB_DATABASE' => 'centralvet']));
    }

    public function testUsesTestDatabaseWhenSet(): void
    {
        Assert::same('centralvet_test', TestDatabase::resolveName([
            'DB_DATABASE' => 'centralvet',
            'TEST_DB_DATABASE' => 'centralvet_test',
        ]));
    }

    public function testRefusesTestDatabaseEqualToApplicationDatabase(): void
    {
        $message = null;

        try {
            TestDatabase::resolveName(['DB_DATABASE' => 'centralvet', 'TEST_DB_DATABASE' => 'centralvet']);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        Assert::same('Refusing to run: test MySQL database equals the application database (centralvet)', $message);
    }
}

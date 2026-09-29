<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Audit\AuditRedactor;
use CentralVet\Tests\Support\Assert;

final class AuditRedactorTest
{
    public function testRedactsKnownSensitiveKeys(): void
    {
        $result = AuditRedactor::redact([
            'password' => 'p4ss',
            'token' => 'abc',
            'cpf' => '123.456.789-00',
            'name' => 'Rex',
        ]);

        Assert::same('*****', $result['password']);
        Assert::same('*****', $result['token']);
        Assert::same('*****', $result['cpf']);
        Assert::same('Rex', $result['name']);
    }

    public function testRedactionIsCaseInsensitive(): void
    {
        $result = AuditRedactor::redact(['Password' => 'p4ss', 'CPF' => '111']);

        Assert::same('*****', $result['Password']);
        Assert::same('*****', $result['CPF']);
    }

    public function testRedactsNestedArraysRecursively(): void
    {
        $result = AuditRedactor::redact([
            'patient' => [
                'name' => 'Rex',
                'owner' => ['cpf' => '123', 'password' => 'x'],
            ],
        ]);

        Assert::same('Rex', $result['patient']['name']);
        Assert::same('*****', $result['patient']['owner']['cpf']);
        Assert::same('*****', $result['patient']['owner']['password']);
    }

    public function testLeavesNonSensitiveDataUntouched(): void
    {
        $data = ['species' => 'dog', 'weight_kg' => 12.5, 'active' => true, 'notes' => null];

        Assert::same($data, AuditRedactor::redact($data));
    }
}

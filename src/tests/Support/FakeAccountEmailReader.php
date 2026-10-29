<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Billing\Contract\AccountEmailReaderInterface;

/**
 * Em memória: o e-mail está tomado quando, com strtolower(trim(...)), está
 * em $taken. $calls conta as consultas.
 */
final class FakeAccountEmailReader implements AccountEmailReaderInterface
{
    /** @var list<string> */
    public array $taken = [];

    public int $calls = 0;

    public function isTaken(string $email): bool
    {
        $this->calls++;
        $needle = strtolower(trim($email));
        if ($needle === '') {
            return false;
        }

        foreach ($this->taken as $taken) {
            if (strtolower(trim($taken)) === $needle) {
                return true;
            }
        }

        return false;
    }
}

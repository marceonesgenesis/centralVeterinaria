<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

interface SessionContextSource
{
    public function get(string $key): mixed;
}

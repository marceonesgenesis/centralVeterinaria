<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use CentralVet\Tenancy\Exception\MissingTenantContext;

final class AdiantiSessionContextSource implements SessionContextSource
{
    public function get(string $key): mixed
    {
        if (!class_exists('TSession')) {
            throw new MissingTenantContext('Adianti session is not available');
        }

        return \TSession::getValue($key);
    }
}

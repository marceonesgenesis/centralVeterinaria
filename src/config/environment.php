<?php

declare(strict_types=1);

function envValue(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException("Required environment variable {$name} is not set");
    }

    return $value;
}

return [
    'app' => [
        'environment' => envValue('APP_ENV', 'development'),
        'debug' => filter_var(envValue('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
        'timezone' => envValue('APP_TIMEZONE', 'America/Fortaleza'),
    ],
    'database' => [
        'host' => envValue('DB_HOST'),
        'port' => (int) envValue('DB_PORT', '3306'),
        'database' => envValue('DB_DATABASE'),
        'username' => envValue('DB_USERNAME'),
        'password' => envValue('DB_PASSWORD'),
    ],
    'redis' => [
        'host' => envValue('REDIS_HOST'),
        'port' => (int) envValue('REDIS_PORT', '6379'),
        'database' => (int) envValue('REDIS_DATABASE', '0'),
    ],
];


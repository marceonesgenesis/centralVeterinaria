<?php

declare(strict_types=1);

return [
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_DATABASE') ?: 'centralvet',
    'user' => getenv('DB_USERNAME') ?: 'centralvet',
    'pass' => getenv('DB_PASSWORD') ?: '',
    'type' => 'mysql',
    'prep' => '1',
    'char' => 'utf8mb4',
];

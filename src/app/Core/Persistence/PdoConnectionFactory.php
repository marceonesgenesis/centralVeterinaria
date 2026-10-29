<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Opens a plain PDO connection outside the Adianti request cycle (worker
 * jobs and scheduler ticks), from the same environment keys as
 * `app/config/database.php`: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME,
 * DB_PASSWORD and DB_STRICT_MODE.
 *
 * Errors raise exceptions and prepares are native. A connection failure is
 * rethrown as a RuntimeException carrying only the driver error code, so
 * the password (or any part of the DSN) never reaches logs or the queue.
 */
final class PdoConnectionFactory
{
    private const STRICT_SQL_MODE = "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'";

    private function __construct()
    {
    }

    public static function fromEnvironment(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', 'mysql'),
            self::env('DB_PORT', '3306'),
            self::env('DB_DATABASE', 'centralvet'),
        );

        try {
            $connection = new PDO(
                $dsn,
                self::env('DB_USERNAME', 'centralvet'),
                self::env('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed (code ' . (string) $exception->getCode() . ')');
        }

        if (getenv('DB_STRICT_MODE') === 'true') {
            $connection->exec(self::STRICT_SQL_MODE);
        }

        return $connection;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}

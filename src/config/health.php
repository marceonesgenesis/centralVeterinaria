<?php

declare(strict_types=1);

function centralvet_handle_health_request(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/live' && $path !== '/health')
    {
        return false;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($path === '/live')
    {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'service' => 'centralvet'], JSON_THROW_ON_ERROR);
        return true;
    }

    $checks = ['mysql' => false, 'redis' => false];
    $config = require __DIR__ . '/environment.php';
    date_default_timezone_set($config['app']['timezone']);
    try
    {
        $database = $config['database'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']);
        $pdo = new PDO($dsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        $checks['mysql'] = $pdo->query('SELECT 1')->fetchColumn() === 1;
    }
    catch (Throwable $exception)
    {
        error_log(json_encode(['event' => 'mysql_readiness_check_failed', 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES));
    }

    try
    {
        $redisConfig = $config['redis'];
        $redis = new Redis();
        $redis->connect($redisConfig['host'], $redisConfig['port'], 2.0);
        $redis->select($redisConfig['database']);
        $checks['redis'] = (bool) $redis->ping();
        $redis->close();
    }
    catch (Throwable $exception)
    {
        error_log(json_encode(['event' => 'redis_readiness_check_failed', 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES));
    }

    $ready = !in_array(false, $checks, true);
    http_response_code($ready ? 200 : 503);
    echo json_encode(['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks], JSON_THROW_ON_ERROR);
    return true;
}

<?php

declare(strict_types=1);

/**
 * POST /lead.php — captura de leads da landing pública, sem Adianti e sem
 * sessão (não carrega init.php). Regras em
 * CentralVet\Landing\LeadSubmissionHandler; contrato em
 * CentralVet\Landing\LeadEndpoint.
 */

use CentralVet\Landing\LeadEndpoint;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadSubmissionHandler;
use CentralVet\Persistence\LeadRepository;
use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Security\LoginRateLimiter;

chdir(__DIR__);

/** @param array<string, string> $headers */
function centralvet_lead_emit(int $status, array $body, array $headers): void
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
    require __DIR__ . '/vendor/autoload.php';

    $config = require __DIR__ . '/config/environment.php';
    date_default_timezone_set($config['app']['timezone']);

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $redis = RedisConnectionFactory::fromEnvironment();
    $limiter = new LoginRateLimiter(
        $redis,
        (int) (getenv('LEAD_RATE_LIMIT_MAX_ATTEMPTS') ?: 10),
        (int) (getenv('LEAD_RATE_LIMIT_DECAY_SECONDS') ?: 3600),
        'centralvet:lead-throttle:',
    );

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($value)) {
            continue;
        }
        if (str_starts_with($key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        } elseif ($key === 'CONTENT_TYPE') {
            $headers['content-type'] = $value;
        }
    }

    $body = (string) file_get_contents('php://input', false, null, 0, LeadEndpoint::MAX_BODY_BYTES + 1);

    $response = (new LeadSubmissionHandler($limiter, new LeadFormToken($redis), new LeadRepository($pdo)))->handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $headers,
        $body,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        new DateTimeImmutable(),
    );

    centralvet_lead_emit($response->status, $response->body, $response->headers);
} catch (Throwable $exception) {
    error_log(sprintf('lead.php: %s: %s', $exception::class, $exception->getMessage()));
    centralvet_lead_emit(503, ['accepted' => false, 'error' => 'unavailable'], [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
}

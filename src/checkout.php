<?php

declare(strict_types=1);

/**
 * POST /checkout.php — início da compra pela landing pública (Etapa 3,
 * T-16), sem Adianti e sem sessão (não carrega init.php), no padrão de
 * lead.php. Regras e contrato em CentralVet\Billing\CheckoutStartHandler.
 */

use CentralVet\Billing\CheckoutStartHandler;
use CentralVet\Billing\Stripe\CurlStripeClient;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Landing\LeadEndpoint;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadSubmissionHandler;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Redis\RedisConnectionFactory;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PublicUrl;

chdir(__DIR__);

/** @param array<string, string> $headers */
function centralvet_checkout_emit(int $status, array $body, array $headers): void
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
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $early = LeadSubmissionHandler::precheck($method, $headers, $body);
    if ($early !== null) {
        centralvet_checkout_emit($early->status, $early->body, $early->headers);

        return;
    }

    $redis = RedisConnectionFactory::fromEnvironment();
    $limiter = new LoginRateLimiter(
        $redis,
        (int) (getenv('CHECKOUT_RATE_LIMIT_MAX_ATTEMPTS') ?: 10),
        (int) (getenv('CHECKOUT_RATE_LIMIT_DECAY_SECONDS') ?: 3600),
        'centralvet:checkout-throttle:',
    );

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $stripeConfig = StripeConfig::fromEnvironment();

    $handler = new CheckoutStartHandler(
        new LeadFormToken($redis),
        $limiter,
        new BillingOrderRepository($pdo),
        new CurlStripeClient($stripeConfig),
        $stripeConfig,
        PublicUrl::fromEnvironment(),
        static function (): string {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        },
    );

    $response = $handler->handle(
        $method,
        $headers,
        $body,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        new DateTimeImmutable(),
    );

    centralvet_checkout_emit($response->status, $response->body, $response->headers);
} catch (Throwable $exception) {
    error_log(sprintf('checkout.php: %s: %s', $exception::class, $exception->getMessage()));
    centralvet_checkout_emit(503, ['accepted' => false, 'error' => 'unavailable'], [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
}

<?php

declare(strict_types=1);

/**
 * POST /billing-webhook.php — eventos do Stripe (Etapa 3, T-17). Público e
 * sem sessão Adianti: autenticado só pela Stripe-Signature, exceção ao CSRF
 * registrada em docs/security/csrf-inventario.md. Carrega init.php apenas
 * para o _t() do convite (idioma pt). Regras e contrato em
 * CentralVet\Billing\BillingWebhookHandler e BillingEventIngestor.
 */

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingWebhookHandler;
use CentralVet\Billing\Stripe\CurlStripeClient;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Billing\TenantProvisioningService;
use CentralVet\Billing\TransactionRunner;
use CentralVet\Communication\EmailProviderFactory;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Persistence\BillingEventRepository;
use CentralVet\Persistence\BillingOrderRepository;
use CentralVet\Persistence\SubscriptionInvoiceRepository;
use CentralVet\Persistence\SubscriptionRepository;
use CentralVet\Security\PasswordResetToken;
use CentralVet\Security\PublicUrl;

chdir(__DIR__);

/** @param array<string, string> $headers */
function centralvet_billing_webhook_emit(int $status, array $body, array $headers): void
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
    require __DIR__ . '/init.php';
    ApplicationTranslator::setLanguage('pt');

    $config = require __DIR__ . '/config/environment.php';
    date_default_timezone_set($config['app']['timezone']);

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($value) && str_starts_with($key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }

    $body = (string) file_get_contents('php://input', false, null, 0, BillingWebhookHandler::MAX_BODY_BYTES + 1);

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $stripeConfig = StripeConfig::fromEnvironment();
    $logger = JsonLogger::fromEnvironment('billing-webhook');
    $tx = new TransactionRunner($pdo);
    $orders = new BillingOrderRepository($pdo);
    $subscriptions = new SubscriptionRepository($pdo);
    $ini = AdiantiApplicationConfig::get();

    $provisioning = new TenantProvisioningService(
        $pdo,
        $tx,
        $orders,
        $subscriptions,
        new AccountInviteService(
            new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']),
            PublicUrl::fromEnvironment(),
            EmailProviderFactory::fromEnvironment($logger),
            Closure::fromCallable('_t'),
        ),
    );

    $handler = new BillingWebhookHandler(
        new StripeSignatureVerifier($stripeConfig->webhookSecret()),
        new BillingEventIngestor(
            $tx,
            new BillingEventRepository($pdo),
            new BillingEventProcessor($orders, $subscriptions, new SubscriptionInvoiceRepository($pdo), new CurlStripeClient($stripeConfig)),
            $provisioning,
        ),
        $logger,
    );

    $response = $handler->handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $headers,
        $body,
        new DateTimeImmutable(),
    );

    centralvet_billing_webhook_emit($response->status, $response->body, $response->headers);
} catch (Throwable $exception) {
    error_log(sprintf('billing-webhook.php: %s', $exception::class));
    centralvet_billing_webhook_emit(500, ['error' => 'processing_failed'], [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
}

<?php

declare(strict_types=1);

/**
 * Reconciliação da cobrança (Etapa 3, T-18), para o cron da hospedagem
 * (a cada 15 minutos, a partir da raiz de src/): eventos do Stripe
 * perdidos, provisionamentos pendentes, faturas Pix e fim da tolerância.
 * Regras em CentralVet\Billing\BillingReconciler.
 *
 * Imprime `billing-reconcile events_fetched=<n> events_ingested=<n>
 * orders_fulfilled=<n> orders_failed=<n> pix_invoices_issued=<n>
 * pix_invoices_overdue=<n> pix_intents_refreshed=<n>
 * subscriptions_suspended=<n>` e sai 0; sai 1 em erro de Stripe ou de banco
 * (só a classe da exceção vai para o log, nunca a mensagem). Execuções
 * sobrepostas não acumulam: a trava de arquivo (`flock` no diretório
 * temporário, como `worker.php --once`) faz a segunda sair 0.
 */

use CentralVet\Billing\AccountInviteService;
use CentralVet\Billing\BillingEventIngestor;
use CentralVet\Billing\BillingEventProcessor;
use CentralVet\Billing\BillingReconciler;
use CentralVet\Billing\PixInvoiceIssuer;
use CentralVet\Billing\Stripe\CurlStripeClient;
use CentralVet\Billing\Stripe\StripeConfig;
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

// Só por linha de comando: em hospedagem Apache sem bloqueio de bin/, um GET
// anônimo dispararia a reconciliação (POST ao Stripe e e-mails).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

chdir(dirname(__DIR__));

$lockHandle = fopen(sys_get_temp_dir() . '/centralvet-billing-reconcile-' . md5(__DIR__) . '.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, 'billing-reconcile skipped_locked' . PHP_EOL);
    exit(0);
}

try {
    // init.php: autoload, configuração do Adianti e _t() (convite e e-mail do Pix em pt).
    require dirname(__DIR__) . '/init.php';
    ApplicationTranslator::setLanguage('pt');

    $config = require dirname(__DIR__) . '/config/environment.php';
    date_default_timezone_set($config['app']['timezone']);

    $database = $config['database'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
        $database['username'],
        $database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $logger = JsonLogger::fromEnvironment('billing-reconcile');
    $stripe = new CurlStripeClient(StripeConfig::fromEnvironment());
    $email = EmailProviderFactory::fromEnvironment($logger);
    $translate = Closure::fromCallable('_t');
    $ini = AdiantiApplicationConfig::get();

    $tx = new TransactionRunner($pdo);
    $orders = new BillingOrderRepository($pdo);
    $subscriptions = new SubscriptionRepository($pdo);
    $invoices = new SubscriptionInvoiceRepository($pdo);
    $events = new BillingEventRepository($pdo);

    $provisioning = new TenantProvisioningService(
        $pdo,
        $tx,
        $orders,
        $subscriptions,
        new AccountInviteService(
            new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']),
            PublicUrl::fromEnvironment(),
            $email,
            $translate,
        ),
    );

    $result = (new BillingReconciler(
        $stripe,
        $events,
        new BillingEventIngestor($tx, $events, new BillingEventProcessor($orders, $subscriptions, $invoices, $stripe), $provisioning),
        $orders,
        $subscriptions,
        $invoices,
        new PixInvoiceIssuer($stripe, $invoices, $email, $translate),
        $provisioning,
        $tx,
    ))->run(new DateTimeImmutable());
} catch (Throwable $exception) {
    error_log(sprintf('billing-reconcile.php: %s', $exception::class));
    fwrite(STDOUT, 'billing-reconcile error=' . $exception::class . PHP_EOL);
    exit(1);
}

$pairs = [];
foreach ($result as $name => $value) {
    $pairs[] = $name . '=' . $value;
}
fwrite(STDOUT, 'billing-reconcile ' . implode(' ', $pairs) . PHP_EOL);
exit(0);

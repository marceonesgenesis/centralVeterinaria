<?php

declare(strict_types=1);

/**
 * Fumaça da contratação (Etapa 4, T-06): gera um evento do Stripe assinado
 * com o segredo de TESTE do webhook e, com --send, faz o POST ao endpoint
 * local. Não fala com o Stripe. Roteiro em docs/runbooks/smoke-billing.md.
 *
 *   STRIPE_WEBHOOK_SECRET=whsec_test_etapa4 \
 *     php scripts/smoke/billing/webhook-event.php <tipo> <objeto-json> [--send <url>]
 *     [--id <evt_zz_e3_...>] [--timestamp <unix>]
 *
 * Imprime `event_id=`, `payload=` (JSON em uma linha) e `stripe_signature=`;
 * com --send, também `HTTP <status> <corpo>`. --id reenvia um evento com o
 * mesmo id (duplicado, reentrega); --timestamp assina com outro instante
 * (fora da tolerância de 300 s). Sai 2 com segredo vazio, tipo fora da lista
 * ou argumento inválido; 1 se o POST não completar.
 */

use CentralVet\Billing\Stripe\StripeSignatureVerifier;

const SMOKE_EVENT_TYPES = [
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed',
    'checkout.session.expired',
    'invoice.paid',
    'invoice.payment_failed',
    'payment_intent.succeeded',
    'charge.refunded',
    'customer.subscription.deleted',
];

function smoke_usage(string $error): never
{
    fwrite(STDERR, 'webhook-event: ' . $error . PHP_EOL);
    fwrite(STDERR, 'uso: php scripts/smoke/billing/webhook-event.php <tipo> <objeto-json> [--send <url>] [--id <evt_zz_e3_...>] [--timestamp <unix>]' . PHP_EOL);
    fwrite(STDERR, 'tipos: ' . implode(', ', SMOKE_EVENT_TYPES) . PHP_EOL);
    exit(2);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__, 3) . '/src/vendor/autoload.php';

$positional = [];
$options = ['send' => null, 'id' => null, 'timestamp' => null];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (str_starts_with($arg, '--')) {
        $name = substr($arg, 2);
        if (!array_key_exists($name, $options) || !isset($args[$i + 1])) {
            smoke_usage('opção inválida: ' . $arg);
        }
        $options[$name] = $args[++$i];
        continue;
    }
    $positional[] = $arg;
}

if (count($positional) !== 2) {
    smoke_usage('informe <tipo> e <objeto-json>');
}
[$type, $objectJson] = $positional;

if (!in_array($type, SMOKE_EVENT_TYPES, true)) {
    smoke_usage('tipo fora da lista: ' . $type);
}

$secret = (string) getenv('STRIPE_WEBHOOK_SECRET');
if ($secret === '') {
    smoke_usage('STRIPE_WEBHOOK_SECRET vazio (use o segredo de teste, ex.: whsec_test_etapa4)');
}

$object = json_decode($objectJson, false);
if (!$object instanceof stdClass) {
    smoke_usage('<objeto-json> precisa ser um objeto JSON');
}

$eventId = $options['id'] ?? 'evt_zz_e3_' . bin2hex(random_bytes(8));
if (!preg_match('/^evt_zz_e3_[A-Za-z0-9_]+$/', $eventId)) {
    smoke_usage('--id precisa começar com evt_zz_e3_');
}

$now = time();
$timestamp = $now;
if ($options['timestamp'] !== null) {
    if (!ctype_digit($options['timestamp'])) {
        smoke_usage('--timestamp precisa ser um unix timestamp');
    }
    $timestamp = (int) $options['timestamp'];
}

$payload = json_encode([
    'id' => $eventId,
    'object' => 'event',
    'type' => $type,
    'created' => $now,
    'livemode' => false,
    'data' => ['object' => $object],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$signature = StripeSignatureVerifier::header($payload, $secret, $timestamp);

fwrite(STDOUT, 'event_id=' . $eventId . PHP_EOL);
fwrite(STDOUT, 'payload=' . $payload . PHP_EOL);
fwrite(STDOUT, 'stripe_signature=' . $signature . PHP_EOL);

if ($options['send'] === null) {
    exit(0);
}

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nStripe-Signature: " . $signature . "\r\n",
    'content' => $payload,
    'ignore_errors' => true,
    'timeout' => 30,
]]);
$body = @file_get_contents($options['send'], false, $context);
$responseHeaders = function_exists('http_get_last_response_headers')
    ? (http_get_last_response_headers() ?? [])
    : ($http_response_header ?? []);
$statusLine = $responseHeaders[0] ?? '';
if ($body === false || !preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $match)) {
    fwrite(STDERR, 'webhook-event: POST não completou para ' . $options['send'] . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'HTTP ' . $match[1] . ' ' . trim($body) . PHP_EOL);
exit(0);

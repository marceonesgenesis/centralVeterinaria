<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 4, T-06: o gerador versionado de eventos assinados da fumaça da
 * contratação (scripts/smoke/billing/webhook-event.php) imprime um payload
 * que o StripeSignatureVerifier aceita e recusa segredo vazio ou tipo fora da
 * lista com código 2. Segredo só de teste (whsec_test_etapa4).
 */
final class SmokeWebhookEventTest
{
    private const SECRET = 'whsec_test_etapa4';

    public function testSignedInvoicePaidEventIsAcceptedByTheVerifier(): void
    {
        $result = $this->run(['invoice.paid', '{"id":"in_zz_e3_t"}'], self::SECRET);

        Assert::same(0, $result['code'], 'webhook-event.php must exit 0: ' . $result['output']);
        Assert::true((bool) preg_match('/^event_id=(evt_zz_e3_\S+)$/m', $result['output'], $id), 'event_id= line: ' . $result['output']);
        Assert::true((bool) preg_match('/^payload=(.+)$/m', $result['output'], $payload), 'payload= line: ' . $result['output']);
        Assert::true((bool) preg_match('/^stripe_signature=(t=\d+,v1=[0-9a-f]{64})$/m', $result['output'], $signature), 'stripe_signature= line: ' . $result['output']);

        (new StripeSignatureVerifier(self::SECRET))->verify($payload[1], $signature[1], time());

        $event = json_decode($payload[1], true);
        Assert::true(is_array($event), 'payload must be JSON');
        Assert::stringContains('"type":"invoice.paid"', $payload[1]);
        Assert::stringContains('"livemode":false', $payload[1]);
        Assert::same($id[1], $event['id']);
        Assert::same('event', $event['object']);
        Assert::same(['id' => 'in_zz_e3_t'], $event['data']['object']);
    }

    public function testEmptySecretExitsWithCodeTwo(): void
    {
        $result = $this->run(['invoice.paid', '{"id":"in_zz_e3_t"}'], '');

        Assert::same(2, $result['code'], 'empty STRIPE_WEBHOOK_SECRET must exit 2: ' . $result['output']);
        Assert::false(str_contains($result['output'], 'stripe_signature='), 'no signature without a secret');
    }

    public function testUnknownEventTypeExitsWithCodeTwo(): void
    {
        $result = $this->run(['customer.created', '{"id":"cus_zz_e3_t"}'], self::SECRET);

        Assert::same(2, $result['code'], 'customer.created must exit 2: ' . $result['output']);
        Assert::false(str_contains($result['output'], 'stripe_signature='), 'no signature for an unknown type');
    }

    /**
     * @param list<string> $args
     * @return array{code: int, output: string}
     */
    private function run(array $args, string $secret): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/smoke/billing/webhook-event.php';
        $command = array_merge([PHP_BINARY, $script], $args);
        $env = ['STRIPE_WEBHOOK_SECRET' => $secret, 'PATH' => (string) getenv('PATH')];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        Assert::true(is_resource($process), 'proc_open must start php');
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}

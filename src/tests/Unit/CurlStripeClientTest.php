<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Stripe\CurlStripeClient;
use CentralVet\Billing\Stripe\StripeApiException;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStripeClient;

/**
 * Etapa 3, T-08: own Stripe HTTP client. The $transport closure replaces
 * cURL, so no test touches the network. The key below is a dummy.
 */
final class CurlStripeClientTest
{
    private const SECRET = 'sk_test_dummy';

    /** @var list<array{method: string, url: string, headers: array<int|string, string>, body: string}> */
    private array $sent = [];

    /** @param array{status: int, body: string} $response */
    private function client(array $response): CurlStripeClient
    {
        $this->sent = [];

        return new CurlStripeClient(
            new StripeConfig(self::SECRET, 'whsec_test_etapa3', ['starter' => 'price_x']),
            function (string $method, string $url, array $headers, string $body) use ($response): array {
                $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return $response;
            },
        );
    }

    /** @param array<int|string, string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $line = is_string($key) ? $key . ': ' . $value : $value;
            [$headerName, $headerValue] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($headerName, $name) === 0) {
                return $headerValue;
            }
        }

        return null;
    }

    public function testPostSendsFormEncodedNestedParamsAndTheRequiredHeaders(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{"id":"cs_1","url":"https://checkout.stripe.com/c/pay/cs_1"}']);

        $result = $client->request('POST', '/v1/checkout/sessions', [
            'mode' => 'subscription',
            'line_items' => [['price' => 'price_x', 'quantity' => 1]],
            'metadata' => ['billing_order' => 42],
        ], 'k1');

        Assert::same(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'], $result);
        Assert::count(1, $this->sent);
        $sent = $this->sent[0];
        Assert::same('POST', $sent['method']);
        Assert::same('https://api.stripe.com/v1/checkout/sessions', $sent['url']);
        parse_str($sent['body'], $decoded);
        Assert::same('price_x', $decoded['line_items'][0]['price'] ?? null);
        Assert::stringContains('line_items%5B0%5D%5Bprice%5D=price_x', $sent['body']);
        Assert::stringContains('metadata%5Bbilling_order%5D=42', $sent['body']);
        Assert::same('Bearer ' . self::SECRET, $this->header($sent['headers'], 'Authorization'));
        Assert::same('2024-06-20', $this->header($sent['headers'], 'Stripe-Version'));
        Assert::same(StripeConfig::API_VERSION, $this->header($sent['headers'], 'Stripe-Version'));
        Assert::same('k1', $this->header($sent['headers'], 'Idempotency-Key'));
        Assert::same('application/x-www-form-urlencoded', $this->header($sent['headers'], 'Content-Type'));
    }

    public function testGetPutsParamsInTheQueryStringAndOmitsIdempotencyKey(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{"object":"list","data":[]}']);

        $client->request('GET', '/v1/events', ['type' => 'invoice.paid', 'created' => ['gte' => 100]]);

        $sent = $this->sent[0];
        Assert::same('GET', $sent['method']);
        Assert::same('https://api.stripe.com/v1/events?type=invoice.paid&created%5Bgte%5D=100', $sent['url']);
        Assert::same('', $sent['body']);
        Assert::null($this->header($sent['headers'], 'Idempotency-Key'));
    }

    public function testBooleansAreSentAsTrueFalse(): void
    {
        $client = $this->client(['status' => 200, 'body' => '{}']);

        $client->request('POST', '/v1/customers', ['tax_exempt' => false, 'flag' => true]);

        Assert::same('tax_exempt=false&flag=true', $this->sent[0]['body']);
    }

    public function testHttpErrorBecomesStripeApiExceptionWithoutTheKey(): void
    {
        $client = $this->client(['status' => 402, 'body' => '{"error":{"code":"card_declined","message":"Your card was declined."}}']);

        try {
            $client->request('POST', '/v1/payment_intents', ['amount' => 100]);
        } catch (StripeApiException $e) {
            Assert::same(402, $e->httpStatus());
            Assert::same('card_declined', $e->stripeCode());
            Assert::false(str_contains($e->getMessage(), 'sk_'), 'Message must not leak the key: ' . $e->getMessage());
            Assert::false(str_contains($e->getMessage(), self::SECRET));

            return;
        }

        throw new \RuntimeException('StripeApiException was not thrown');
    }

    public function testNetworkFailureBecomesStripeApiExceptionWithStatusZero(): void
    {
        $client = new CurlStripeClient(
            new StripeConfig(self::SECRET, '', []),
            static function (): array {
                throw new \RuntimeException('Could not resolve host api.stripe.com (Bearer ' . self::SECRET . ')');
            },
        );

        try {
            $client->request('GET', '/v1/subscriptions/sub_1');
        } catch (StripeApiException $e) {
            Assert::same(0, $e->httpStatus());
            Assert::null($e->stripeCode());
            Assert::false(str_contains($e->getMessage(), 'sk_'), 'Message must not leak the key: ' . $e->getMessage());

            return;
        }

        throw new \RuntimeException('StripeApiException was not thrown');
    }

    public function testInvalidJsonResponseIsAnError(): void
    {
        $client = $this->client(['status' => 200, 'body' => '<html>bad gateway</html>']);

        Assert::throws(StripeApiException::class, fn () => $client->request('GET', '/v1/events'));
    }

    public function testConfigPriceLookupAndEnvironment(): void
    {
        $config = new StripeConfig(self::SECRET, 'whsec_test_etapa3', ['starter' => 'price_x', 'pro' => '']);

        Assert::same(self::SECRET, $config->secretKey());
        Assert::same('whsec_test_etapa3', $config->webhookSecret());
        Assert::same('price_x', $config->priceIdFor('starter'));

        try {
            $config->priceIdFor('pro');
            throw new \RuntimeException('Expected RuntimeException for an empty price');
        } catch (\RuntimeException $e) {
            Assert::same('Stripe price for plan "pro" is not configured', $e->getMessage());
        }
        Assert::throws(\RuntimeException::class, fn () => $config->priceIdFor('enterprise'));

        $names = ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_PRICE_STARTER', 'STRIPE_PRICE_PRO', 'STRIPE_PRICE_BUSINESS', 'STRIPE_PRICE_ENTERPRISE'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
        }

        try {
            putenv('STRIPE_SECRET_KEY=' . self::SECRET);
            putenv('STRIPE_WEBHOOK_SECRET=whsec_test_etapa3');
            putenv('STRIPE_PRICE_STARTER=price_s');
            putenv('STRIPE_PRICE_PRO=price_p');
            putenv('STRIPE_PRICE_BUSINESS=price_b');
            putenv('STRIPE_PRICE_ENTERPRISE=');

            $fromEnv = StripeConfig::fromEnvironment();
            Assert::same(self::SECRET, $fromEnv->secretKey());
            Assert::same('whsec_test_etapa3', $fromEnv->webhookSecret());
            Assert::same('price_s', $fromEnv->priceIdFor('starter'));
            Assert::same('price_p', $fromEnv->priceIdFor('pro'));
            Assert::same('price_b', $fromEnv->priceIdFor('business'));
            Assert::throws(\RuntimeException::class, fn () => $fromEnv->priceIdFor('enterprise'));
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testFakeClientReplaysQueuedResponsesAndRecordsCalls(): void
    {
        $fake = new FakeStripeClient();
        $fake->queue('POST', '/v1/customers', ['id' => 'cus_1']);
        $fake->fail('GET', '/v1/subscriptions/sub_1', new StripeApiException('Stripe request failed', 404, 'resource_missing'));

        Assert::same(['id' => 'cus_1'], $fake->request('POST', '/v1/customers', ['email' => 'a@example.invalid'], 'k1'));
        Assert::throws(StripeApiException::class, fn () => $fake->request('GET', '/v1/subscriptions/sub_1'));
        Assert::throws(\LogicException::class, fn () => $fake->request('POST', '/v1/customers'));
        Assert::same([
            ['method' => 'POST', 'path' => '/v1/customers', 'params' => ['email' => 'a@example.invalid'], 'idempotencyKey' => 'k1'],
            ['method' => 'GET', 'path' => '/v1/subscriptions/sub_1', 'params' => [], 'idempotencyKey' => null],
            ['method' => 'POST', 'path' => '/v1/customers', 'params' => [], 'idempotencyKey' => null],
        ], $fake->calls);
    }
}

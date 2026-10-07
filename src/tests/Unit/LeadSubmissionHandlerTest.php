<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LeadEndpoint;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadResponse;
use CentralVet\Landing\LeadSubmissionHandler;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeLeadStore;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Rules of the public POST /lead.php, in the order the handler applies them:
 * method, size, content type, origin, rate limit, token, JSON, honeypot,
 * validation, single-use consume and store failure without leaking.
 */
final class LeadSubmissionHandlerTest
{
    private const IP = '203.0.113.7';
    private const ISSUED_AT = 1_000_000;

    private FakeRedis $redis;
    private LeadFormToken $tokens;
    private FakeLeadStore $store;
    private LeadSubmissionHandler $handler;

    public function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }

        $this->redis = new FakeRedis();
        $this->tokens = new LeadFormToken($this->redis);
        $this->store = new FakeLeadStore();
        $this->handler = $this->handlerWith($this->store);
    }

    public function testValidLeadIsStoredOnceAndTokenCannotBeReused(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);

        $first = $this->post($this->validPayload(), $token);

        Assert::same(201, $first->status);
        Assert::same(['accepted' => true], $first->body);
        Assert::same('application/json; charset=utf-8', $first->headers['Content-Type'] ?? null);
        Assert::same('no-store', $first->headers['Cache-Control'] ?? null);
        Assert::count(1, $this->store->inserted);
        Assert::same(9700, $this->store->inserted[0]['lead']->planPriceCents);
        Assert::same(self::IP, $this->store->inserted[0]['ip']);

        $second = $this->post($this->validPayload(), $token);

        Assert::same(403, $second->status);
        Assert::same(['accepted' => false, 'error' => 'invalid_token'], $second->body);
        Assert::count(1, $this->store->inserted, 'Reused token must not store again');
    }

    public function testHoneypotAnswers200WithoutStoring(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $payload = $this->validPayload();
        $payload[LeadEndpoint::HONEYPOT_FIELD] = 'http://spam.example';

        $response = $this->post($payload, $token);

        Assert::same(200, $response->status);
        Assert::same(['accepted' => true], $response->body);
        Assert::count(0, $this->store->inserted);
        Assert::false($this->tokens->isUsable($token, self::ISSUED_AT + 10), 'Honeypot must consume the token');
    }

    public function testGetAnswers405WithAllowHeader(): void
    {
        $response = $this->handler->handle('GET', [], '', self::IP, $this->now());

        Assert::same(405, $response->status);
        Assert::same('POST', $response->headers['Allow'] ?? null);
        Assert::same(['accepted' => false, 'error' => 'method_not_allowed'], $response->body);
        Assert::same('no-store', $response->headers['Cache-Control'] ?? null);
    }

    public function testBodyLargerThanLimitAnswers413(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $body = str_repeat('a', LeadEndpoint::MAX_BODY_BYTES + 1);

        $response = $this->handler->handle('POST', $this->headers($token), $body, self::IP, $this->now());

        Assert::same(413, $response->status);
        Assert::same(['accepted' => false, 'error' => 'payload_too_large'], $response->body);
    }

    public function testNonJsonContentTypeAnswers400(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $headers = $this->headers($token);
        $headers['content-type'] = 'application/x-www-form-urlencoded';

        $response = $this->handler->handle('POST', $headers, $this->json($this->validPayload()), self::IP, $this->now());

        Assert::same(400, $response->status);
        Assert::same(['accepted' => false, 'error' => 'invalid_request'], $response->body);
    }

    public function testForeignOriginAnswers403ForbiddenOrigin(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $headers = $this->headers($token);
        $headers['origin'] = 'https://evil.example';

        $response = $this->handler->handle('POST', $headers, $this->json($this->validPayload()), self::IP, $this->now());

        Assert::same(403, $response->status);
        Assert::same(['accepted' => false, 'error' => 'forbidden_origin'], $response->body);
        Assert::count(0, $this->store->inserted);
    }

    public function testCrossSiteFetchMetadataAnswers403ForbiddenOrigin(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $headers = $this->headers($token);
        $headers['sec-fetch-site'] = 'cross-site';

        $response = $this->handler->handle('POST', $headers, $this->json($this->validPayload()), self::IP, $this->now());

        Assert::same(403, $response->status);
        Assert::same(['accepted' => false, 'error' => 'forbidden_origin'], $response->body);
    }

    public function testEleventhPostFromSameIpAnswers429WithRetryAfter(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->post($this->validPayload(), 'missing-token');
            Assert::same(403, $response->status, "POST {$i} must reach the token check");
        }

        $response = $this->post($this->validPayload(), 'missing-token');

        Assert::same(429, $response->status);
        Assert::same('rate_limited', $response->body['error'] ?? null);
        Assert::same(3600, $response->body['retry_after'] ?? null);
        Assert::same('3600', $response->headers['Retry-After'] ?? null);
    }

    public function testMissingTokenAnswers403InvalidToken(): void
    {
        $headers = $this->headers('');
        unset($headers['x-cv-lead-token']);

        $response = $this->handler->handle('POST', $headers, '{}', self::IP, $this->now());

        Assert::same(403, $response->status);
        Assert::same(['accepted' => false, 'error' => 'invalid_token'], $response->body);
    }

    public function testBodyThatIsNotAJsonObjectAnswers400(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);

        foreach (['not json', '[1,2]', '"text"'] as $body) {
            $response = $this->handler->handle('POST', $this->headers($token), $body, self::IP, $this->now());

            Assert::same(400, $response->status, "Body {$body} must be refused");
            Assert::same(['accepted' => false, 'error' => 'invalid_request'], $response->body);
        }
    }

    public function testInvalidEmailAnswers422AndKeepsTokenForTheRetry(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);
        $payload = $this->validPayload();
        $payload['email'] = 'not-an-email';

        $invalid = $this->post($payload, $token);

        Assert::same(422, $invalid->status);
        Assert::same(false, $invalid->body['accepted'] ?? null);
        Assert::same('validation', $invalid->body['error'] ?? null);
        Assert::true(isset($invalid->body['fields']['email']), 'fields.email must be present');
        Assert::count(0, $this->store->inserted);

        $fixed = $this->post($this->validPayload(), $token);

        Assert::same(201, $fixed->status);
        Assert::count(1, $this->store->inserted);
    }

    public function testForgedPriceInPayloadIsStoredWithCatalogPrice(): void
    {
        $token = $this->tokens->issue(self::ISSUED_AT);

        $response = $this->post(['plan_price_cents' => 1, 'price_cents' => 1] + $this->validPayload(), $token);

        Assert::same(201, $response->status);
        Assert::count(1, $this->store->inserted);
        Assert::same(9700, $this->store->inserted[0]['lead']->planPriceCents);
    }

    public function testStoreFailureAnswers503WithoutLeakingTheMessage(): void
    {
        $handler = $this->handlerWith(new FakeLeadStore(new \RuntimeException('SQLSTATE[HY000] segredo')));
        $token = $this->tokens->issue(self::ISSUED_AT);

        $previousLog = ini_set('error_log', '/dev/null');
        try {
            $response = $handler->handle('POST', $this->headers($token), $this->json($this->validPayload()), self::IP, $this->now());
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        Assert::same(503, $response->status);
        Assert::same(['accepted' => false, 'error' => 'unavailable'], $response->body);
        $encoded = (string) json_encode($response->body);
        Assert::false(str_contains($encoded, 'SQLSTATE'), 'Body must not carry the exception message');
        Assert::false(str_contains($encoded, 'segredo'), 'Body must not carry the exception message');
    }

    private function handlerWith(FakeLeadStore $store): LeadSubmissionHandler
    {
        return new LeadSubmissionHandler(
            new LoginRateLimiter($this->redis, 10, 3600, 'centralvet:lead-throttle:'),
            $this->tokens,
            $store,
        );
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload, string $token): LeadResponse
    {
        return $this->handler->handle('POST', $this->headers($token), $this->json($payload), self::IP, $this->now());
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return [
            'content-type' => 'application/json',
            'origin' => 'http://127.0.0.1:8081',
            'host' => '127.0.0.1:8081',
            'sec-fetch-site' => 'same-origin',
            'x-cv-lead-token' => $token,
        ];
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'name' => 'LP teste Maria',
            'clinic' => 'Clínica Patas',
            'email' => 'maria@example.com',
            'phone' => '(85) 99999-1234',
            'vets' => '2-4',
            'city' => 'Fortaleza',
            'uf' => 'CE',
            'plan' => 'pro',
            'consent' => true,
            'website' => '',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return (string) json_encode($payload);
    }

    private function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp(self::ISSUED_AT + 10);
    }
}

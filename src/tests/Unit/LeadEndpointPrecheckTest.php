<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\Contract\LeadStoreInterface;
use CentralVet\Landing\LazyLeadStore;
use CentralVet\Landing\LeadEndpoint;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadSubmissionHandler;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeLeadStore;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * lead.php answers 405/413/400/403 through LeadSubmissionHandler::precheck()
 * before opening Redis or MySQL, and LazyLeadStore only builds the real
 * store (PDO) on the first insert.
 */
final class LeadEndpointPrecheckTest
{
    private const IP = '203.0.113.7';
    private const ISSUED_AT = 1_000_000;

    public function testGetAnswers405WithAllowHeader(): void
    {
        $response = LeadSubmissionHandler::precheck('GET', [], '');

        Assert::notNull($response);
        Assert::same(405, $response->status);
        Assert::same(['accepted' => false, 'error' => 'method_not_allowed'], $response->body);
        Assert::same('POST', $response->headers['Allow'] ?? null);
    }

    public function testSameOriginJsonPostPassesThrough(): void
    {
        Assert::null(LeadSubmissionHandler::precheck('POST', $this->headers(), '{}'));
    }

    public function testOversizedBodyAnswers413(): void
    {
        $response = LeadSubmissionHandler::precheck('POST', $this->headers(), str_repeat('a', LeadEndpoint::MAX_BODY_BYTES + 1));

        Assert::notNull($response);
        Assert::same(413, $response->status);
        Assert::same(['accepted' => false, 'error' => 'payload_too_large'], $response->body);
    }

    public function testPlainTextAnswers400(): void
    {
        $headers = $this->headers();
        $headers['content-type'] = 'text/plain';

        $response = LeadSubmissionHandler::precheck('POST', $headers, '{}');

        Assert::notNull($response);
        Assert::same(400, $response->status);
        Assert::same(['accepted' => false, 'error' => 'invalid_request'], $response->body);
    }

    public function testForeignOriginAnswers403(): void
    {
        $headers = $this->headers();
        $headers['origin'] = 'https://evil.example';

        $response = LeadSubmissionHandler::precheck('POST', $headers, '{}');

        Assert::notNull($response);
        Assert::same(403, $response->status);
        Assert::same(['accepted' => false, 'error' => 'forbidden_origin'], $response->body);
    }

    public function testLazyStoreIsBuiltOnlyOnFirstInsert(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }

        $redis = new FakeRedis();
        $tokens = new LeadFormToken($redis);
        $calls = 0;
        $fake = new FakeLeadStore();
        $handler = new LeadSubmissionHandler(
            new LoginRateLimiter($redis, 10, 3600, 'centralvet:lead-throttle:'),
            $tokens,
            new LazyLeadStore(static function () use (&$calls, $fake): LeadStoreInterface {
                $calls++;

                return $fake;
            }),
        );
        $now = (new \DateTimeImmutable())->setTimestamp(self::ISSUED_AT + 10);
        $body = (string) json_encode($this->validPayload());

        $noToken = $handler->handle('POST', $this->headers(), $body, self::IP, $now);
        Assert::same(403, $noToken->status);
        Assert::same(0, $calls, 'Store must not be built before a valid submission');

        $first = $handler->handle('POST', $this->headers($tokens->issue(self::ISSUED_AT)), $body, self::IP, $now);
        Assert::same(201, $first->status);
        Assert::same(1, $calls);

        $second = $handler->handle('POST', $this->headers($tokens->issue(self::ISSUED_AT)), $body, self::IP, $now);
        Assert::same(201, $second->status);
        Assert::same(1, $calls, 'Store factory must run only once');
        Assert::count(2, $fake->inserted);
    }

    /** @return array<string, string> */
    private function headers(?string $token = null): array
    {
        $headers = [
            'content-type' => 'application/json',
            'origin' => 'http://127.0.0.1:8081',
            'host' => '127.0.0.1:8081',
            'sec-fetch-site' => 'same-origin',
        ];
        if ($token !== null) {
            $headers['x-cv-lead-token'] = $token;
        }

        return $headers;
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
}

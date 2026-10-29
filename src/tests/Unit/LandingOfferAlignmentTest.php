<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\CheckoutStartHandler;
use CentralVet\Billing\OfferScope;
use CentralVet\Billing\Stripe\StripeConfig;
use CentralVet\Landing\LandingCatalog;
use CentralVet\Landing\LeadFormToken;
use CentralVet\Landing\LeadResponse;
use CentralVet\Pilot\PilotSettings;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Security\PublicUrl;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAccountEmailReader;
use CentralVet\Tests\Support\FakeBillingOrderRepository;
use CentralVet\Tests\Support\FakeRedis;
use CentralVet\Tests\Support\FakeStripeClient;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Etapa 5, T-13: landing e checkout alinhados ao recorte da oferta
 * (`OfferScope`) e aos documentos legais (Termos de Uso e Política de
 * Privacidade).
 */
final class LandingOfferAlignmentTest
{
    private const ALL_PLANS = ['starter', 'pro', 'business', 'enterprise'];

    private const SOON_LABELS = [
        'Comunicação e automações',
        'IA e agentes avançados',
        'Relatórios avançados',
        'Integrações',
        'SSO e SLA',
        'Governança da rede',
    ];

    private const IP = '203.0.113.9';
    private const ISSUED_AT = 1_000_000;

    public function testPublicJsonOnlyListsOfferedPlans(): void
    {
        $offer = new OfferScope(PilotSettings::fromArray(['OFFER_PLANS' => 'pro']));
        $data = json_decode(LandingCatalog::publicJson($offer), true, 512, JSON_THROW_ON_ERROR);

        Assert::same(['pro'], array_column($data['plans'], 'id'));
        Assert::true(count($data['compare']) > 0, 'compare must keep its rows');
        foreach ($data['compare'] as $row) {
            Assert::same(['pro'], array_keys($row['plans']), "{$row['label']} must only list the offered plan");
        }
    }

    public function testPilotDefaultOffersTheFourPlans(): void
    {
        $offer = new OfferScope(PilotSettings::fromArray([]));
        $data = json_decode(LandingCatalog::publicJson($offer), true, 512, JSON_THROW_ON_ERROR);

        Assert::same(self::ALL_PLANS, array_column($data['plans'], 'id'));
        foreach ($data['compare'] as $row) {
            Assert::same(self::ALL_PLANS, array_keys($row['plans']), "{$row['label']} must list the four plans");
        }
        Assert::same(self::ALL_PLANS, array_column(LandingCatalog::plans(), 'id'), 'plans() keeps the four plans for PlanEntitlements');
    }

    public function testPublicJsonLinksTheLegalDocumentsAndCurrentTermsVersion(): void
    {
        $data = json_decode(LandingCatalog::publicJson(new OfferScope(PilotSettings::fromArray([]))), true, 512, JSON_THROW_ON_ERROR);

        Assert::same('legal.php?doc=termos', $data['legal']['terms_url'] ?? null);
        Assert::same('legal.php?doc=privacidade', $data['legal']['privacy_url'] ?? null);
        Assert::same('termos-uso-2026-10', LandingCatalog::TERMS_VERSION);
        Assert::same(['version' => 'termos-uso-2026-10', 'text' => LandingCatalog::TERMS_TEXT], $data['terms']);
        Assert::stringContains('Termos de Uso e a Política de Privacidade', LandingCatalog::TERMS_TEXT);
    }

    public function testUnreleasedItemsAreMarkedSoonAndDedicatedSupportStaysAnnounced(): void
    {
        $seen = [];
        $support = 0;
        $entries = [];
        foreach (LandingCatalog::plans() as $plan) {
            foreach ($plan['items'] as $item) {
                $entries[] = $item;
            }
        }
        foreach (LandingCatalog::compareRows() as $row) {
            $entries[] = ['label' => $row['label'], 'soon' => $row['soon']];
        }

        foreach ($entries as $entry) {
            if (in_array($entry['label'], self::SOON_LABELS, true)) {
                Assert::true($entry['soon'], "{$entry['label']} must be marked soon");
                $seen[$entry['label']] = true;
            }
            if ($entry['label'] === 'Suporte dedicado') {
                Assert::false($entry['soon'], 'Suporte dedicado stays announced');
                $support++;
            }
        }

        Assert::same(count(self::SOON_LABELS), count($seen), 'every soon label must appear in the catalog');
        Assert::same(2, $support, 'Suporte dedicado appears in the Enterprise items and in the compare rows');
    }

    public function testTemplateLinksLegalDocumentsInFooterAndNearTermsCheckbox(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/app/view/landing/landing.html');

        Assert::same(1, preg_match('#<footer>(.*?)</footer>#s', $template, $footer), 'landing.html needs a <footer>');
        $terms = strpos($template, 'id="sub-terms"');
        Assert::true($terms !== false, 'sub-terms checkbox must exist');
        $near = substr($template, $terms, 800);

        foreach (['legal.php?doc=termos', 'legal.php?doc=privacidade'] as $url) {
            $link = '#<a [^>]*href="' . preg_quote($url, '#') . '"[^>]*target="_blank"[^>]*rel="noopener"#';
            Assert::same(1, preg_match($link, $footer[1]), "footer must link {$url} in a new tab");
            Assert::same(1, preg_match($link, $near), "checkout terms must link {$url} in a new tab");
        }

        foreach (['landing/landing.css', 'landing/translations.js', 'landing/landing.js'] as $asset) {
            Assert::stringContains($asset . '?v=20261026-piloto"', $template, "{$asset} needs the pilot cache buster");
        }
    }

    public function testCheckoutAcceptsEveryPlanWithThePilotDefault(): void
    {
        $this->requireRedis();
        $offer = new OfferScope(PilotSettings::fromArray([]));

        foreach (['starter', 'enterprise'] as $planId) {
            [$handler, $tokens, , $stripe] = $this->handler($offer);
            $stripe->queue('POST', '/v1/customers', ['id' => 'cus_test_1']);
            $stripe->queue('POST', '/v1/checkout/sessions', ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.test/c/pay/cs_test_1']);

            $response = $this->post($handler, $tokens, $planId);

            Assert::false(isset($response->body['errors']['plan_id']), "{$planId} must be accepted");
            Assert::same(201, $response->status, "{$planId} must open the checkout");
        }
    }

    public function testCheckoutRefusesPlanOutsideTheOfferBeforeOrderAndStripe(): void
    {
        $this->requireRedis();
        [$handler, $tokens, $orders, $stripe] = $this->handler(new OfferScope(PilotSettings::fromArray(['OFFER_PLANS' => 'pro'])));

        $response = $this->post($handler, $tokens, 'starter');

        Assert::same(422, $response->status);
        Assert::same('Plano indisponível no momento.', $response->body['errors']['plan_id'] ?? null);
        Assert::null($orders->findByPublicId('order-1'));
        Assert::count(0, $stripe->calls);
    }

    private function requireRedis(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new SkippedTestException('ext-redis is not loaded in this PHP runtime');
        }
    }

    /** @return array{0: CheckoutStartHandler, 1: LeadFormToken, 2: FakeBillingOrderRepository, 3: FakeStripeClient} */
    private function handler(OfferScope $offer): array
    {
        $redis = new FakeRedis();
        $tokens = new LeadFormToken($redis);
        $orders = new FakeBillingOrderRepository();
        $stripe = new FakeStripeClient();
        $sequence = 0;

        $handler = new CheckoutStartHandler(
            $tokens,
            new LoginRateLimiter($redis, 10, 3600, 'centralvet:checkout-throttle:'),
            $orders,
            $stripe,
            new StripeConfig('placeholder-secret', 'placeholder-webhook', [
                'starter' => 'price_starter_placeholder',
                'pro' => 'price_pro_placeholder',
                'business' => 'price_business_placeholder',
                'enterprise' => 'price_enterprise_placeholder',
            ]),
            new PublicUrl('https://app.centralvet.test'),
            static function () use (&$sequence): string {
                return 'order-' . ++$sequence;
            },
            new FakeAccountEmailReader(),
            $offer,
        );

        return [$handler, $tokens, $orders, $stripe];
    }

    private function post(CheckoutStartHandler $handler, LeadFormToken $tokens, string $planId): LeadResponse
    {
        $payload = [
            'plan_id' => $planId,
            'payment_method' => 'card',
            'responsible_name' => 'Maria Souza',
            'responsible_email' => 'maria@example.test',
            'responsible_phone' => '(85) 99999-1234',
            'clinic_name' => 'Clínica Patas',
            'terms_accepted' => true,
            'terms_version' => LandingCatalog::TERMS_VERSION,
            'contact_consent' => false,
            'website' => '',
            'form_token' => $tokens->issue(self::ISSUED_AT),
        ];
        $headers = [
            'content-type' => 'application/json',
            'origin' => 'http://127.0.0.1:8081',
            'host' => '127.0.0.1:8081',
            'sec-fetch-site' => 'same-origin',
        ];
        $now = (new \DateTimeImmutable('@' . (self::ISSUED_AT + 10)))->setTimezone(new \DateTimeZone('America/Fortaleza'));

        return $handler->handle('POST', $headers, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), self::IP, $now);
    }
}

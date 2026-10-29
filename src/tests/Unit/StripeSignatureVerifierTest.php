<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Billing\Stripe\InvalidWebhookSignature;
use CentralVet\Billing\Stripe\StripeSignatureVerifier;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-08: Stripe-Signature (t=<ts>,v1=<HMAC-SHA256 of "<t>.<payload>">)
 * with a 300 s tolerance. The secret is a test-only value generated here.
 */
final class StripeSignatureVerifierTest
{
    private const SECRET = 'whsec_test_etapa3';
    private const PAYLOAD = '{"id":"evt_1","type":"checkout.session.completed"}';
    private const NOW = 1_790_000_000;

    private function verifier(): StripeSignatureVerifier
    {
        return new StripeSignatureVerifier(self::SECRET);
    }

    public function testAcceptsTheHeaderBuiltWithTheSameSecret(): void
    {
        $header = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW);

        Assert::same('t=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.' . self::PAYLOAD, self::SECRET), $header);

        $this->verifier()->verify(self::PAYLOAD, $header, self::NOW);
    }

    public function testAcceptsAHeaderWithSeveralSignaturesWhenOneMatches(): void
    {
        $valid = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW);
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',' . substr($valid, strpos($valid, 'v1='));

        $this->verifier()->verify(self::PAYLOAD, $header, self::NOW);
    }

    public function testRejectsAPayloadWithOneByteChanged(): void
    {
        $header = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW);
        $tampered = substr_replace(self::PAYLOAD, '2', strpos(self::PAYLOAD, '1'), 1);

        Assert::throws(InvalidWebhookSignature::class, fn () => $this->verifier()->verify($tampered, $header, self::NOW));
    }

    public function testRejectsAHeaderSignedWithAnotherSecret(): void
    {
        $header = StripeSignatureVerifier::header(self::PAYLOAD, 'whsec_test_other', self::NOW);

        Assert::throws(InvalidWebhookSignature::class, fn () => $this->verifier()->verify(self::PAYLOAD, $header, self::NOW));
    }

    public function testRejectsATimestampOutsideTheTolerance(): void
    {
        $old = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW - 301);
        $future = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW + 301);
        $edge = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW - 300);

        Assert::throws(InvalidWebhookSignature::class, fn () => $this->verifier()->verify(self::PAYLOAD, $old, self::NOW));
        Assert::throws(InvalidWebhookSignature::class, fn () => $this->verifier()->verify(self::PAYLOAD, $future, self::NOW));
        $this->verifier()->verify(self::PAYLOAD, $edge, self::NOW);
    }

    public function testRejectsAMissingOrMalformedHeader(): void
    {
        $verifier = $this->verifier();
        $valid = StripeSignatureVerifier::header(self::PAYLOAD, self::SECRET, self::NOW);
        $onlySignature = substr($valid, strpos($valid, 'v1='));

        foreach (['', '   ', 't=' . self::NOW, $onlySignature, 't=abc,' . $onlySignature, 'garbage'] as $header) {
            Assert::throws(
                InvalidWebhookSignature::class,
                fn () => $verifier->verify(self::PAYLOAD, $header, self::NOW),
                "Header '{$header}' should be rejected",
            );
        }
    }

    public function testRejectsEverythingWhenTheSecretIsNotConfigured(): void
    {
        $header = StripeSignatureVerifier::header(self::PAYLOAD, '', self::NOW);

        Assert::throws(InvalidWebhookSignature::class, fn () => (new StripeSignatureVerifier(''))->verify(self::PAYLOAD, $header, self::NOW));
    }
}

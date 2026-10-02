<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LandingCatalog;
use CentralVet\Landing\LeadSubmission;
use CentralVet\Landing\LeadValidationException;
use CentralVet\Tests\Support\Assert;

/**
 * Landing pública, T-01: catálogo de planos (fonte única de preço) e
 * validação do lead (`LeadSubmission::fromPayload`).
 */
final class LandingCatalogTest
{
    public function testPlansFollowDraftOrderAndPrices(): void
    {
        $ids = array_map(static fn (array $plan): string => $plan['id'], LandingCatalog::plans());

        Assert::same(['starter', 'pro', 'business', 'enterprise'], $ids);
        Assert::same(9700, LandingCatalog::plan('pro')['price_cents']);
        Assert::true(LandingCatalog::plan('pro')['featured']);
        Assert::false(LandingCatalog::plan('starter')['featured']);
        Assert::null(LandingCatalog::plan('gold'));
        Assert::count(11, LandingCatalog::compareRows());
        Assert::count(27, LandingCatalog::UFS);
    }

    public function testPublicJsonHasNoRawMarkup(): void
    {
        $json = LandingCatalog::publicJson();

        Assert::false(str_contains($json, '<'), 'publicJson must not contain a raw <');
        Assert::false(str_contains($json, '>'), 'publicJson must not contain a raw >');

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        Assert::same(['plans', 'compare', 'vets', 'ufs', 'consent'], array_keys($data));
    }

    public function testValidPayloadTakesPriceAndVersionFromCatalog(): void
    {
        $lead = LeadSubmission::fromPayload($this->validPayload());

        Assert::same('11912345678', $lead->phone);
        Assert::same('pro', $lead->planId);
        Assert::same('Pro', $lead->planName);
        Assert::same(9700, $lead->planPriceCents);
        Assert::same('lgpd-contato-2026-10', $lead->consentVersion);
        Assert::same('Ana Ribeiro', $lead->name);
    }

    public function testConsentFalseIsRejected(): void
    {
        $errors = $this->errorsFor(['consent' => false] + $this->validPayload());

        Assert::same('Marque a autorização de contato para enviar.', $errors['consent'] ?? null);
    }

    public function testInvalidFieldsAreReportedTogether(): void
    {
        $errors = $this->errorsFor([
            'name' => '<b>Ana</b>',
            'email' => 'ana@',
            'plan' => 'gold',
            'uf' => 'XX',
        ] + $this->validPayload());

        Assert::same(['email', 'name', 'plan', 'uf'], $this->sortedKeys($errors));
        Assert::same('Informe seu nome.', $errors['name']);
    }

    public function testNameAboveMaximumIsRejectedNotTruncated(): void
    {
        $errors = $this->errorsFor(['name' => str_repeat('a', 121)] + $this->validPayload());

        Assert::same(['name'], array_keys($errors));
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'name' => '  Ana Ribeiro ',
            'clinic' => 'Clínica Patas & Cia',
            'email' => 'ana@clinica.com.br',
            'phone' => '(11) 91234-5678',
            'vets' => '2-4',
            'city' => 'Campinas',
            'uf' => 'SP',
            'plan' => 'pro',
            'consent' => true,
        ];
    }

    /** @return array<string, string> */
    private function errorsFor(array $payload): array
    {
        try {
            LeadSubmission::fromPayload($payload);
        } catch (LeadValidationException $e) {
            return $e->errors();
        }

        throw new \RuntimeException('Expected LeadValidationException');
    }

    /** @return list<string> */
    private function sortedKeys(array $errors): array
    {
        $keys = array_keys($errors);
        sort($keys);

        return $keys;
    }
}

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

    public function testForgedPriceInPayloadIsIgnored(): void
    {
        $lead = LeadSubmission::fromPayload([
            'price_cents' => 1,
            'plan_price_cents' => 1,
            'planPriceCents' => 1,
            'plan_name' => 'Grátis',
        ] + $this->validPayload());

        Assert::same(9700, $lead->planPriceCents);
        Assert::same('Pro', $lead->planName);
    }

    public function testInvisibleOnlyNameAndClinicAreRejected(): void
    {
        $errors = $this->errorsFor([
            'name' => "\u{00A0}\u{200B}\u{00A0}",
            'clinic' => "\u{200B}\u{2060}",
        ] + $this->validPayload());

        Assert::same(['clinic', 'name'], $this->sortedKeys($errors));
    }

    public function testUnicodeSpaceOnlyNameAndClinicAreRejected(): void
    {
        // Sem \p{Cf}: só o strip unicode de text() recusa; trim() simples aceitaria.
        $cases = [
            ["\u{00A0}\u{00A0}\u{00A0}", "\u{3000}\u{3000}"],
            ["\u{3000}\u{3000}\u{3000}", "\u{00A0}\u{00A0}"],
        ];

        foreach ($cases as [$name, $clinic]) {
            $errors = $this->errorsFor(['name' => $name, 'clinic' => $clinic] + $this->validPayload());
            Assert::same(['clinic', 'name'], $this->sortedKeys($errors));
        }
    }

    public function testFormatCharacterInsideCityIsRejected(): void
    {
        $errors = $this->errorsFor(['city' => "Camp\u{2060}inas"] + $this->validPayload());

        Assert::same(['city'], array_keys($errors));
    }

    public function testFormatCharacterInsideNameIsRejected(): void
    {
        $errors = $this->errorsFor(['name' => "An\u{200B}a Ribeiro"] + $this->validPayload());

        Assert::same(['name'], array_keys($errors));
    }

    public function testPhoneRawTextAboveLimitIsRejected(): void
    {
        $phone = '(11) 91234-5678' . str_repeat(' -', 5);
        Assert::same(25, mb_strlen($phone));
        Assert::same(11, strlen((string) preg_replace('/\D/', '', $phone)));

        $errors = $this->errorsFor(['phone' => $phone] + $this->validPayload());

        Assert::same(['phone'], array_keys($errors));
    }

    public function testUncoveredRulesAreEnforced(): void
    {
        $cases = [
            ['phone', ['phone' => '119123456']],
            ['phone', ['phone' => '11912345678901']],
            ['email', ['email' => str_repeat('a', 149) . '@exemplo.com']],
            ['clinic', ['clinic' => 'A']],
            ['clinic', ['clinic' => str_repeat('a', 161)]],
            ['vets', ['vets' => 'x']],
            ['city', ['city' => str_repeat('a', 81)]],
            ['city', ['city' => 'Campinas <b>']],
            ['name', ['name' => 123]],
            ['email', ['email' => ['a@b.com']]],
            ['plan', ['plan' => true]],
            ['consent', ['consent' => 'true']],
            ['consent', ['consent' => 1]],
        ];

        foreach ($cases as [$key, $override]) {
            $errors = $this->errorsFor($override + $this->validPayload());
            Assert::same([$key], array_keys($errors), 'Expected only ' . $key . ' for ' . json_encode($override));
        }
    }

    public function testMissingNullOrNonStringPhoneIsRejected(): void
    {
        $missing = $this->validPayload();
        unset($missing['phone']);

        $cases = [
            'missing' => $missing,
            'null' => ['phone' => null] + $this->validPayload(),
            'int' => ['phone' => 11912345678] + $this->validPayload(),
        ];

        foreach ($cases as $label => $payload) {
            Assert::same(['phone'], array_keys($this->errorsFor($payload)), 'Expected only phone for ' . $label);
        }
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

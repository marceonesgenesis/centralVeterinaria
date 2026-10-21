<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Landing\LandingCatalog;
use CentralVet\Landing\LandingPage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3, T-20: compra pela landing. Não há harness de JS no projeto; os
 * marcadores estáticos garantem o contrato com POST /checkout.php (T-16) e a
 * fumaça no navegador fica com a T-22.
 */
final class LandingCheckoutWiringTest
{
    private const TEMPLATE = 'app/view/landing/landing.html';
    private const SCRIPT = 'landing/landing.js';
    private const TRANSLATIONS = 'landing/translations.js';
    private const STYLE = 'landing/landing.css';

    /** Textos novos em pt (fonte) que precisam de tradução em en e es. */
    private const NEW_STRINGS = [
        'Assinar',
        'Assinar o plano',
        'Plano escolhido',
        'Dados do responsável',
        'Nome do responsável',
        'Telefone com DDD',
        'Forma de pagamento',
        'Cartão de crédito',
        'Ir para o pagamento',
        'Trocar de plano',
        'Informe o nome do responsável.',
        'Informe o telefone com DDD.',
        'Escolha a forma de pagamento: cartão ou Pix.',
        'Aceite os Termos de Assinatura para continuar.',
        'Abrindo o pagamento…',
        'Não foi possível iniciar o pagamento. Tente de novo em instantes.',
        'Pagamento recebido. Você receberá por e-mail o convite para definir sua senha.',
        'Pagamento não concluído. Nenhum valor foi cobrado.',
        LandingCatalog::TERMS_TEXT,
    ];

    public function testScriptPostsCheckoutWithTermsConsentAndPaymentMethod(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::stringContains('var CHECKOUT_PATH = "checkout.php";', $script);
        Assert::stringContains('fetch(CHECKOUT_PATH, { method: "POST", credentials: "same-origin"', $script);
        foreach (['plan_id:', 'payment_method:', 'responsible_name:', 'responsible_email:', 'responsible_phone:', 'clinic_name:', 'terms_accepted:', 'terms_version:', 'contact_consent:', 'form_token:'] as $key) {
            Assert::stringContains($key, $script, "checkout payload must send {$key}");
        }
        Assert::stringContains('TERMS.version', $script);
        Assert::stringContains('r.json.checkout_url', $script);
        Assert::stringContains('window.location.assign(', $script);
        Assert::stringContains('r.status === 422', $script);
        Assert::stringContains('r.status === 502', $script);
        Assert::stringContains('r.json.errors', $script);
        Assert::stringContains('data-plan="', $script);
    }

    public function testScriptShowsCheckoutReturnMessages(): void
    {
        $script = $this->read(self::SCRIPT);

        Assert::stringContains('"Não foi possível iniciar o pagamento. Tente de novo em instantes."', $script);
        Assert::stringContains('"Pagamento recebido. Você receberá por e-mail o convite para definir sua senha."', $script);
        Assert::stringContains('"Pagamento não concluído. Nenhum valor foi cobrado."', $script);
        Assert::stringContains('get("checkout")', $script);
    }

    public function testTemplateHasSubscribeSectionWithAssociatedLabels(): void
    {
        $template = $this->read(self::TEMPLATE);

        Assert::stringContains('<section class="block subscribe" id="assinar"', $template);
        Assert::stringContains('id="checkout-result"', $template);
        foreach (['sub-name', 'sub-email', 'sub-phone', 'sub-clinic', 'sub-terms', 'sub-consent'] as $id) {
            Assert::stringContains('<label for="' . $id . '"', $template, "{$id} needs an associated <label>");
            Assert::same(1, preg_match('/<input[^>]*\sid="' . $id . '"/', $template), "{$id} input must exist once");
        }
        Assert::stringContains('<fieldset class="field methods"', $template);
        Assert::stringContains('<legend>Forma de pagamento</legend>', $template);
    }

    public function testTermsAndConsentAreSeparateCheckboxes(): void
    {
        $template = $this->read(self::TEMPLATE);

        Assert::same(1, preg_match('/<input type="checkbox" id="sub-terms"[^>]*\srequired/', $template), 'terms checkbox must be required');
        Assert::same(1, preg_match('/<input type="checkbox" id="sub-consent"(?![^>]*required)[^>]*>/', $template), 'consent checkbox must be optional');
        Assert::stringContains('id="sub-terms-text"', $template);
        Assert::stringContains('id="sub-consent-text"', $template);
    }

    public function testTemplateCopyDescribesThePurchase(): void
    {
        $template = $this->read(self::TEMPLATE);

        Assert::false(str_contains($template, 'Nenhum pagamento é cobrado nesta página'), 'FAQ must describe the purchase');
        Assert::false(str_contains($template, 'Nossa equipe entra em contato para ativar a sua conta.'), 'plans intro must describe the purchase');
    }

    public function testTranslationsHaveNewKeysInEnglishAndSpanish(): void
    {
        $source = $this->read(self::TRANSLATIONS);
        $en = strpos($source, '"en": {');
        $es = strpos($source, '"es": {');
        Assert::true($en !== false && $es !== false && $en < $es, 'translations.js must have en and es blocks');
        $blocks = ['en' => substr($source, $en, $es - $en), 'es' => substr($source, $es)];

        foreach ($blocks as $language => $block) {
            foreach (self::NEW_STRINGS as $text) {
                $key = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ':';
                Assert::stringContains($key, $block, "{$language} must translate {$text}");
            }
        }
    }

    public function testStyleKeepsNewLabelsLeftAndTouchTargets(): void
    {
        $style = $this->read(self::STYLE);

        Assert::stringContains('--cv-touch-target: 44px;', $style);
        Assert::stringContains('min-height: var(--cv-touch-target)', $style);
        preg_match_all('/([^{}]*(?:subscribe|methods|checkout-result)[^{}]*)\{([^}]*)\}/', $style, $rules, PREG_SET_ORDER);
        Assert::true(count($rules) > 0, 'landing.css must style the subscribe section');
        foreach ($rules as [, $selector, $body]) {
            Assert::same(0, preg_match('/text-align\s*:\s*(right|center)/', $body), trim($selector) . ' must keep text left aligned');
        }
    }

    public function testCheckoutReturnOpensTheLanding(): void
    {
        $page = new LandingPage();

        Assert::same('landing', $page->entryFor('GET', 'checkout=success&order=3f2b8c1e-0d4a-4b7e-9c55-1a2b3c4d5e6f', false));
        Assert::same('landing', $page->entryFor('GET', 'checkout=cancelled&order=3f2b8c1e-0d4a-4b7e-9c55-1a2b3c4d5e6f', false));
        Assert::same('landing', $page->entryFor('HEAD', 'checkout=success', false));
        Assert::same('system', $page->entryFor('GET', 'checkout=success', true));
        Assert::same('system', $page->entryFor('GET', 'checkout=other', false));
        Assert::same('system', $page->entryFor('GET', 'checkout=success&class=LoginForm', false));
        Assert::same('system', $page->entryFor('GET', 'checkout=success&order=%3Cx%3E', false));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), "{$relative} must exist");

        return (string) file_get_contents($path);
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 8A, T-13: telas AiSettingsForm (liga/desliga, termo, cota, uso do
 * mês) e AiInteractionList (50 interações mais recentes, sem textos
 * clínicos).
 *
 * Os controllers só carregam com o Adianti, num processo PHP separado
 * (init.php define _t() global). Nenhum acesso a banco: subclasses trocam
 * os pontos de extensão (contexto, transação, serviço, nomes de usuário)
 * por fakes de CentralVet\Tests\Support.
 */
final class AiSettingsScreensIntegrationTest
{
    private const API_KEY = 'sk-ant-test-f8a';

    /**
     * Subclasses de teste; `T13::$allowed`, `T13::$flags` e `T13::$interactions`
     * controlam o serviço. Abre um `else {` fechado pelo corpo do teste.
     */
    private const SUBCLASSES = 'if (!class_exists("AiSettingsForm") || !class_exists("AiInteractionList")) { $out["missing"] = true; } else {'
        . 'final class T13 {'
        . ' public static bool $allowed = true;'
        . ' public static ?CentralVet\Tests\Support\FakeFeatureFlagRepository $flags = null;'
        . ' public static ?CentralVet\Tests\Support\FakeAiTenantSettingRepository $settings = null;'
        . ' public static ?CentralVet\Tests\Support\FakeAiInteractionRepository $interactions = null;'
        . ' public static function context(): CentralVet\Tenancy\TenantContext { return CentralVet\Tenancy\TenantContext::authenticated(3, 1, 1); }'
        . ' public static function service(CentralVet\Tenancy\TenantContext $c): CentralVet\Application\AiSettingsService {'
        . '  self::$flags ??= new CentralVet\Tests\Support\FakeFeatureFlagRepository();'
        . '  self::$settings ??= new CentralVet\Tests\Support\FakeAiTenantSettingRepository();'
        . '  self::$interactions ??= new CentralVet\Tests\Support\FakeAiInteractionRepository(3);'
        . '  return new CentralVet\Application\AiSettingsService(self::$settings, self::$flags, self::$interactions,'
        . '   new CentralVet\Tests\Support\FakeAuthorizationPolicy(allowed: self::$allowed), new CentralVet\Tests\Support\SpyAuditLogWriter(), $c); }'
        . '}'
        . 'class AiSettingsFormT13 extends AiSettingsForm {'
        . ' protected static function resolveTenantContext(): CentralVet\Tenancy\TenantContext { return T13::context(); }'
        . ' protected static function transaction(Closure $work): mixed { return $work(new CentralVet\Tests\Support\NullPdo()); }'
        . ' protected static function makeService(CentralVet\Tenancy\TenantContext $context, PDO $connection): CentralVet\Application\AiSettingsService { return T13::service($context); }'
        . '}'
        . 'class AiInteractionListT13 extends AiInteractionList {'
        . ' protected static function resolveTenantContext(): CentralVet\Tenancy\TenantContext { return T13::context(); }'
        . ' protected static function transaction(Closure $work): mixed { return $work(new CentralVet\Tests\Support\NullPdo()); }'
        . ' protected static function makeService(CentralVet\Tenancy\TenantContext $context, PDO $connection): CentralVet\Application\AiSettingsService { return T13::service($context); }'
        . ' protected static function userNames(CentralVet\Tenancy\TenantContext $context, PDO $connection, array $ids): array { return [1 => "F8A teste <b>Usuário</b>"]; }'
        . '}';

    /** Interação semeada (PHP): `%s` = status, `%s` = error_code literal PHP. */
    private const SEED = 'T13::$interactions = new CentralVet\Tests\Support\FakeAiInteractionRepository(3);'
        . 'T13::$interactions->seed(CentralVet\Domain\AiInteraction::reconstitute(["id" => 5, "tenant_id" => 3, "system_unit_id" => 1,'
        . ' "system_user_id" => 1, "purpose" => "patient_summary", "subject_id" => 11, "prompt_version" => "v1", "provider" => "fake",'
        . ' "model" => "claude-sonnet-5-5", "status" => "%s", "input_sha256" => str_repeat("a", 64), "input_tokens" => 1234,'
        . ' "output_tokens" => 567, "cost_micro_usd" => 7000, "error_code" => %s,'
        . ' "suggestion_text" => "F8A teste sugestao sigilosa", "final_text" => "F8A teste texto final sigiloso",'
        . ' "correlation_id" => "corr-test", "created_at" => "2026-10-07 09:30:00"]));';

    /**
     * @return array<string, mixed>
     */
    private function runAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'putenv("ANTHROPIC_API_KEY=' . self::API_KEY . '"); putenv("AI_PROVIDER=anthropic"); putenv("AI_MODEL=claude-sonnet-5-5");'
            . '$out = [];'
            . 'ob_start();'
            . 'try { ' . self::SUBCLASSES . $body . ' } }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "@@T13@@", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, '@@T13@@');
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen('@@T13@@')), true);

        Assert::true(is_array($decoded), 'AiSettings subprocess output: ' . $out);
        Assert::true(!isset($decoded['missing']), 'AiSettingsForm and AiInteractionList must exist');
        Assert::true(!isset($decoded['error']), 'subprocess threw: ' . (string) ($decoded['error'] ?? ''));

        return $decoded;
    }

    public function testSettingsFormRendersFieldsTermAndNeverTheApiKey(): void
    {
        $result = $this->runAdianti(
            '$page = new AiSettingsFormT13([]); $page->show();'
            . '$out["term"] = CvFormat::e(CentralVet\Domain\AiTerms::text((string) ApplicationTranslator::getLanguage()));'
        );

        $html = (string) $result['html'];

        foreach (['ai_enabled', 'accept_terms', 'monthly_token_quota'] as $field) {
            Assert::true(preg_match('/name="' . $field . '"/', $html) === 1, 'field ' . $field . ' must be rendered');
        }

        Assert::stringContains((string) $result['term'], $html, 'the AI term text must be shown');
        Assert::stringContains('claude-sonnet-5-5', $html, 'the configured model is shown');
        Assert::false(str_contains($html, self::API_KEY), 'the API key must never reach the page');
        Assert::true(preg_match('/navegador|browser/i', $html) === 1, 'the term must declare that dictation audio goes to the browser speech service');
        Assert::same(1, substr_count($html, 'Google, Apple, Microsoft'), 'the dictation declaration is shown once, inside the versioned term');
        Assert::true(preg_match('/o áudio é enviado a esse serviço|the audio is sent to that service/', (string) $result['term']) === 1, 'the versioned term carries the dictation declaration');
    }

    public function testSettingsWithoutTheProgramShowsPermissionDenied(): void
    {
        $result = $this->runAdianti(
            'T13::$allowed = false;'
            . '$page = new AiSettingsFormT13([]); $page->show();'
            . '$out["denied"] = _t("You are not allowed to manage the AI settings");'
        );

        $html = (string) $result['html'];

        Assert::stringContains((string) $result['denied'], $html, 'denied user sees the not-allowed message');
        Assert::false(str_contains($html, 'name="ai_enabled"'), 'denied user does not see the form');
    }

    public function testEnablingWithoutAcceptingTheTermWarnsAndKeepsTheFlagOff(): void
    {
        $result = $this->runAdianti(
            '$_POST = ["ai_enabled" => "1", "monthly_token_quota" => "300000"];'
            . '$page = new AiSettingsFormT13(["method" => "onSave"]); $page->onSave($_POST); $page->show();'
            . '$out["enabled"] = T13::$flags->isEnabled("ai_assistant");'
            . '$out["changes"] = count(T13::$flags->changes());'
            . '$out["pending"] = _t("Accept the AI terms to turn the assistant on");'
        );

        Assert::same(false, $result['enabled'] ?? null, 'flag stays off without the accepted term');
        Assert::same(0, $result['changes'] ?? null, 'no flag change is written');
        Assert::stringContains((string) $result['pending'], (string) $result['html'], 'the pending-term warning is shown');
    }

    public function testEnablingWithTheAcceptedTermTurnsTheFlagOn(): void
    {
        $result = $this->runAdianti(
            '$_POST = ["ai_enabled" => "1", "accept_terms" => "1", "monthly_token_quota" => "250000"];'
            . '$page = new AiSettingsFormT13(["method" => "onSave"]); $page->onSave($_POST);'
            . '$out["enabled"] = T13::$flags->isEnabled("ai_assistant");'
            . '$out["quota"] = T13::$settings->saved()?->monthlyTokenQuota();'
            . '$out["terms"] = T13::$settings->saved()?->termsVersion();'
        );

        Assert::same(true, $result['enabled'] ?? null, 'flag is turned on');
        Assert::same(250000, $result['quota'] ?? null, 'quota is saved');
        Assert::same(\CentralVet\Domain\AiTerms::VERSION, $result['terms'] ?? null, 'current term is accepted');
    }

    public function testSaveAndViewInteractionsAreTouchTargets(): void
    {
        $result = $this->runAdianti('$page = new AiSettingsFormT13([]); $page->show();');

        $html = (string) $result['html'];

        preg_match_all('/<button[^>]*>/', $html, $buttons);
        $save = array_values(array_filter($buttons[0], static fn (string $b): bool => str_contains($b, 'onSave')));
        Assert::same(1, count($save), 'one Save button');
        Assert::stringContains('cv-touch-target', $save[0], 'the Save button must carry cv-touch-target');
        Assert::true(preg_match('/<a [^>]*href="index\.php\?class=AiInteractionList"[^>]*>/', $html, $link) === 1, 'link to AiInteractionList');
        Assert::stringContains('cv-touch-target', $link[0], 'the view-interactions link must carry cv-touch-target');

        foreach (['AiSettingsForm', 'AiInteractionList'] as $class) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/' . $class . '.php');
            Assert::same(0, preg_match_all('/text-align:\s*(right|center)/', $source), $class . ' must not right/center-align anything inline');
        }
    }

    public function testInteractionListShowsEmptyState(): void
    {
        $result = $this->runAdianti('$page = new AiInteractionListT13([]); $page->show();');

        Assert::stringContains('cv-state--empty', (string) $result['html'], 'empty list renders cv-state--empty');
    }

    public function testInteractionListNeverShowsSuggestionOrFinalText(): void
    {
        $result = $this->runAdianti(
            sprintf(self::SEED, 'accepted', 'null')
            . '$page = new AiInteractionListT13([]); $page->show();'
        );

        $html = (string) $result['html'];

        Assert::stringContains('cv-table', $html, 'interactions render in a cv-table');
        Assert::stringContains('claude-sonnet-5-5', $html, 'model column');
        Assert::stringContains('1.234 / 567', $html, 'tokens column (input / output, pt thousands separator)');
        Assert::stringContains('US$ 0,0070', $html, 'cost column in US$ with 4 decimals');
        Assert::stringContains('F8A teste &lt;b&gt;Usuário&lt;/b&gt;', $html, 'user name is escaped');
        Assert::false(str_contains($html, 'F8A teste sugestao sigilosa'), 'suggestion text must never be shown');
        Assert::false(str_contains($html, 'F8A teste texto final sigiloso'), 'final text must never be shown');
    }

    public function testInteractionListEscapesTheErrorCode(): void
    {
        $result = $this->runAdianti(
            sprintf(self::SEED, 'failed', '"<script>alert(1)</script>"')
            . '$page = new AiInteractionListT13([]); $page->show();'
        );

        $html = (string) $result['html'];

        Assert::stringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'error code is escaped');
        Assert::false(str_contains($html, '<script>alert(1)'), 'no raw <script> from the error code');
    }

    public function testInteractionListWithoutTheProgramShowsPermissionDenied(): void
    {
        $result = $this->runAdianti(
            'T13::$allowed = false;'
            . '$page = new AiInteractionListT13([]); $page->show();'
            . '$out["denied"] = _t("You are not allowed to view the AI interactions");'
        );

        Assert::stringContains((string) $result['denied'], (string) $result['html'], 'denied user sees the not-allowed message');
    }

    public function testSettingsFormRendersTheManagementCopilotSwitchWithPortugueseLabel(): void
    {
        $result = $this->runAdianti(
            'T13::$flags = new CentralVet\\Tests\\Support\\FakeFeatureFlagRepository(["ai_copilot_management"]);'
            . '$page = new AiSettingsFormT13([]); $page->show();'
        );

        $html = (string) $result['html'];

        Assert::true(preg_match('/<input[^>]*name="ai_copilot_management"[^>]*>/', $html, $input) === 1, 'the management copilot switch must be rendered');
        Assert::stringContains('btn-tswitch', $input[0], 'ai_copilot_management is a switch');
        Assert::stringContains('checked', $input[0], 'the switch reflects the stored flag');
        Assert::true(preg_match('/name="ai_copilot_management".{0,600}?<label[^>]*class="[^"]*tswitch[^"]*cv-touch-target/s', $html) === 1, 'the switch carries cv-touch-target');
        Assert::stringContains('Ligar o copiloto de gestão (agenda, estoque, débitos e indicadores)', $html, 'label in Portuguese');

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/AiSettingsForm.php');
        Assert::same(0, preg_match_all('/text-align:\s*(right|center)/', $source), 'AiSettingsForm must not right/center-align anything inline');
    }

    public function testSavingTogglesOnlyTheManagementCopilotFlag(): void
    {
        $result = $this->runAdianti(
            'T13::$flags = new CentralVet\\Tests\\Support\\FakeFeatureFlagRepository(["ai_assistant"]);'
            . '$s = CentralVet\\Domain\\AiTenantSetting::defaults(3); $s->acceptTerms(CentralVet\\Domain\\AiTerms::VERSION, 1, new DateTimeImmutable("2026-10-01 09:00:00"));'
            . 'T13::$settings = new CentralVet\\Tests\\Support\\FakeAiTenantSettingRepository($s);'
            . '$_POST = ["ai_enabled" => "1", "ai_copilot_management" => "1", "monthly_token_quota" => "300000"];'
            . '$page = new AiSettingsFormT13(["method" => "onSave"]); $page->onSave($_POST);'
            . '$out["on"] = T13::$flags->isEnabled("ai_copilot_management");'
            . '$_POST = ["ai_enabled" => "1", "monthly_token_quota" => "300000"];'
            . '$page = new AiSettingsFormT13(["method" => "onSave"]); $page->onSave($_POST);'
            . '$out["off"] = T13::$flags->isEnabled("ai_copilot_management");'
            . '$out["assistant"] = T13::$flags->isEnabled("ai_assistant");'
            . '$out["changes"] = T13::$flags->changes();'
        );

        Assert::same(true, $result['on'] ?? null, 'checking the switch turns the management copilot on');
        Assert::same(false, $result['off'] ?? null, 'unchecking turns it off');
        Assert::same(true, $result['assistant'] ?? null, 'the ai_assistant flag stays on');
        Assert::same([
            ['flag_key' => 'ai_copilot_management', 'enabled' => true, 'system_user_id' => 1],
            ['flag_key' => 'ai_copilot_management', 'enabled' => false, 'system_user_id' => 1],
        ], $result['changes'] ?? null, 'only ai_copilot_management rows are written (1 then 0)');
    }
}

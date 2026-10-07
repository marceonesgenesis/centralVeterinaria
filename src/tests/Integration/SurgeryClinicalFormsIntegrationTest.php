<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-15: consentimento (SurgeryConsentForm) e eventos clínicos
 * (SurgeryEventForm) da cirurgia.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de HospitalizationClinicalFormsIntegrationTest.
 */
final class SurgeryClinicalFormsIntegrationTest
{
    /**
     * Roda $body no processo com init.php; $body grava em $out e o
     * resultado volta em JSON. "html" é a saída capturada.
     *
     * @return array<string, mixed>
     */
    private function runAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'ob_start();'
            . 'try {' . $body . '}'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "\n@@SURGERYFORMS@@", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, '@@SURGERYFORMS@@');
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen('@@SURGERYFORMS@@')), true);

        Assert::true(is_array($decoded), 'subprocess output: ' . $out);

        return $decoded;
    }

    /** @return list<string> */
    private function fieldsOf(string $class, array $param): array
    {
        $result = $this->runAdianti(
            'if (!class_exists(' . var_export($class, true) . ')) { $out["missing"] = true; } else {'
            . '$page = new ' . $class . '(' . var_export($param, true) . ');'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["fields"] = $form === null ? [] : array_values(array_map("strval", array_keys($form->getFields())));'
            . '}'
        );

        Assert::true(!isset($result['missing']), "{$class} must exist");
        Assert::true(!isset($result['error']), "{$class} threw: " . (string) ($result['error'] ?? ''));

        return $result['fields'];
    }

    public function testConsentFormHasSignerAndTextFields(): void
    {
        $fields = $this->fieldsOf('SurgeryConsentForm', ['surgery_id' => 1]);

        foreach (['surgery_id', 'consent_signer_name', 'consent_text'] as $name) {
            Assert::true(in_array($name, $fields, true), "SurgeryConsentForm must have field '{$name}' (got: " . implode(', ', $fields) . ')');
        }
    }

    public function testConsentTextIsPrefilledWithTheDefaultTextKey(): void
    {
        $result = $this->runAdianti(
            '$page = new SurgeryConsentForm(["surgery_id" => 1]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["key"] = SurgeryConsentForm::DEFAULT_TEXT_KEY;'
            . '$out["expected"] = _t(SurgeryConsentForm::DEFAULT_TEXT_KEY);'
            . '$out["value"] = (string) $form->getField("consent_text")->getValue();'
            . '$out["is_text"] = $form->getField("consent_text") instanceof TText;'
        );

        Assert::true(!isset($result['error']), 'SurgeryConsentForm threw: ' . (string) ($result['error'] ?? ''));
        Assert::same('Surgery consent default text', $result['key']);
        Assert::true($result['is_text'] === true, 'consent_text must be a TText');
        Assert::same($result['expected'], $result['value']);
    }

    public function testEventFormHasNotesField(): void
    {
        $fields = $this->fieldsOf('SurgeryEventForm', ['surgery_id' => 1, 'type' => 'intra_op']);

        foreach (['surgery_id', 'type', 'notes_text'] as $name) {
            Assert::true(in_array($name, $fields, true), "SurgeryEventForm must have field '{$name}' (got: " . implode(', ', $fields) . ')');
        }
    }

    public function testEventFormWithUnknownTypeRendersEmptyStateWithoutThrowing(): void
    {
        $result = $this->runAdianti(
            '$_GET = ["class" => "SurgeryEventForm", "surgery_id" => "1", "type" => "foo"];'
            . '$page = new SurgeryEventForm($_GET); $page->show();'
        );

        Assert::true(!isset($result['error']), 'SurgeryEventForm with type=foo threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], 'cv-state--empty'), 'type=foo must render cv-state--empty');
        Assert::true(!str_contains((string) $result['html'], 'notes_text'), 'type=foo must not render the notes form');
    }

    public function testFormsWithoutSurgeryIdRenderEmptyState(): void
    {
        $result = $this->runAdianti(
            '$_GET = [];'
            . '$a = new SurgeryConsentForm([]); $a->show();'
            . '$b = new SurgeryEventForm(["type" => "post_op"]); $b->show();'
        );

        Assert::true(!isset($result['error']), 'forms without surgery_id threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(2, substr_count((string) $result['html'], 'cv-state--empty'));
    }

    public function testAllAuthorizationActionsMatchTheRequestPattern(): void
    {
        $result = $this->runAdianti(
            'foreach (["SurgeryConsentForm", "SurgeryEventForm"] as $c) {'
            . ' $out["actions"][$c] = [];'
            . ' if (!class_exists($c)) { continue; }'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out["actions"][$c][$k] = $v; } } }'
        );

        foreach ($result['actions'] as $class => $actions) {
            Assert::true($actions !== [], "{$class} must declare ACTION_* constants");

            foreach ($actions as $name => $action) {
                Assert::same(
                    1,
                    preg_match(AuthorizationRequest::ACTION_PATTERN, (string) $action),
                    "{$class}::{$name} = '{$action}' must match AuthorizationRequest::ACTION_PATTERN"
                );
                Assert::true(str_starts_with((string) $action, $class . '::'), "{$class}::{$name} must be '{$class}::<method>'");
            }

            Assert::true(in_array($class . '::onSave', $actions, true), "{$class} must declare '{$class}::onSave'");
            Assert::true(in_array($class . '::onLoad', $actions, true), "{$class} must declare '{$class}::onLoad'");
        }
    }

    /** Texto clínico só por POST: o que vier na query string é ignorado. */
    public function testEventNotesComeOnlyFromThePostBody(): void
    {
        $result = $this->runAdianti(
            '$_POST = [];'
            . '$_GET = ["class" => "SurgeryEventForm", "method" => "onSave", "surgery_id" => "1", "type" => "post_op", "notes_text" => "VIAURL"];'
            . '$page = new SurgeryEventForm($_GET);'
            . '$out["expected"] = _t("Notes") ;'
            . '$page->onSave($_GET);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["kept"] = (string) $form->getField("notes_text")->getValue();'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(!str_contains((string) $result['html'], 'notes_text is required'), 'error must not expose the technical field name');
        Assert::true(str_contains((string) $result['html'], (string) $result['expected']), 'error must name the Notes label: ' . (string) $result['html']);
        Assert::same('', $result['kept'], 'notes from the query string must be ignored');
    }

    /** Erro de validação mantém o que foi digitado e cita o rótulo. */
    public function testConsentMissingSignerShowsLabelAndKeepsTypedText(): void
    {
        $result = $this->runAdianti(
            '$_POST = ["surgery_id" => "1", "consent_signer_name" => "", "consent_text" => "Texto F6B teste"];'
            . '$page = new SurgeryConsentForm(["surgery_id" => 1]);'
            . '$out["label"] = _t("Signer name");'
            . '$page->onSave($_POST);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["kept"] = (string) $form->getField("consent_text")->getValue();'
            . '$out["required"] = (string) $form->getField("consent_signer_name")->getProperty("required");'
            . '$out["classes"] = [];'
            . 'foreach ($form->getActions() as $b) { $out["classes"][] = (string) $b->getProperty("class"); }'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(!str_contains((string) $result['html'], 'consent_signer_name'), 'error must not expose the technical field name');
        Assert::true(str_contains((string) $result['html'], (string) $result['label']), 'error must name the signer label: ' . (string) $result['html']);
        Assert::same('Texto F6B teste', $result['kept']);
        Assert::true($result['required'] !== '', 'consent_signer_name must be marked as required');
        Assert::true($result['classes'] !== [], 'consent form must have actions');

        foreach ($result['classes'] as $class) {
            Assert::true(str_contains($class, 'cv-touch-target'), "action button must use cv-touch-target (got '{$class}')");
        }
    }

    /**
     * Correção 1 (revisão T-15): o nome do procedimento vem do banco (texto
     * livre do catálogo) e o BootstrapFormBuilder não escapa o título.
     */
    public function testProcedureNameIsEscapedInTheEventFormTitle(): void
    {
        $result = $this->runAdianti(
            '$_GET = ["class" => "SurgeryEventForm", "surgery_id" => "1", "type" => "post_op"];'
            . 'eval(\'class SurgeryEventFormXss extends SurgeryEventForm {'
            . ' protected static function loadProcedureName(int $surgeryId): ?string { return "<img src=x onerror=alert(1)>"; } }\');'
            . '$page = new SurgeryEventFormXss($_GET); $page->show();'
        );

        Assert::true(!isset($result['error']), 'SurgeryEventForm threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], '&lt;img src=x onerror=alert(1)&gt;'), 'procedure name must be rendered escaped');
        Assert::true(!str_contains((string) $result['html'], '<img src=x'), 'procedure name must not be rendered as raw HTML');
    }

    /** Signatário e texto do consentimento só pelo corpo do POST. */
    public function testConsentFieldsComeOnlyFromThePostBody(): void
    {
        $result = $this->runAdianti(
            '$_POST = [];'
            . '$_GET = ["class" => "SurgeryConsentForm", "method" => "onSave", "surgery_id" => "1",'
            . ' "consent_signer_name" => "VIAURL", "consent_text" => "TEXTOURL"];'
            . '$page = new SurgeryConsentForm($_GET);'
            . '$out["label"] = _t("Signer name");'
            . '$page->onSave($_GET);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$out["signer"] = (string) $form->getField("consent_signer_name")->getValue();'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], (string) $result['label']), 'signer from the query string must be ignored: ' . (string) $result['html']);
        Assert::true(!str_contains((string) $result['html'], 'VIAURL'), 'signer from the query string must not be used');
        Assert::same('', $result['signer']);
    }
}

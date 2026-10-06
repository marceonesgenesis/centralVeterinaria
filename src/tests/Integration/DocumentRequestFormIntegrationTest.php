<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7B, T-15: tela de pedido de documento (DocumentRequestForm).
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global), no
 * padrão de BedFormIntegrationTest. Sem sessão nem banco: as leituras da tela
 * (templates ativos, texto inicial, resumo do consentimento e merge do
 * template) ficam em ganchos estáticos protegidos, trocados por uma subclasse
 * declarada no subprocesso.
 */
final class DocumentRequestFormIntegrationTest
{
    private const MARKER = '@@T15DOCREQ@@';

    private const TEMPLATE_NAME = '<script>x</script>';
    private const MERGED = "F7B teste atestado</script><img src=x onerror=alert(1)>\nlinha 2";

    private const SUBCLASS = 'if (!class_exists("DocumentRequestForm")) { $out["missing"] = true; } else {'
        . 'class DocumentRequestFormT15 extends DocumentRequestForm {'
        . ' protected static function loadTemplateOptions(string $kind): array { return [5 => "<script>x</script>"]; }'
        . ' protected static function loadInitialBody(int $patientId): string { return "F7B teste texto inicial"; }'
        . ' protected static function loadConsentSummary(string $kind, int $sourceId): array { return ["email" => true, "whatsapp" => false]; }'
        . ' protected static function mergeTemplate(int $templateId, int $patientId): string { return ' . "\"F7B teste atestado</script><img src=x onerror=alert(1)>\\nlinha 2\"" . '; }'
        . ' }';

    public function testMedicalCertificateRendersTextTemplateComboAndTouchButton(): void
    {
        $result = $this->runAdianti(
            self::SUBCLASS
            . '$page = new DocumentRequestFormT15(["kind" => "medical_certificate", "source_id" => "42"]);'
            . '$form = (new ReflectionProperty(DocumentRequestForm::class, "form"))->getValue($page);'
            . '$out["fields"] = $form === null ? null : ['
            . ' "body_text" => $form->getField("body_text") !== null,'
            . ' "template_id" => $form->getField("template_id") !== null,'
            . ' "notify_tutor" => $form->getField("notify_tutor") !== null];'
            . '$page->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(['body_text' => true, 'template_id' => true, 'notify_tutor' => true], $result['fields']);

        $html = (string) $result['html'];
        preg_match_all('/<button[^>]*>.*?<\/button>/s', $html, $m);
        $save = array_values(array_filter($m[0], static fn (string $b): bool => str_contains($b, 'onSave')));

        Assert::same(1, count($save), 'one Generate PDF button: ' . $html);
        Assert::stringContains('Generate PDF', $save[0], 'button label');
        Assert::stringContains('cv-touch-target', $save[0], 'button is a touch target');
        Assert::stringContains('btn-primary', $save[0], 'button is primary');
        Assert::stringContains('F7B teste texto inicial', $html, 'initial body is loaded into the text');
        Assert::false(str_contains($html, self::TEMPLATE_NAME), 'template name must be escaped: ' . $html);
        Assert::stringContains('&lt;script&gt;x&lt;/script&gt;', $html, 'template name appears escaped');
    }

    public function testUnknownKindShowsInvalidRequestWithoutForm(): void
    {
        $result = $this->runAdianti(
            self::SUBCLASS
            . '$page = new DocumentRequestFormT15(["kind" => "foo", "source_id" => "42"]);'
            . '$out["form"] = (new ReflectionProperty(DocumentRequestForm::class, "form"))->getValue($page) !== null;'
            . '$page->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::false($result['form'], 'no form for an invalid request');
        Assert::stringContains('Invalid document request', (string) $result['html']);
        Assert::false(str_contains((string) $result['html'], 'Generate PDF'), 'no Generate PDF button');
    }

    public function testNonIntegerSourceShowsInvalidRequest(): void
    {
        $result = $this->runAdianti(
            self::SUBCLASS
            . '$page = new DocumentRequestFormT15(["kind" => "vaccination_card", "source_id" => "4x"]);'
            . '$out["form"] = (new ReflectionProperty(DocumentRequestForm::class, "form"))->getValue($page) !== null;'
            . '$page->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::false($result['form'], 'no form for a non-integer source_id');
        Assert::stringContains('Invalid document request', (string) $result['html']);
    }

    public function testOnChangeTemplateSendsMergedTextAsSafeScriptLiteral(): void
    {
        $result = $this->runAdianti(
            self::SUBCLASS
            . 'DocumentRequestFormT15::onChangeTemplate(["kind" => "medical_certificate", "source_id" => "42", "template_id" => "5"]);'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'onChangeTemplate threw: ' . (string) ($result['error'] ?? ''));

        $html = (string) $result['html'];
        Assert::stringContains('tform_send_data', $html, 'merged text must be sent to the form');
        Assert::false(stripos($html, '<img') !== false, 'merged text must not open an HTML tag: ' . $html);
        $literal = json_encode(self::MERGED, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        Assert::stringContains((string) $literal, $html, 'merged text goes as a JSON-encoded JS literal');
    }

    public function testControllerNeverShowsRawExceptionMessages(): void
    {
        $path = dirname(__DIR__, 2) . '/app/control/clinic/DocumentRequestForm.php';

        Assert::true(is_file($path), 'DocumentRequestForm.php must exist');
        Assert::false(str_contains((string) file_get_contents($path), 'getMessage()'), 'the screen must not use getMessage()');
    }

    /** @return array<string, mixed> */
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
            . ' catch (Throwable $e) { $out["error"] = get_class($e); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "\n' . self::MARKER . '", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'subprocess output: ' . $out);

        return $decoded;
    }
}

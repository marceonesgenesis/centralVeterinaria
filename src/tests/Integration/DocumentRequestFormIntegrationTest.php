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
            . '$out["label"] = _t("Generate PDF");'
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
        Assert::stringContains((string) $result['label'], $save[0], 'button label is _t("Generate PDF")');
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
            . '$out["invalid"] = _t("Invalid document request"); $out["label"] = _t("Generate PDF");'
            . '$page->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::false($result['form'], 'no form for an invalid request');
        Assert::stringContains((string) $result['invalid'], (string) $result['html'], 'shows _t("Invalid document request")');
        Assert::false(str_contains((string) $result['html'], (string) $result['label']), 'no Generate PDF button');
    }

    public function testNonIntegerSourceShowsInvalidRequest(): void
    {
        $result = $this->runAdianti(
            self::SUBCLASS
            . '$page = new DocumentRequestFormT15(["kind" => "vaccination_card", "source_id" => "4x"]);'
            . '$out["form"] = (new ReflectionProperty(DocumentRequestForm::class, "form"))->getValue($page) !== null;'
            . '$out["invalid"] = _t("Invalid document request"); $out["label"] = _t("Generate PDF");'
            . '$page->show();'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::false($result['form'], 'no form for a non-integer source_id');
        Assert::stringContains((string) $result['invalid'], (string) $result['html'], 'shows _t("Invalid document request")');
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

    /** Linha mínima para GeneratedDocument::reconstitute (paciente 7, tenant 3). */
    private const DOC_ROW = '["id" => 61, "tenant_id" => 3, "system_unit_id" => 1, "patient_id" => 7, "tutor_id" => 9,'
        . ' "kind" => "medical_certificate", "source_type" => "patient", "source_id" => 7, "version" => 1,'
        . ' "template_id" => null, "title" => "Atestado", "body_text" => "F7B teste corpo", "notify_tutor" => 1,'
        . ' "status" => "queued", "attempt_count" => 0, "stored_object_id" => null, "storage_key" => null,'
        . ' "last_error_code" => null, "ready_at" => null, "notified_at" => null,'
        . ' "requested_by_system_user_id" => 1, "created_at" => "2026-10-06 09:00:00"]';

    /**
     * Subclasse de onSave (T-25): troca o pedido (sessão + banco) e a
     * publicação na fila por gravadores, mantendo a lógica do controller.
     */
    private const SAVE_SUBCLASS = 'if (!class_exists("DocumentRequestForm")) { $out["missing"] = true; } else {'
        . 'class DocumentRequestFormT25 extends DocumentRequestForm {'
        . ' public static array $calls = []; public static ?Throwable $fail = null;'
        . ' protected static function loadTemplateOptions(string $kind): array { return []; }'
        . ' protected static function loadInitialBody(int $patientId): string { return ""; }'
        . ' protected static function loadConsentSummary(string $kind, int $sourceId): array { return []; }'
        . ' protected static function requestDocument(string $kind, int $sourceId, ?int $templateId, ?string $bodyText, bool $notifyTutor): CentralVet\Domain\GeneratedDocument {'
        . '  self::$calls[] = ["request", $kind, $sourceId, $templateId, $bodyText, $notifyTutor];'
        . '  if (self::$fail !== null) { throw self::$fail; }'
        . '  return CentralVet\Domain\GeneratedDocument::reconstitute(' . self::DOC_ROW . '); }'
        . ' protected static function publishDocumentJob(CentralVet\Domain\GeneratedDocument $document): void {'
        . '  self::$calls[] = ["publish", $document->tenantId(), $document->id()]; }'
        . ' }';

    public function testOnSaveRequestsWithPostedTextPublishesAndGoesToThePatientDocuments(): void
    {
        $result = $this->runAdianti(
            self::SAVE_SUBCLASS
            . '$_POST["body_text"] = "F7B teste corpo";'
            . '$page = new DocumentRequestFormT25(["kind" => "medical_certificate", "source_id" => "7"]);'
            . '$page->onSave(["kind" => "medical_certificate", "source_id" => "7", "template_id" => "0",'
            . ' "notify_tutor" => "1", "body_text" => "F7B teste texto da query"]);'
            . '$out["calls"] = DocumentRequestFormT25::$calls;'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::same([
            ['request', 'medical_certificate', 7, null, 'F7B teste corpo', true],
            ['publish', 3, 61],
        ], $result['calls'] ?? null, 'onSave requests with the POST text (template 0 = none) and publishes after the request');
        Assert::stringContains('class=DocumentList&patient_id=7', (string) $result['html'], 'onSave goes to the documents of the patient');
    }

    public function testOnSaveOfANonCertificateIgnoresAnyText(): void
    {
        $result = $this->runAdianti(
            self::SAVE_SUBCLASS
            . '$_POST["body_text"] = "F7B teste corpo";'
            . '$page = new DocumentRequestFormT25(["kind" => "prescription", "source_id" => "12"]);'
            . '$page->onSave(["kind" => "prescription", "source_id" => "12", "template_id" => "5"]);'
            . '$out["calls"] = DocumentRequestFormT25::$calls;'
            . '}'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(['request', 'prescription', 12, null, null, false], $result['calls'][0] ?? null, 'no text nor template outside the certificate');
    }

    public function testOnSaveDeniedShowsNotAllowedAndDoesNotPublish(): void
    {
        $result = $this->runAdianti(
            self::SAVE_SUBCLASS
            . 'DocumentRequestFormT25::$fail = new CentralVet\Authorization\Exception\AuthorizationDenied("denied");'
            . '$page = new DocumentRequestFormT25(["kind" => "vaccination_card", "source_id" => "7"]);'
            . '$page->onSave(["kind" => "vaccination_card", "source_id" => "7"]);'
            . '$out["calls"] = DocumentRequestFormT25::$calls;'
            . '$out["denied"] = _t("You are not allowed to request documents");'
            . '}'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(1, count((array) ($result['calls'] ?? [])), 'denied request must not publish');
        $html = (string) $result['html'];
        Assert::stringContains((string) $result['denied'], $html, 'denied request shows the not-allowed message');
        Assert::false(str_contains($html, 'class=DocumentList&patient_id='), 'denied request stays on the form');
    }

    public function testOnSaveWithInvalidRequestNeverCallsTheService(): void
    {
        $result = $this->runAdianti(
            self::SAVE_SUBCLASS
            . '$page = new DocumentRequestFormT25(["kind" => "vaccination_card", "source_id" => "7"]);'
            . '$page->onSave(["kind" => "vaccination_card", "source_id" => "0"]);'
            . '$out["calls"] = DocumentRequestFormT25::$calls;'
            . '}'
        );

        Assert::true(!isset($result['error']), 'onSave threw: ' . (string) ($result['error'] ?? ''));
        Assert::same([], $result['calls'] ?? null, 'source_id 0 is refused before the service');
    }

    /**
     * Resumo do consentimento (T-25): fonte de outra unidade → vazio, sem
     * ler preferências; RBAC negado → vazio; fonte da unidade ativa e
     * permitida → resumo por canal.
     */
    private const CONSENT_CALL = '$m = new ReflectionMethod("DocumentRequestForm", "consentSummaryFor"); $m->setAccessible(true);'
        . '$ctx = CentralVet\Tenancy\TenantContext::authenticated(3, 1, 1);'
        . '$src = new CentralVet\Tests\Support\FakeDocumentSourceQuery();'
        . '$src->seedPatient(["patient_id" => 7, "patient_name" => "F7B teste Pet", "species" => "dog", "breed" => null, "tutor_id" => 9, "tutor_name" => "F7B teste Tutor"]);'
        . '$src->seedPrescription(["prescription_id" => 12, "patient_id" => 7, "system_unit_id" => 2]);'
        . '$src->seedPrescription(["prescription_id" => 13, "patient_id" => 7, "system_unit_id" => 1]);'
        . '$src->seedSurgery(["surgery_id" => 4, "patient_id" => 7, "system_unit_id" => 2]);'
        . '$src->seedSurgery(["surgery_id" => 5, "patient_id" => 7, "system_unit_id" => 1]);'
        . '$out["tutors"] = [];'
        . '$prefs = function (int $tutorId) use (&$out): array { $out["tutors"][] = $tutorId; return ["email" => true, "whatsapp" => false]; };';

    public function testConsentSummaryRefusesSourcesOfAnotherUnit(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("DocumentRequestForm")) { $out["missing"] = true; } else {'
            . self::CONSENT_CALL
            . '$auth = new CentralVet\Tests\Support\FakeAuthorizationPolicy(true);'
            . '$out["prescription"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "prescription", 12);'
            . '$out["surgery"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "surgery_consent", 4);'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'DocumentRequestForm must exist');
        Assert::true(!isset($result['error']), 'consentSummaryFor threw: ' . (string) ($result['error'] ?? ''));
        Assert::same([], $result['prescription'] ?? null, 'prescription of another unit has no summary');
        Assert::same([], $result['surgery'] ?? null, 'surgery of another unit has no summary');
        Assert::same([], $result['tutors'] ?? null, 'preferences are never read for another unit');
    }

    public function testConsentSummaryRequiresAuthorization(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("DocumentRequestForm")) { $out["missing"] = true; } else {'
            . self::CONSENT_CALL
            . '$auth = new CentralVet\Tests\Support\FakeAuthorizationPolicy(false, "denied:permission");'
            . '$out["patient"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "vaccination_card", 7);'
            . '$out["prescription"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "prescription", 13);'
            . '$out["actions"] = array_map(fn ($r) => [$r->action(), $r->requiresUnitScope(), $r->resourceUnitId()], $auth->requests);'
            . '}'
        );

        Assert::true(!isset($result['error']), 'consentSummaryFor threw: ' . (string) ($result['error'] ?? ''));
        Assert::same([], $result['patient'] ?? null, 'denied → no summary');
        Assert::same([], $result['prescription'] ?? null, 'denied → no summary');
        Assert::same([], $result['tutors'] ?? null, 'preferences are never read without authorization');
        Assert::same([
            ['DocumentRequestForm::onLoad', true, 1],
            ['DocumentRequestForm::onLoad', true, 1],
        ], $result['actions'] ?? null, 'authorization is asked with the unit scope of the active unit');
    }

    public function testConsentSummaryOfAllowedSourcesOfTheActiveUnit(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("DocumentRequestForm")) { $out["missing"] = true; } else {'
            . self::CONSENT_CALL
            . '$auth = new CentralVet\Tests\Support\FakeAuthorizationPolicy(true);'
            . '$out["patient"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "medical_certificate", 7);'
            . '$out["prescription"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "prescription", 13);'
            . '$out["surgery"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "surgery_consent", 5);'
            . '$out["unknown"] = $m->invoke(null, $ctx, $src, $auth, $prefs, "vaccination_card", 99);'
            . '}'
        );

        Assert::true(!isset($result['error']), 'consentSummaryFor threw: ' . (string) ($result['error'] ?? ''));
        $expected = ['email' => true, 'whatsapp' => false];
        Assert::same($expected, $result['patient'] ?? null, 'patient summary');
        Assert::same($expected, $result['prescription'] ?? null, 'prescription of the active unit');
        Assert::same($expected, $result['surgery'] ?? null, 'surgery of the active unit');
        Assert::same([], $result['unknown'] ?? null, 'missing patient → no summary');
        Assert::same([9, 9, 9], $result['tutors'] ?? null, 'preferences read for the tutor of the patient');
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

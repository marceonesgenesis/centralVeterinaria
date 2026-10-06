<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7B, T-16: lista de documentos (DocumentList), download só por id e
 * nova tentativa. O download de id inexistente (ou sem sessão) responde 404
 * com o texto fixo de _t('Document not found') e sem Content-Disposition; o
 * documento ready ganha o link `method=onDownload&id=` com cv-touch-target
 * e só o id na URL; o título sai escapado.
 *
 * O controller só carrega com o Adianti, num processo PHP separado
 * (init.php define _t() global). Nenhum acesso a banco: a tabela é montada
 * por reflexão com documentos reconstituídos, e o download sem sessão cai no
 * 404 antes de qualquer consulta.
 */
final class DocumentListIntegrationTest
{
    private const MARKER = '@@DOCLIST@@';

    /** Linha mínima para GeneratedDocument::reconstitute, em PHP. */
    private const ROW = '["id" => %d, "tenant_id" => 1, "system_unit_id" => 1, "patient_id" => 7, "tutor_id" => 9,'
        . ' "kind" => "vaccination_card", "source_type" => "patient", "source_id" => 7, "version" => 2,'
        . ' "template_id" => null, "title" => "<script>alert(1)</script>", "body_text" => null, "notify_tutor" => 0,'
        . ' "status" => "%s", "attempt_count" => 0, "stored_object_id" => %s, "storage_key" => %s,'
        . ' "last_error_code" => null, "ready_at" => null, "notified_at" => null,'
        . ' "requested_by_system_user_id" => 1, "created_at" => "2026-10-06 09:00:00"]';

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
            . '$out = [];'
            . 'ob_start();'
            . 'try { ' . $body . ' }'
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "' . self::MARKER . '", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'DocumentList subprocess output: ' . $out);

        return $decoded;
    }

    private static function row(int $id, string $status): string
    {
        $ready = $status === 'ready';

        return sprintf(self::ROW, $id, $status, $ready ? '5' : 'null', $ready ? '"cv/test/tenant/1/objects/x.pdf"' : 'null');
    }

    private function renderTable(string $status, int $id = 41): array
    {
        return $this->runAdianti(
            '$doc = CentralVet\Domain\GeneratedDocument::reconstitute(' . self::row($id, $status) . ');'
            . '$m = new ReflectionMethod("DocumentList", "table"); $m->setAccessible(true);'
            . '$out["processing"] = _t("Processing…");'
            . '$m->invoke(null, [$doc])->show();'
        );
    }

    public function testDeclaresDownloadAndRetryActions(): void
    {
        $result = $this->runAdianti(
            '$r = new ReflectionClass("DocumentList");'
            . 'foreach (["onDownload", "onRetry"] as $m) {'
            . ' $out[$m] = $r->hasMethod($m) && $r->getMethod($m)->isPublic() && $r->getMethod($m)->isStatic(); }'
        );

        Assert::true(!isset($result['error']), 'reflection threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(true, $result['onDownload'] ?? null, 'onDownload must be public static');
        Assert::same(true, $result['onRetry'] ?? null, 'onRetry must be public static');
    }

    public function testDownloadOfUnknownIdAnswers404WithoutDisposition(): void
    {
        foreach (['999999999', 'abc', '0'] as $id) {
            $result = $this->runAdianti(
                '$m = new ReflectionMethod("DocumentList", "downloadResponse"); $m->setAccessible(true);'
                . '$out["response"] = $m->invoke(null, ["id" => ' . var_export($id, true) . ']);'
                . '$out["expected"] = _t("Document not found");'
            );

            Assert::true(!isset($result['error']), 'downloadResponse threw: ' . (string) ($result['error'] ?? ''));

            $response = (array) ($result['response'] ?? []);
            $headers = (array) ($response['headers'] ?? []);

            Assert::same(404, $response['status'] ?? null, "id {$id} must answer 404");
            Assert::same((string) $result['expected'], $response['body'] ?? null, "id {$id} must answer the fixed not-found text");
            Assert::true(in_array('Content-Type: text/plain; charset=utf-8', $headers, true), "id {$id} must answer text/plain");

            foreach ($headers as $header) {
                Assert::true(stripos((string) $header, 'Content-Disposition') !== 0, "id {$id} must not send Content-Disposition");
            }
        }
    }

    public function testOnDownloadEmitsOnlyTheNotFoundText(): void
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        // onDownload limpa os buffers e chama exit: o marcador sai no shutdown
        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$expected = _t("Document not found");'
            . 'register_shutdown_function(function () use ($expected) { echo "' . self::MARKER . '", json_encode(["expected" => $expected]); });'
            . 'ob_start(); echo "noise-before";'
            . 'DocumentList::onDownload(["id" => "999999999"]);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);

        Assert::true($pos !== false, 'onDownload subprocess output: ' . $out);

        $meta = json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::same((string) ($meta['expected'] ?? ''), substr($out, 0, $pos), 'onDownload must print only the not-found text');
    }

    public function testReadyDocumentLinksTheDownloadByIdOnly(): void
    {
        $result = $this->renderTable('ready');
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(
            preg_match('/<a [^>]*href="([^"]*method=onDownload&(?:amp;)?id=41[^"]*)"[^>]*>/', $html, $m) === 1,
            'ready document must link to onDownload&id='
        );
        Assert::same(
            'engine.php?class=DocumentList&method=onDownload&id=41&static=1',
            html_entity_decode($m[1]),
            'download href must go through engine.php (index.php answers the app shell) with only class, method, id and static'
        );
        Assert::true(str_contains($m[0], 'target="_blank"'), 'download link must open outside the SPA (target _blank)');
        Assert::true(preg_match('/rel="[^"]*\bnoopener\b/', $m[0]) === 1, 'download link must carry rel noopener');
        Assert::true(!str_contains($m[0], 'generator="adianti"'), 'download link must not go through the Adianti router');
        Assert::true(preg_match('/class="[^"]*\bcv-touch-target\b/', $m[0]) === 1, 'download link must carry cv-touch-target');
        Assert::true(!str_contains($html, (string) $result['processing']), 'ready document must not show the processing text');
    }

    public function testTitleIsEscaped(): void
    {
        $result = $this->renderTable('ready');
        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(!str_contains($html, '<script>alert(1)</script>'), 'title must not be rendered raw');
        Assert::true(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'title must be rendered escaped');
        Assert::true(str_contains($html, 'cv-table'), 'list must use cv-table');
    }

    public function testFailedOffersRetryByIdAndQueuedShowsProcessing(): void
    {
        $failed = $this->renderTable('failed', 52);
        $html = (string) $failed['html'];

        Assert::true(!isset($failed['error']), 'render threw: ' . (string) ($failed['error'] ?? ''));
        Assert::true(preg_match('/<a [^>]*href="([^"]*Retry[^"]*)"[^>]*>/', $html, $m) === 1, 'failed document must offer a retry');
        Assert::true(str_contains(html_entity_decode($m[1]), 'id=52'), 'retry must carry the id');
        Assert::true(preg_match('/class="[^"]*\bcv-touch-target\b/', $m[0]) === 1, 'retry must carry cv-touch-target');
        Assert::true(!str_contains($html, 'onDownload'), 'failed document must not link the download');

        $queued = $this->renderTable('queued', 53);
        $html = (string) $queued['html'];

        Assert::true(!isset($queued['error']), 'render threw: ' . (string) ($queued['error'] ?? ''));
        Assert::true(str_contains($html, htmlspecialchars((string) $queued['processing'])), 'queued document must show the processing text');
        Assert::true(!str_contains($html, 'onDownload') && !str_contains($html, 'Retry'), 'queued document has no actions');
    }
}

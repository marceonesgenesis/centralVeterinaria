<?php

declare(strict_types=1);

namespace CentralVet\Document;

use CentralVet\Domain\Contract\DocumentRendererInterface;
use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * dompdf-backed renderer for document PDFs (Fase 7B). The HTML comes only
 * from {@see DocumentHtmlBuilder} (central escaping) and dompdf runs locked
 * down: no remote fetch, no inline PHP, no JavaScript, temp dir and chroot
 * in sys_get_temp_dir() (the app container is read-only), bundled fonts only.
 * Any dompdf failure becomes DocumentGenerationFailed(RENDER_FAILED) without
 * the original message, which could quote document text.
 */
final class DompdfDocumentRenderer implements DocumentRendererInterface
{
    public function __construct(private readonly DocumentHtmlBuilder $htmlBuilder = new DocumentHtmlBuilder())
    {
    }

    public function render(DocumentContent $content): string
    {
        try {
            $dompdf = new Dompdf($this->options());
            $dompdf->loadHtml($this->htmlBuilder->build($content), 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();
            $bytes = $dompdf->output();
        } catch (Throwable) {
            throw new DocumentGenerationFailed(DocumentGenerationFailed::RENDER_FAILED);
        }

        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new DocumentGenerationFailed(DocumentGenerationFailed::RENDER_FAILED);
        }

        return $bytes;
    }

    /** The exact dompdf options used by render(); public so tests can pin them. */
    public function options(): Options
    {
        $tempDir = sys_get_temp_dir();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->setTempDir($tempDir);
        $options->setChroot($tempDir);
        $options->setDefaultFont('DejaVu Sans');

        return $options;
    }
}

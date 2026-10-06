<?php

declare(strict_types=1);

namespace CentralVet\Document;

use CentralVet\Domain\DocumentContent;

/**
 * Builds the HTML of one document PDF (Fase 7B). This is the single place
 * where document text becomes markup: every value goes through
 * {@see self::e()}, and the output carries no <img>, <link>, <script> or
 * external URL, so patient names and free text cannot inject markup or make
 * dompdf fetch anything.
 */
final class DocumentHtmlBuilder
{
    private const CSS = '@page { size: A4; margin: 18mm 16mm; }'
        . ' body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #222; }'
        . ' h1 { font-size: 16pt; margin: 0 0 4pt 0; }'
        . ' .clinic { font-size: 10pt; color: #555; margin: 0 0 12pt 0; }'
        . ' .subject { margin: 0 0 12pt 0; }'
        . ' .subject div { margin: 0 0 2pt 0; }'
        . ' p { margin: 0 0 8pt 0; text-align: justify; }'
        . ' table { width: 100%; border-collapse: collapse; margin: 8pt 0 12pt 0; }'
        . ' th, td { border: 1px solid #999; padding: 3pt 5pt; text-align: left; font-size: 10pt; }'
        . ' th { background: #eee; }'
        . ' .signature { margin-top: 48pt; text-align: center; }'
        . ' .signature .line { border-top: 1px solid #222; width: 60%; margin: 0 auto 2pt auto; }'
        . ' .footer { margin-top: 24pt; font-size: 9pt; color: #555; }';

    public function build(DocumentContent $content): string
    {
        $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<title>' . self::e($content->title) . '</title>'
            . '<style>' . self::CSS . '</style></head><body>';

        $html .= '<h1>' . self::e($content->title) . '</h1>';
        $html .= '<div class="clinic">' . self::e($content->clinicName) . ' — ' . self::e($content->unitName) . '</div>';

        if ($content->subjectLines !== []) {
            $html .= '<div class="subject">';
            foreach ($content->subjectLines as $line) {
                $html .= '<div>' . self::subjectLine((string) $line) . '</div>';
            }
            $html .= '</div>';
        }

        foreach ($content->paragraphs as $paragraph) {
            $html .= '<p>' . nl2br(self::e((string) $paragraph), false) . '</p>';
        }

        if ($content->tableHeader !== [] || $content->tableRows !== []) {
            $html .= '<table>';
            if ($content->tableHeader !== []) {
                $html .= '<thead><tr>';
                foreach ($content->tableHeader as $column) {
                    $html .= '<th>' . self::e((string) $column) . '</th>';
                }
                $html .= '</tr></thead>';
            }
            $html .= '<tbody>';
            foreach ($content->tableRows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . self::e((string) $cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($content->signatureName !== null && trim($content->signatureName) !== '') {
            $html .= '<div class="signature"><div class="line"></div>' . self::e($content->signatureName) . '</div>';
        }

        $html .= '<div class="footer">Emitido em ' . self::e($content->issuedAt->format('d/m/Y H:i')) . '</div>';

        return $html . '</body></html>';
    }

    /** "Label: value" → label in bold on the left, value after it; both escaped. */
    private static function subjectLine(string $line): string
    {
        $separator = strpos($line, ':');
        if ($separator === false) {
            return self::e($line);
        }

        return '<strong>' . self::e(substr($line, 0, $separator + 1)) . '</strong>'
            . self::e(substr($line, $separator + 1));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

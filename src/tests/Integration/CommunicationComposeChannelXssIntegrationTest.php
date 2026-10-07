<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-26 (revisão final rodada 2, bloqueante): a troca de canal na
 * composição recarrega o combo de templates com o nome digitado pelo usuário.
 * TCombo::reload não escapa `\` no literal JS e tcombo_add_option monta HTML,
 * então `\x3cimg ...\x3e` no nome virava tag. A recarga tem de mandar os
 * rótulos como literal JSON (JSON_HEX_*) e criá-los como texto (CvCombo).
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global); a
 * lista de templates é trocada por uma subclasse (gancho protegido
 * loadTemplateOptions), sem sessão nem banco.
 */
final class CommunicationComposeChannelXssIntegrationTest
{
    private const MARKER = '@@T26XSS@@';

    private const LABEL_ESCAPED = '\x3cimg src=x onerror=alert(1)\x3e — Confirmação';
    private const LABEL_RAW = "F7A teste <img src=x onerror=alert(2)> ');alert(3);('";

    public function testChannelChangeSendsTemplateNamesAsTextOnly(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("CommunicationComposeForm")) { $out["missing"] = true; } else {'
            . 'class CommunicationComposeFormT26 extends CommunicationComposeForm {'
            . ' protected static function loadTemplateOptions(string $channel, string $action): array {'
            . ' return [7 => ' . var_export(self::LABEL_ESCAPED, true) . ', 8 => ' . var_export(self::LABEL_RAW, true) . ']; }'
            . ' }'
            . 'CommunicationComposeFormT26::onChangeChannel(["channel" => "email"]);'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'CommunicationComposeForm must exist');
        Assert::true(!isset($result['error']), 'onChangeChannel threw: ' . (string) ($result['error'] ?? ''));

        $html = (string) $result['html'];

        Assert::stringContains('tcombo_clear', $html, 'the template combo must be reloaded: ' . $html);
        Assert::false(str_contains($html, 'tcombo_add_option'), 'labels must not go through the vendor HTML sink: ' . $html);
        Assert::false(stripos($html, '<img') !== false, 'template name must not open an HTML tag: ' . $html);
        Assert::same(
            substr_count(strtolower($html), '<script'),
            substr_count(strtolower($html), '</script>'),
            'only the scripts opened by the form are closed: ' . $html,
        );

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        Assert::stringContains((string) json_encode(self::LABEL_ESCAPED, $flags), $html, 'escaped-looking name goes as a JSON literal');
        Assert::stringContains((string) json_encode(self::LABEL_RAW, $flags), $html, 'raw-tag name goes as a JSON literal');
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
            . ' catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . '$out["html"] = (string) ob_get_clean();'
            . 'echo "\n' . self::MARKER . '", json_encode($out);';

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'subprocess output: ' . $out);

        return $decoded;
    }
}

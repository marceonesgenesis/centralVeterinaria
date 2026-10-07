<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-24 (revisão final, bloqueante): o assunto e o corpo renderizados
 * do template chegam ao navegador dentro de um `<script>`. TForm::sendData só
 * aplica addslashes, então `</script>` no texto fechava o script e o resto
 * virava HTML executável. O preenchimento tem de codificar o valor como
 * literal JS seguro (JSON com JSON_HEX_TAG|AMP|APOS|QUOT).
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global); a
 * renderização é trocada por uma subclasse (gancho protegido
 * renderTemplateData, declarada no subprocesso), sem sessão nem banco.
 */
final class CommunicationComposeTemplateXssIntegrationTest
{
    private const MARKER = '@@T24XSS@@';

    private const SUBJECT = "F7A teste '</script><svg onload=alert(1)>\"";
    private const BODY = "Ola F7A teste</script><img src=x onerror=alert(2)>\nlinha 2 & <b>fim</b>";

    public function testTemplateTextIsSentAsASafeScriptLiteral(): void
    {
        $result = $this->runAdianti(
            'if (!class_exists("CommunicationComposeForm")) { $out["missing"] = true; } else {'
            . 'class CommunicationComposeFormT24 extends CommunicationComposeForm {'
            . ' protected static function renderTemplateData(int $templateId, int $tutorId, ?int $patientId): ?array {'
            . ' return ["subject" => ' . var_export(self::SUBJECT, true) . ', "body" => ' . var_export(self::BODY, true) . ', "purpose" => "custom"]; }'
            . ' }'
            . 'CommunicationComposeFormT24::onChangeTemplate(["template_id" => "5", "tutor_id" => "7", "patient_id" => "9"]);'
            . '}'
        );

        Assert::true(!isset($result['missing']), 'CommunicationComposeForm must exist');
        Assert::true(!isset($result['error']), 'onChangeTemplate threw: ' . (string) ($result['error'] ?? ''));

        $html = (string) $result['html'];

        Assert::stringContains('tform_send_data', $html, 'template text must be sent to the form');
        Assert::false(stripos($html, '<img') !== false, 'body must not open an HTML tag: ' . $html);
        Assert::false(stripos($html, '<svg') !== false, 'subject must not open an HTML tag: ' . $html);
        Assert::false(stripos($html, '<b>') !== false, 'markup in the body stays inside the JS literal');
        Assert::same(
            substr_count(strtolower($html), '<script'),
            substr_count(strtolower($html), '</script>'),
            'only the scripts opened by the form are closed: ' . $html,
        );

        $literal = json_encode(self::BODY, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        Assert::stringContains((string) $literal, $html, 'body goes as a JSON-encoded JS literal');
        $subject = json_encode(self::SUBJECT, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        Assert::stringContains((string) $subject, $html, 'subject goes as a JSON-encoded JS literal');
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

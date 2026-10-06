<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-17: histórico (CommunicationMessageList) e ficha
 * (CommunicationMessageView) da mensagem. A ficha declara as ações
 * onMarkSent, onCancel e onRetry; o link do WhatsApp abre em nova aba com
 * rel="noopener noreferrer"; corpo e nome do tutor com <script> saem
 * escapados; o histórico mascara o destinatário.
 *
 * Os controllers só carregam com o Adianti, num processo PHP separado
 * (init.php define _t() global). Nenhum acesso a banco: os painéis são
 * montados por reflexão com mensagens reconstituídas.
 */
final class CommunicationMessageScreensIntegrationTest
{
    /**
     * Linha mínima para OutboundMessage::reconstitute, em PHP.
     */
    private const ROW = '["id" => 41, "tenant_id" => 1, "system_unit_id" => 1, "tutor_id" => 9, "patient_id" => null,'
        . ' "template_id" => null, "purpose" => "custom", "channel" => "%s", "origin" => "manual",'
        . ' "legal_basis" => "consent", "source_type" => null, "source_id" => null, "dedupe_key" => null,'
        . ' "recipient" => "%s", "subject" => %s, "body_text" => "<script>alert(1)</script>\nSegunda linha",'
        . ' "status" => "%s", "attempt_count" => 0, "last_error_code" => %s, "created_at" => "2026-10-06 09:00:00"]';

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
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'CommunicationMessage subprocess output: ' . (string) $out);

        return $decoded;
    }

    private static function row(string $channel, string $recipient, string $status, ?string $errorCode = null): string
    {
        return sprintf(
            self::ROW,
            $channel,
            $recipient,
            $channel === 'email' ? '"Assunto <b>x</b>"' : 'null',
            $status,
            $errorCode === null ? 'null' : '"' . $errorCode . '"'
        );
    }

    public function testViewDeclaresStatusActions(): void
    {
        $result = $this->runAdianti(
            '$r = new ReflectionClass("CommunicationMessageView");'
            . 'foreach (["onMarkSent", "onCancel", "onRetry"] as $m) {'
            . ' $out[$m] = $r->hasMethod($m) && $r->getMethod($m)->isPublic() && $r->getMethod($m)->isStatic(); }'
            . '$actions = [];'
            . 'foreach ($r->getReflectionConstants() as $c) { if (str_starts_with($c->getName(), "ACTION_")) { $actions[] = $c->getValue(); } }'
            . '$out["actions"] = $actions;'
            . '$out["pattern"] = CentralVet\Authorization\AuthorizationRequest::ACTION_PATTERN;'
        );

        Assert::true(!isset($result['error']), 'reflection threw: ' . (string) ($result['error'] ?? ''));
        Assert::same(true, $result['onMarkSent'] ?? null, 'onMarkSent must be public static');
        Assert::same(true, $result['onCancel'] ?? null, 'onCancel must be public static');
        Assert::same(true, $result['onRetry'] ?? null, 'onRetry must be public static');

        $actions = (array) ($result['actions'] ?? []);

        foreach ([
            'CommunicationMessageView::onMarkSent',
            'CommunicationMessageView::onCancel',
            'CommunicationMessageView::onRetry',
        ] as $expected) {
            Assert::true(in_array($expected, $actions, true), 'missing action constant ' . $expected);
        }

        foreach ($actions as $action) {
            Assert::true(
                preg_match((string) $result['pattern'], (string) $action) === 1,
                'action ' . (string) $action . ' must match AuthorizationRequest::ACTION_PATTERN'
            );
        }
    }

    public function testViewWithoutIdShowsEmptyState(): void
    {
        $result = $this->runAdianti('$page = new CommunicationMessageView([]); $page->show();');

        Assert::true(!isset($result['error']), 'view without id threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains((string) $result['html'], 'cv-state--empty'), 'view without id must render cv-state--empty');
    }

    public function testQueuedWhatsAppOffersOpenLinkMarkSentAndDiscard(): void
    {
        $result = $this->runAdianti(
            '$msg = CentralVet\Domain\OutboundMessage::reconstitute(' . self::row('whatsapp', '5585999991234', 'queued') . ');'
            . '$data = ["message" => $msg, "tutor_name" => "Ana", "patient_name" => null, "whatsapp_url" => "https://wa.me/5585999991234?text=Oi%20%3Cb%3E"];'
            . '$r = new ReflectionClass("CommunicationMessageView"); $v = $r->newInstanceWithoutConstructor();'
            . '$m = $r->getMethod("buildActionsPanel"); $m->setAccessible(true);'
            . '$m->invoke($v, $data)->show();'
        );

        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(preg_match('/<a [^>]*href="https:\/\/wa\.me\/5585999991234[^"]*"[^>]*>/', $html, $m) === 1, 'queued WhatsApp must link to wa.me');
        Assert::true(str_contains($m[0], 'rel="noopener noreferrer"'), 'wa.me link must carry rel="noopener noreferrer"');
        Assert::true(str_contains($m[0], 'target="_blank"'), 'wa.me link must open in a new tab');
        Assert::true(!str_contains($m[0], 'generator="adianti"'), 'wa.me link must not go through the Adianti router');
        Assert::true(str_contains($html, 'onAskMarkSent'), 'queued WhatsApp must offer mark as sent');
        Assert::true(str_contains($html, 'onAskCancel'), 'queued WhatsApp must offer discard');
        Assert::true(!str_contains($html, 'onAskRetry'), 'queued WhatsApp must not offer retry');
        Assert::true(preg_match_all('/class="[^"]*\bcv-touch-target\b/', $html) >= 3, 'the three actions must carry cv-touch-target');
    }

    public function testQueuedEmailOffersOnlyDiscardAndFailedOffersRetry(): void
    {
        $result = $this->runAdianti(
            '$r = new ReflectionClass("CommunicationMessageView"); $v = $r->newInstanceWithoutConstructor();'
            . '$m = $r->getMethod("buildActionsPanel"); $m->setAccessible(true);'
            . '$queued = CentralVet\Domain\OutboundMessage::reconstitute(' . self::row('email', 'fulano@example.invalid', 'queued') . ');'
            . '$m->invoke($v, ["message" => $queued, "tutor_name" => "Ana", "patient_name" => null, "whatsapp_url" => null])->show();'
            . 'echo "<!--split-->";'
            . '$failed = CentralVet\Domain\OutboundMessage::reconstitute(' . self::row('email', 'fulano@example.invalid', 'failed', 'smtp_auth') . ');'
            . '$m->invoke($v, ["message" => $failed, "tutor_name" => "Ana", "patient_name" => null, "whatsapp_url" => null])->show();'
        );

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        [$queued, $failed] = explode('<!--split-->', (string) $result['html']) + [1 => ''];

        Assert::true(str_contains($queued, 'onAskCancel'), 'queued e-mail must offer discard');
        Assert::true(!str_contains($queued, 'onAskMarkSent') && !str_contains($queued, 'wa.me'), 'queued e-mail must not offer WhatsApp actions');
        Assert::true(str_contains($failed, 'onAskRetry'), 'failed message must offer retry');
        Assert::true(!str_contains($failed, 'onAskCancel'), 'failed message must not offer discard');
    }

    public function testDetailEscapesBodySubjectAndTutorName(): void
    {
        $result = $this->runAdianti(
            '$msg = CentralVet\Domain\OutboundMessage::reconstitute(' . self::row('email', 'fulano@example.invalid', 'failed', 'smtp_auth') . ');'
            . '$data = ["message" => $msg, "tutor_name" => "<script>tutor()</script>", "patient_name" => "<img src=x>", "whatsapp_url" => null];'
            . '$r = new ReflectionClass("CommunicationMessageView"); $v = $r->newInstanceWithoutConstructor();'
            . '$m = $r->getMethod("buildDetailPanel"); $m->setAccessible(true);'
            . '$m->invoke($v, $data)->show();'
        );

        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::true(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'body must be escaped');
        Assert::true(!str_contains($html, '<script>'), 'no raw <script> may reach the page');
        Assert::true(!str_contains($html, '<img src=x>'), 'patient name must be escaped');
        Assert::true(!str_contains($html, '<b>x</b>'), 'subject must be escaped');
        Assert::true(preg_match('/&lt;\/script&gt;<br\s*\/?>\s*Segunda linha/', $html) === 1, 'body line breaks must become <br> after escaping');
        Assert::true(str_contains($html, 'fulano@example.invalid'), 'the detail shows the full recipient');
    }

    public function testListMasksRecipientAndEscapesTutorName(): void
    {
        $result = $this->runAdianti(
            '$out["email"] = CommunicationMessageList::maskRecipient("fulano@example.invalid", "email");'
            . '$out["phone"] = CommunicationMessageList::maskRecipient("5585999991234", "whatsapp");'
            . '$msg = CentralVet\Domain\OutboundMessage::reconstitute(' . self::row('email', 'fulano@example.invalid', 'queued') . ');'
            . '$r = new ReflectionClass("CommunicationMessageList");'
            . '$m = $r->getMethod("table"); $m->setAccessible(true);'
            . '$m->invoke(null, [$msg], [9 => "<script>tutor()</script>"])->show();'
        );

        $html = (string) $result['html'];

        Assert::true(!isset($result['error']), 'render threw: ' . (string) ($result['error'] ?? ''));
        Assert::same('fu***@example.invalid', $result['email'] ?? null);
        Assert::true(str_ends_with((string) ($result['phone'] ?? ''), '1234'), 'phone keeps the last 4 digits');
        Assert::true(!str_contains((string) ($result['phone'] ?? ''), '55859999'), 'phone hides all but the last 4 digits');
        Assert::true(str_contains($html, 'fu***@example.invalid'), 'list shows the masked recipient');
        Assert::true(!str_contains($html, 'fulano@'), 'list never shows the full recipient');
        Assert::true(str_contains($html, '&lt;script&gt;tutor()'), 'tutor name must be escaped');
        Assert::true(!str_contains($html, '<script>'), 'no raw <script> may reach the list');
        Assert::true(str_contains($html, 'class=CommunicationMessageView&id=41') || str_contains($html, 'class=CommunicationMessageView&amp;id=41'), 'row links to the detail');
    }
}

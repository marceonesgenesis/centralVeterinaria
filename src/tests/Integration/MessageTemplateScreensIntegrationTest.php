<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7A, T-16: telas de templates de mensagem. MessageTemplateForm expõe
 * purpose, channel, name, subject e body_text e lista os placeholders de
 * MessageTemplateRenderer::PLACEHOLDERS; as ações declaradas incluem
 * MessageTemplateForm::onSave e MessageTemplateList::onToggle; o nome vindo
 * do banco sai escapado na lista.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de BedFormIntegrationTest.
 */
final class MessageTemplateScreensIntegrationTest
{
    private const MARKER = '@@MSGTEMPLATE@@';

    /** @return array<string, mixed> */
    private function runInAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";' . $body;

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos((string) $out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr((string) $out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'MessageTemplate subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testFormHasTemplateFields(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("MessageTemplateForm")) { echo "@@MSGTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new MessageTemplateForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$fields = [];'
            . 'foreach (["purpose", "channel", "name", "subject", "body_text"] as $n) { $fields[$n] = $form->getField($n) !== null; }'
            . 'echo "@@MSGTEMPLATE@@", json_encode($fields);'
        );

        Assert::same(
            ['purpose' => true, 'channel' => true, 'name' => true, 'subject' => true, 'body_text' => true],
            $result
        );
    }

    public function testDeclaredActionsIncludeSaveAndToggleAndMatchThePattern(): void
    {
        $decoded = $this->runInAdianti(
            '$out = [];'
            . 'foreach (["MessageTemplateList", "MessageTemplateForm"] as $c) {'
            . ' if (!class_exists($c)) { $out[$c] = null; continue; }'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out[$c][$k] = $v; } } }'
            . 'echo "@@MSGTEMPLATE@@", json_encode($out);'
        );

        Assert::true(is_array($decoded['MessageTemplateList'] ?? null), 'MessageTemplateList must declare ACTION_* constants');
        Assert::true(is_array($decoded['MessageTemplateForm'] ?? null), 'MessageTemplateForm must declare ACTION_* constants');
        Assert::true(in_array('MessageTemplateForm::onSave', $decoded['MessageTemplateForm'], true), 'MessageTemplateForm::onSave must be declared');
        Assert::true(in_array('MessageTemplateList::onToggle', $decoded['MessageTemplateList'], true), 'MessageTemplateList::onToggle must be declared');

        foreach ($decoded as $class => $actions) {
            foreach ($actions as $name => $action) {
                Assert::same(
                    1,
                    preg_match(AuthorizationRequest::ACTION_PATTERN, (string) $action),
                    "{$class}::{$name} = '{$action}' must match AuthorizationRequest::ACTION_PATTERN"
                );
                Assert::true(str_starts_with((string) $action, $class . '::'), "{$class}::{$name} must be '{$class}::<method>'");
            }
        }
    }

    public function testFormHelpListsEveryPlaceholder(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("MessageTemplateForm")) { echo "@@MSGTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new MessageTemplateForm([]);'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . '$found = [];'
            . 'foreach (\CentralVet\Domain\MessageTemplateRenderer::PLACEHOLDERS as $p) { if (str_contains($html, "{{" . $p . "}}")) { $found[] = $p; } }'
            . 'echo "@@MSGTEMPLATE@@", json_encode(["found" => $found]);'
        );

        Assert::same(9, count(MessageTemplateRenderer::PLACEHOLDERS));
        Assert::same(['found' => MessageTemplateRenderer::PLACEHOLDERS], $result);
    }

    public function testListEscapesTemplateNameAndActionsAreTouchTargets(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("MessageTemplateList")) { echo "@@MSGTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new MessageTemplateList([]);'
            . '$grid = (new ReflectionProperty($page, "datagrid"))->getValue($page);'
            . '$grid->addItem((object) ["id" => 1, "name" => "<script>alert(1)</script>", "purpose" => "custom", "channel" => "email", "status" => "active"]);'
            . 'ob_start(); $grid->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<a [^>]*href=\"[^\"]*(onEdit|onToggle)[^\"]*\"[^>]*>\\s*<span[^>]*class=\"([^\"]*)\"/", $html, $m);'
            . '$touch = array_filter($m[2], fn ($c) => str_contains($c, "cv-touch-target"));'
            . 'echo "@@MSGTEMPLATE@@", json_encode(['
            . ' "raw" => str_contains($html, "<script>alert(1)</script>"),'
            . ' "escaped" => str_contains($html, "&lt;script&gt;alert(1)&lt;/script&gt;"),'
            . ' "actions" => count($m[2]), "touch" => count($touch)]);'
        );

        // template ativo: Editar e Desativar visíveis (Ativar oculto)
        Assert::same(['raw' => false, 'escaped' => true, 'actions' => 2, 'touch' => 2], $result);
    }
}

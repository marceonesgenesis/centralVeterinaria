<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Domain\DocumentTemplateRenderer;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 7B, T-17: telas de template de documento. DocumentTemplateForm expõe
 * name, body_text e status (kind oculto, fixo em medical_certificate) e lista
 * os placeholders de DocumentTemplateRenderer::PLACEHOLDERS; a lista mostra
 * a ação Editar como <a> com cv-touch-target e escapa o nome vindo do banco.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de BedFormIntegrationTest.
 */
final class DocumentTemplateScreensIntegrationTest
{
    private const MARKER = '@@DOCTEMPLATE@@';

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

        Assert::true(is_array($decoded), 'DocumentTemplate subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testFormHasTemplateFieldsWithFixedHiddenKind(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("DocumentTemplateForm")) { echo "@@DOCTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new DocumentTemplateForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$fields = [];'
            . 'foreach (["name", "body_text", "status", "kind"] as $n) { $f = $form->getField($n); $fields[$n] = $f === null ? null : (new ReflectionClass($f))->getShortName(); }'
            . '$status = $form->getField("status");'
            . '$items = $status === null ? [] : array_keys((new ReflectionProperty($status, "items"))->getValue($status));'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . 'echo "@@DOCTEMPLATE@@", json_encode(["fields" => $fields, "status_items" => $items,'
            . ' "kind_value" => preg_match("/name=\"kind\"[^>]*value=\"medical_certificate\"|value=\"medical_certificate\"[^>]*name=\"kind\"/", $html) === 1]);'
        );

        Assert::same(
            [
                'fields' => ['name' => 'TEntry', 'body_text' => 'TText', 'status' => 'TCombo', 'kind' => 'THidden'],
                'status_items' => ['active', 'inactive'],
                'kind_value' => true,
            ],
            $result
        );
    }

    public function testFormHelpListsEveryPlaceholder(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("DocumentTemplateForm")) { echo "@@DOCTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new DocumentTemplateForm([]);'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . '$found = [];'
            . 'foreach (\CentralVet\Domain\DocumentTemplateRenderer::PLACEHOLDERS as $p) { if (str_contains($html, "{{" . $p . "}}")) { $found[] = $p; } }'
            . 'echo "@@DOCTEMPLATE@@", json_encode(["found" => $found]);'
        );

        Assert::true(in_array('patient_name', DocumentTemplateRenderer::PLACEHOLDERS, true));
        Assert::same(['found' => DocumentTemplateRenderer::PLACEHOLDERS], $result);
    }

    public function testFormBackLinkReturnsToTheTemplateList(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("DocumentTemplateForm")) { echo "@@DOCTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new DocumentTemplateForm([]);'
            . 'ob_start(); $page->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<a\\s[^>]*>/i", $html, $tags);'
            . '$back = array_filter($tags[0], fn ($t) => str_contains($t, "href=\"index.php?class=DocumentTemplateList\"")'
            . ' && preg_match("/class=\"[^\"]*\\bcv-touch-target\\b/", $t) === 1);'
            . 'echo "@@DOCTEMPLATE@@", json_encode(["back" => count($back)]);'
        );

        Assert::same(['back' => 1], $result, 'the form must link back to DocumentTemplateList with a cv-touch-target <a>');
    }

    public function testDeclaredActionsIncludeSaveAndMatchThePattern(): void
    {
        $decoded = $this->runInAdianti(
            '$out = [];'
            . 'foreach (["DocumentTemplateList", "DocumentTemplateForm"] as $c) {'
            . ' if (!class_exists($c)) { $out[$c] = null; continue; }'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out[$c][$k] = $v; } } }'
            . 'echo "@@DOCTEMPLATE@@", json_encode($out);'
        );

        Assert::true(is_array($decoded['DocumentTemplateList'] ?? null), 'DocumentTemplateList must declare ACTION_* constants');
        Assert::true(is_array($decoded['DocumentTemplateForm'] ?? null), 'DocumentTemplateForm must declare ACTION_* constants');
        Assert::true(in_array('DocumentTemplateForm::onSave', $decoded['DocumentTemplateForm'], true), 'DocumentTemplateForm::onSave must be declared');

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

    public function testListEscapesTemplateNameAndEditIsTouchTarget(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("DocumentTemplateList")) { echo "@@DOCTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new DocumentTemplateList([]);'
            . '$grid = (new ReflectionProperty($page, "datagrid"))->getValue($page);'
            . '$grid->addItem((object) ["id" => 1, "name" => "<script>alert(1)</script>", "kind" => "medical_certificate", "status" => "active"]);'
            . 'ob_start(); $grid->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<a [^>]*href=\"[^\"]*DocumentTemplateForm[^\"]*\"[^>]*>/", $html, $links);'
            . '$touch = array_filter($links[0], fn ($t) => preg_match("/class=\"[^\"]*cv-touch-target/", $t) === 1);'
            . 'echo "@@DOCTEMPLATE@@", json_encode(['
            . ' "raw" => str_contains($html, "<script>alert(1)</script>"),'
            . ' "escaped" => str_contains($html, "&lt;script&gt;alert(1)&lt;/script&gt;"),'
            . ' "edit_label" => str_contains($html, ">Edit<") || str_contains($html, ">" . _t("Edit") . "<"),'
            . ' "actions" => count($links[0]), "touch" => count($touch)]);'
        );

        Assert::same(['raw' => false, 'escaped' => true, 'edit_label' => true, 'actions' => 1, 'touch' => 1], $result);
    }

    public function testListActionsColumnIsLeftAlignedAndKindLabelIsNotBorrowedFromTheForm(): void
    {
        $result = $this->runInAdianti(
            'if (!class_exists("DocumentTemplateList")) { echo "@@DOCTEMPLATE@@", json_encode(["missing" => true]); return; }'
            . '$page = new DocumentTemplateList([]);'
            . '$grid = (new ReflectionProperty($page, "datagrid"))->getValue($page);'
            . '$grid->addItem((object) ["id" => 1, "name" => "F7B teste Atestado", "kind" => "medical_certificate", "status" => "active"]);'
            . 'ob_start(); $grid->show(); $html = ob_get_clean();'
            . 'echo "@@DOCTEMPLATE@@", json_encode(['
            . ' "right" => preg_match("/(text-align|align)\\s*[:=]\\s*[\"\']?\\s*right|flex-end/i", $html) === 1,'
            . ' "kind" => str_contains($html, _t("Medical certificate"))]);'
        );

        Assert::same(['right' => false, 'kind' => true], $result, 'actions column left-aligned (labels always left) and kind label rendered');

        $list = (string) file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/DocumentTemplateList.php');
        Assert::false(str_contains($list, 'DocumentTemplateForm::kindLabel'), 'the list must not borrow the kind label from the form');
    }
}

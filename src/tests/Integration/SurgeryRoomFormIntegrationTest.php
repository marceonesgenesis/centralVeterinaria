<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-12: telas de salas cirúrgicas. SurgeryRoomForm expõe code e
 * name (code só leitura na edição); as ações de autorização (ACTION_*) de
 * SurgeryRoomList e SurgeryRoomForm seguem AuthorizationRequest::ACTION_PATTERN
 * no formato 'Classe::método'.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de BedFormIntegrationTest.
 */
final class SurgeryRoomFormIntegrationTest
{
    private const MARKER = '@@SURGERYROOM@@';

    /** @return array<string, mixed> */
    private function runInAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";' . $body;

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        // Sem sessão, TMessage pode escrever antes do JSON: só o que vem
        // depois do marcador conta.
        $pos = strrpos((string) $out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr((string) $out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'SurgeryRoom subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testSurgeryRoomFormHasCodeAndNameFields(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryRoomForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$fields = [];'
            . 'foreach (["code", "name"] as $n) { $fields[$n] = $form->getField($n) !== null; }'
            . 'echo "@@SURGERYROOM@@", json_encode($fields);'
        );

        Assert::same(['code' => true, 'name' => true], $result);
    }

    public function testCodeIsReadOnlyWhenEditingAnExistingRoom(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryRoomForm(["id" => 7]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'echo "@@SURGERYROOM@@", json_encode(["editable" => (bool) $form->getField("code")->getEditable()]);'
        );

        Assert::same(['editable' => false], $result);
    }

    public function testAllAuthorizationActionsMatchTheRequestPattern(): void
    {
        $decoded = $this->runInAdianti(
            '$out = [];'
            . 'foreach (["SurgeryRoomList", "SurgeryRoomForm"] as $c) {'
            . ' if (!class_exists($c)) { $out[$c] = null; continue; }'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out[$c][$k] = $v; } } }'
            . 'echo "@@SURGERYROOM@@", json_encode($out);'
        );

        Assert::same(['SurgeryRoomList', 'SurgeryRoomForm'], array_keys($decoded));

        foreach ($decoded as $class => $actions) {
            Assert::true(is_array($actions) && $actions !== [], "{$class} must declare ACTION_* constants");

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

    public function testSurgeryRoomListRowActionsAreTouchTargets(): void
    {
        $result = $this->runInAdianti(
            '$page = new SurgeryRoomList([]);'
            . '$grid = (new ReflectionProperty($page, "datagrid"))->getValue($page);'
            . '$grid->addItem((object) ["id" => 1, "code" => "S1", "name" => "x", "status" => "active"]);'
            . 'ob_start(); $grid->show(); $html = ob_get_clean();'
            . 'preg_match_all("/<a [^>]*href=\"[^\"]*(onEdit|onDeactivate|onActivate)[^\"]*\"[^>]*>\\s*<span[^>]*class=\"([^\"]*)\"/", $html, $m);'
            . '$touch = array_filter($m[2], fn ($c) => str_contains($c, "cv-touch-target"));'
            . 'echo "@@SURGERYROOM@@", json_encode(["actions" => count($m[2]), "touch" => count($touch)]);'
        );

        // sala ativa: Editar e Desativar visíveis (Ativar oculto)
        Assert::same(['actions' => 2, 'touch' => 2], $result);
    }
}

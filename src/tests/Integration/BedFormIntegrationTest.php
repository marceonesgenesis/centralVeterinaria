<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-12: BedForm (cadastro de leitos da unidade) expõe os campos
 * code, name e daily_rate; na edição (id), code fica só leitura.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de AppointmentFormPostIntegrationTest.
 */
final class BedFormIntegrationTest
{
    private const MARKER = '@@BEDFORM@@';

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

        Assert::true(is_array($decoded), 'BedForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testBedFormHasCodeNameAndDailyRateFields(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedForm([]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$fields = [];'
            . 'foreach (["code", "name", "daily_rate"] as $n) { $fields[$n] = $form->getField($n) !== null; }'
            . 'echo "@@BEDFORM@@", json_encode($fields);'
        );

        Assert::same(['code' => true, 'name' => true, 'daily_rate' => true], $result);
    }

    public function testCodeIsReadOnlyWhenEditingAnExistingBed(): void
    {
        $result = $this->runInAdianti(
            '$page = new BedForm(["id" => 7]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'echo "@@BEDFORM@@", json_encode(["editable" => (bool) $form->getField("code")->getEditable()]);'
        );

        Assert::same(['editable' => false], $result);
    }
}

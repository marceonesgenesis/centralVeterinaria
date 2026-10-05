<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-13: a tela de admissão (HospitalizationAdmissionForm) expõe os
 * campos do contrato (encounter_id oculto, bed_id,
 * responsible_system_user_id, reason_text, expected_discharge_date) e, sem
 * encounter_id, abre o estado vazio sem lançar exceção.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * mesmo padrão de AppointmentFormPostIntegrationTest.
 */
final class HospitalizationAdmissionFormIntegrationTest
{
    private const FIELDS = [
        'encounter_id',
        'bed_id',
        'responsible_system_user_id',
        'reason_text',
        'expected_discharge_date',
    ];

    /**
     * @return array{error: ?string, fields: array<string, ?string>}
     */
    private function buildForm(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$result = ["error" => null, "fields" => []];'
            . 'try {'
            . '  ob_start();'
            . '  $page = new HospitalizationAdmissionForm([]);'
            . '  ob_end_clean();'
            . '  $form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '  foreach (' . var_export(self::FIELDS, true) . ' as $name) {'
            . '    $field = is_object($form) ? $form->getField($name) : null;'
            . '    $result["fields"][$name] = is_object($field) ? get_class($field) : null;'
            . '  }'
            . '} catch (\Throwable $e) {'
            . '  while (ob_get_level() > 0) { ob_end_clean(); }'
            . '  $result["error"] = get_class($e) . ": " . $e->getMessage();'
            . '}'
            . 'echo json_encode($result);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'HospitalizationAdmissionForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testFormWithoutEncounterDoesNotThrow(): void
    {
        $result = $this->buildForm();

        Assert::same(null, $result['error'], 'new HospitalizationAdmissionForm([]) must not throw: ' . (string) $result['error']);
    }

    public function testFormExposesTheAdmissionFields(): void
    {
        $result = $this->buildForm();

        foreach (self::FIELDS as $name) {
            Assert::true(
                isset($result['fields'][$name]) && is_string($result['fields'][$name]),
                "field '{$name}' must exist in the admission form"
            );
        }

        Assert::same('THidden', $result['fields']['encounter_id'] ?? null, 'encounter_id must be hidden');
    }
}

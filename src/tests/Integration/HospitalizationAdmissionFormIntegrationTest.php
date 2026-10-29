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
     * @return array{error: ?string, fields: array<string, ?string>, actions: list<string>}
     */
    private function buildForm(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$result = ["error" => null, "fields" => [], "actions" => []];'
            . 'try {'
            . '  ob_start();'
            . '  $page = new HospitalizationAdmissionForm([]);'
            . '  ob_end_clean();'
            . '  $form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '  foreach (' . var_export(self::FIELDS, true) . ' as $name) {'
            . '    $field = is_object($form) ? $form->getField($name) : null;'
            . '    $result["fields"][$name] = is_object($field) ? get_class($field) : null;'
            . '  }'
            . '  foreach ((is_object($form) ? (array) $form->getActions() : []) as $button) {'
            . '    $result["actions"][] = (string) $button->class;'
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

        $hiddenClass = (string) ($result['fields']['encounter_id'] ?? '');
        Assert::true(
            $hiddenClass === 'THidden' || str_ends_with($hiddenClass, '\\THidden'),
            "encounter_id must be hidden, got '{$hiddenClass}'"
        );
    }

    public function testActionButtonsAreTouchTargets(): void
    {
        $actions = $this->buildForm()['actions'] ?? [];

        Assert::true($actions !== [], 'the admission form must have action buttons');

        foreach ($actions as $class) {
            Assert::true(
                in_array('cv-touch-target', preg_split('/\\s+/', trim($class)) ?: [], true),
                "action button must carry cv-touch-target (min 44x44 on tablet), got '{$class}'"
            );
        }
    }
}

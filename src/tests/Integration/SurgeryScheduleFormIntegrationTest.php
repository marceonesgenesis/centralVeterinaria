<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6B, T-13: a tela de agendamento cirúrgico (SurgeryScheduleForm)
 * expõe os 10 campos do contrato (encounter_id oculto), renderiza sem
 * exceção quando aberta sem encounter_id nem id e declara ações
 * (`ACTION_*`) que casam com AuthorizationRequest::ACTION_PATTERN.
 *
 * O Adianti roda num processo PHP separado (init.php define _t() global),
 * no padrão de HospitalizationAdmissionFormIntegrationTest.
 */
final class SurgeryScheduleFormIntegrationTest
{
    private const MARKER = '@@SURGERYSCHEDULEFORM@@';

    private const FIELDS = [
        'encounter_id',
        'room_id',
        'procedure_catalog_item_id',
        'surgeon_system_user_id',
        'scheduled_start_at',
        'duration_minutes',
        'anesthetist_system_user_id',
        'assistant_system_user_id',
        'circulating_system_user_id',
        'notes_text',
    ];

    /**
     * @return array{error: ?string, fields: array<string, ?string>, actions: array<string, string>, buttons: list<string>, html: int}
     */
    private function buildForm(): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$result = ["error" => null, "fields" => [], "actions" => [], "buttons" => [], "html" => 0];'
            . 'try {'
            . '  ob_start();'
            . '  $page = new SurgeryScheduleForm([]);'
            . '  $page->show();'
            . '  $result["html"] = strlen((string) ob_get_clean());'
            . '  $form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '  foreach (' . var_export(self::FIELDS, true) . ' as $name) {'
            . '    $field = is_object($form) ? $form->getField($name) : null;'
            . '    $result["fields"][$name] = is_object($field) ? get_class($field) : null;'
            . '  }'
            . '  foreach ((new ReflectionClass("SurgeryScheduleForm"))->getConstants() as $k => $v) {'
            . '    if (str_starts_with($k, "ACTION_")) { $result["actions"][$k] = (string) $v; }'
            . '  }'
            . '  foreach ((is_object($form) ? (array) $form->getActions() : []) as $button) {'
            . '    $result["buttons"][] = (string) $button->class;'
            . '  }'
            . '} catch (\Throwable $e) {'
            . '  while (ob_get_level() > 0) { ob_end_clean(); }'
            . '  $result["error"] = get_class($e) . ": " . $e->getMessage();'
            . '}'
            . 'echo ' . var_export(self::MARKER, true) . ', json_encode($result);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos((string) $out, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr((string) $out, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'SurgeryScheduleForm subprocess output: ' . (string) $out);

        return $decoded;
    }

    public function testFormWithoutEncounterNorSurgeryRendersWithoutException(): void
    {
        $result = $this->buildForm();

        Assert::same(null, $result['error'], 'new SurgeryScheduleForm([]) must render without exception: ' . (string) $result['error']);
        Assert::true($result['html'] > 0, 'the page must render some HTML');
    }

    public function testFormExposesTheTenContractFields(): void
    {
        $result = $this->buildForm();

        foreach (self::FIELDS as $name) {
            Assert::true(
                isset($result['fields'][$name]) && is_string($result['fields'][$name]),
                "field '{$name}' must exist in the surgery schedule form"
            );
        }

        $hiddenClass = (string) ($result['fields']['encounter_id'] ?? '');
        Assert::true(
            $hiddenClass === 'THidden' || str_ends_with($hiddenClass, '\\THidden'),
            "encounter_id must be hidden, got '{$hiddenClass}'"
        );
    }

    public function testActionConstantsMatchTheAuthorizationPattern(): void
    {
        $actions = $this->buildForm()['actions'] ?? [];

        Assert::true($actions !== [], 'SurgeryScheduleForm must declare ACTION_* constants');
        Assert::true(in_array('SurgeryScheduleForm::onSave', $actions, true), 'ACTION for onSave is required');
        Assert::true(in_array('SurgeryScheduleForm::onSaveTeam', $actions, true), 'ACTION for onSaveTeam is required');

        foreach ($actions as $name => $action) {
            Assert::true(
                preg_match(AuthorizationRequest::ACTION_PATTERN, $action) === 1,
                "{$name} ('{$action}') must match AuthorizationRequest::ACTION_PATTERN"
            );
        }
    }

    public function testActionButtonsAreTouchTargets(): void
    {
        $buttons = $this->buildForm()['buttons'] ?? [];

        Assert::true($buttons !== [], 'the schedule form must have action buttons');

        foreach ($buttons as $class) {
            Assert::true(
                in_array('cv-touch-target', preg_split('/\\s+/', trim($class)) ?: [], true),
                "action button must carry cv-touch-target, got '{$class}'"
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Domain\HospitalizationOrder;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

/**
 * Fase 6A, T-15: formulários clínicos da internação (prescrição e
 * evolução/parâmetros) expõem os campos que os services de T-09/T-10
 * consomem, e a combo de via tem exatamente HospitalizationOrder::ROUTES.
 *
 * O Adianti roda num processo PHP separado (padrão de
 * AppointmentFormPostIntegrationTest: init.php define _t() global).
 */
final class HospitalizationClinicalFormsIntegrationTest
{
    /**
     * @param array<string, mixed> $param
     * @return array{fields: list<string>, route_items: list<string>|null}
     */
    private function inspect(string $class, array $param): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'if (!class_exists(' . var_export($class, true) . ')) { echo json_encode(["missing" => true]); exit; }'
            . '$page = new ' . $class . '(' . var_export($param, true) . ');'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$route = $form->getField("route");'
            . 'echo json_encode(['
            . '"fields" => array_values(array_map("strval", array_keys($form->getFields()))),'
            . '"route_items" => $route instanceof TCombo ? array_values(array_map("strval", array_keys($route->getItems()))) : null,'
            . ']);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), "{$class} subprocess output: " . (string) $out);
        Assert::true(!isset($decoded['missing']), "{$class} must exist");

        return $decoded;
    }

    /** @param list<string> $fields */
    private function assertHasFields(string $class, array $expected, array $fields): void
    {
        foreach ($expected as $name) {
            Assert::true(in_array($name, $fields, true), "{$class} must have field '{$name}' (got: " . implode(', ', $fields) . ')');
        }
    }

    public function testOrderFormHasPrescriptionFields(): void
    {
        $result = $this->inspect('HospitalizationOrderForm', ['hospitalization_id' => 1]);

        $this->assertHasFields('HospitalizationOrderForm', [
            'order_type',
            'description_text',
            'product_id',
            'quantity_per_administration',
            'dose_text',
            'route',
            'frequency_hours',
            'starts_at',
            'ends_at',
        ], $result['fields']);
    }

    public function testRouteComboHasExactlyTheSevenDomainRoutes(): void
    {
        $result = $this->inspect('HospitalizationOrderForm', ['hospitalization_id' => 1]);

        Assert::true(is_array($result['route_items']), 'route must be a TCombo');
        Assert::same(7, count(HospitalizationOrder::ROUTES));
        Assert::same(HospitalizationOrder::ROUTES, $result['route_items']);
    }

    /**
     * Correção 1: toda ação de autorização dos 3 forms (ACTION_*) segue
     * AuthorizationRequest::ACTION_PATTERN (Classe::método); senão o service
     * lança `Invalid authorization action format` e a tela abre vazia.
     */
    public function testAllAuthorizationActionsMatchTheRequestPattern(): void
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . '$out = [];'
            . 'foreach (["HospitalizationOrderForm", "HospitalizationAdministrationForm", "HospitalizationEventForm"] as $c) {'
            . ' if (!class_exists($c)) { $out[$c] = null; continue; }'
            . ' foreach ((new ReflectionClass($c))->getConstants() as $k => $v) {'
            . '  if (str_starts_with($k, "ACTION_")) { $out[$c][$k] = $v; } } }'
            . 'echo json_encode($out);';

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $decoded = json_decode((string) $out, true);

        Assert::true(is_array($decoded), 'subprocess output: ' . (string) $out);

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

    /** @return array<string, mixed> */
    private function runPhp(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";' . $body;
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $start = strrpos((string) $out, "\n{\"");
        $json = $start === false ? (string) $out : substr((string) $out, $start + 1);
        $decoded = json_decode($json, true);

        Assert::true(is_array($decoded), 'subprocess output: ' . (string) $out);

        return $decoded;
    }

    /**
     * Correção 2 (gate T-20): campo obrigatório vazio mostra o rótulo em
     * pt (não o nome técnico) e o formulário volta com o que foi digitado.
     */
    public function testMissingRequiredFieldShowsLabelAndKeepsTypedData(): void
    {
        $result = $this->runPhp(
            '$page = new HospitalizationOrderForm(["hospitalization_id" => 1]);'
            . 'ob_start();'
            . '$page->onSave(["hospitalization_id" => "1", "order_type" => "feeding", "description_text" => "",'
            . ' "dose_text" => "200 ml", "route" => "", "frequency_hours" => "8",'
            . ' "starts_at" => "05/10/2026 10:00", "ends_at" => ""]);'
            . '$msg = ob_get_clean();'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . 'echo "\n" . json_encode(["msg" => $msg, "label" => _t("Description"),'
            . ' "order_type" => (string) $form->getField("order_type")->getValue(),'
            . ' "dose_text" => (string) $form->getField("dose_text")->getValue(),'
            . ' "frequency_hours" => (string) $form->getField("frequency_hours")->getValue()]);'
        );

        Assert::true(!str_contains($result['msg'], 'description_text'), 'error must not expose the technical field name: ' . $result['msg']);
        Assert::true(str_contains($result['msg'], (string) $result['label']), 'error must name the field label: ' . $result['msg']);
        Assert::same('feeding', $result['order_type'], 'order_type must be kept after a validation error');
        Assert::same('200 ml', $result['dose_text'], 'dose_text must be kept after a validation error');
        Assert::same('8', $result['frequency_hours'], 'frequency_hours must be kept after a validation error');
    }

    /** Correção 2: obrigatórios marcados na UI e ações com alvo de toque. */
    public function testRequiredFieldsAreMarkedAndActionsUseTouchTarget(): void
    {
        $result = $this->runPhp(
            '$page = new HospitalizationOrderForm(["hospitalization_id" => 1]);'
            . '$form = (new ReflectionProperty($page, "form"))->getValue($page);'
            . '$required = [];'
            . 'foreach (["order_type", "description_text", "route", "frequency_hours", "starts_at", "ends_at"] as $n) {'
            . ' $required[$n] = (string) $form->getField($n)->getProperty("required"); }'
            . '$classes = [];'
            . 'foreach ($form->getActions() as $b) { $classes[] = (string) $b->getProperty("class"); }'
            . 'echo "\n" . json_encode(["required" => $required, "classes" => $classes]);'
        );

        foreach ($result['required'] as $name => $flag) {
            Assert::true($flag !== '', "{$name} must be marked as required");
        }

        Assert::true($result['classes'] !== [], 'order form must have actions');

        foreach ($result['classes'] as $class) {
            Assert::true(str_contains($class, 'cv-touch-target'), "action button must use cv-touch-target (got '{$class}')");
        }
    }

    public function testEventFormWithVitalsTypeHasVitalSignFields(): void
    {
        $result = $this->inspect('HospitalizationEventForm', ['hospitalization_id' => 1, 'type' => 'vitals']);

        $this->assertHasFields('HospitalizationEventForm', [
            'temperature_c',
            'heart_rate_bpm',
            'respiratory_rate_rpm',
            'weight_kg',
            'pain_score',
        ], $result['fields']);
    }
}

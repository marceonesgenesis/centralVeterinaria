<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

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

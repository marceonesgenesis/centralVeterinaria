<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-08 (F1/S4): o carrinho do PDV usa o preço de venda do produto (nunca o
 * custo), recusa produto sem preço de venda e o SaleService recebe os
 * repositórios de tutor, paciente e atendimento. Lê o fonte: o SaleForm
 * depende do Adianti e do banco, que o teste unitário não sobe.
 */
final class SaleFormPricingTest
{
    public function testCartDoesNotUseTheUnitCost(): void
    {
        Assert::false(str_contains($this->source(), 'unitCostCents()'), 'o carrinho ainda usa unitCostCents()');
    }

    public function testCartUsesTheSalePrice(): void
    {
        Assert::stringContains("'unitPriceCents' => \$product->requireSalePriceCents()", $this->source());
    }

    public function testProductWithoutSalePriceIsRefused(): void
    {
        $source = $this->source();

        Assert::stringContains('ProductWithoutSalePriceException', $source);
        Assert::true(
            (bool) preg_match('/catch\s*\(\s*\\\\?(?:CentralVet\\\\Domain\\\\Exception\\\\)?ProductWithoutSalePriceException\s+\$\w+\s*\)/', $source),
            'ProductWithoutSalePriceException não é tratada em catch',
        );
    }

    public function testBuildSaleServiceWiresTheTenantRepositories(): void
    {
        $body = $this->buildSaleServiceBody();

        Assert::stringContains('TutorRepository($context, $connection)', $body);
        Assert::stringContains('PatientRepository($context, $connection)', $body);
        Assert::stringContains('EncounterRepository($context, $connection)', $body);
    }

    private function buildSaleServiceBody(): string
    {
        $source = $this->source();
        $start = strpos($source, 'function buildSaleService(');
        Assert::true($start !== false, 'buildSaleService() ausente');

        $end = strpos($source, "\n    }\n", (int) $start);
        Assert::true($end !== false, 'fim de buildSaleService() não encontrado');

        return substr($source, (int) $start, (int) $end - (int) $start);
    }

    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/control/clinic/SaleForm.php';
        Assert::true(is_file($path), "arquivo ausente: {$path}");

        return (string) file_get_contents($path);
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

/**
 * T-16 (Etapa 2): sale history and traceable cancellation screens.
 * SaleCancelForm::onCancel writes (POST + X-CSRF-Token), onEdit only reads;
 * the form calls SaleCancellationService in the right panel; both programs
 * are in the RBAC seed, the cancel action is in the CSRF inventory and the
 * stock tab links SaleList. Reads the sources: the screens depend on
 * Adianti and the database, which the unit suite does not boot.
 */
final class SaleCancelFormWiringTest
{
    public function testCancelIsMutatingAndEditIsReadOnly(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('SaleCancelForm', 'onCancel'), 'onCancel must require POST + token');
        Assert::true(MutationRequestPolicy::isReadOnly('SaleCancelForm', 'onEdit'), 'onEdit only reads');
    }

    public function testFormCallsTheCancellationServiceInTheRightPanel(): void
    {
        $source = $this->source(dirname(__DIR__, 2) . '/app/control/clinic/SaleCancelForm.php');

        Assert::stringContains('SaleCancellationService', $source);
        Assert::stringContains("'adianti_right_panel'", $source);
    }

    public function testSeedRegistersBothPrograms(): void
    {
        $seed = $this->source(dirname(__DIR__, 2) . '/app/database/seeds/initial-application-programs.sql');

        Assert::stringContains("'SaleCancelForm'", $seed);
        Assert::stringContains("'SaleList'", $seed);
    }

    public function testCsrfInventoryListsTheCancelAction(): void
    {
        Assert::stringContains(
            'SaleCancelForm::onCancel',
            $this->source(dirname(__DIR__, 3) . '/docs/security/csrf-inventario.md'),
        );
    }

    public function testStockTabsLinkTheSaleHistory(): void
    {
        Assert::stringContains(
            'index.php?class=SaleList',
            $this->source(dirname(__DIR__, 2) . '/app/lib/widget/CvNav.php'),
        );
    }

    private function source(string $path): string
    {
        $source = is_file($path) ? file_get_contents($path) : false;

        return $source === false ? '' : $source;
    }
}

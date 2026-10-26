<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\MutationRequestPolicy;
use CentralVet\Tests\Support\Assert;

/**
 * T-14 (Etapa 5): cancelamento da assinatura pelo app. A
 * SubscriptionStatusView mostra o cancelamento agendado e o botão
 * "Cancelar assinatura", que abre SubscriptionCancelForm; o formulário
 * mostra a política (fim do período, retenção de PilotSettings, links de
 * exportação e termos) e confirma por onConfirm (POST + X-CSRF-Token), que
 * chama SubscriptionCancellationService::request. Lê os fontes: as telas
 * dependem do Adianti e do banco, que a suíte unitária não sobe.
 */
final class SubscriptionCancelFormWiringTest
{
    public function testConfirmIsMutating(): void
    {
        Assert::false(MutationRequestPolicy::isReadOnly('SubscriptionCancelForm', 'onConfirm'), 'onConfirm must require POST + token');
    }

    public function testFormConfirmsThroughTheCancellationService(): void
    {
        $source = $this->source('SubscriptionCancelForm.php');

        Assert::stringContains('public function onConfirm', $source);
        Assert::stringContains('SubscriptionCancellationService', $source);
        Assert::stringContains('->request(', $source);
        Assert::stringContains("_t('Confirm cancellation')", $source);
        Assert::stringContains("_t('Subscription cancellation requested')", $source);
        Assert::stringContains("'SubscriptionStatusView'", $source, 'sucesso volta à tela da assinatura');
        Assert::stringContains('CvFormat::userError(', $source, 'erro de domínio em pt-BR por UserMessage');
    }

    public function testFormShowsThePolicyWithRetentionAndLinks(): void
    {
        $source = $this->source('SubscriptionCancelForm.php');

        Assert::stringContains("_t('Cancellation policy')", $source);
        Assert::stringContains("_t('Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted'", $source);
        Assert::stringContains('retentionDaysAfterCancel()', $source);
        Assert::stringContains('index.php?class=ClinicDataExportView', $source);
        Assert::stringContains('legal.php?doc=termos', $source);
    }

    public function testFormAndViewKeepTouchTargetsAndLeftAlignedLabels(): void
    {
        foreach (['SubscriptionCancelForm.php', 'SubscriptionStatusView.php'] as $file) {
            $source = $this->source($file);

            Assert::stringContains('cv-touch-target', $source, $file);
            Assert::true(preg_match('/text-align\s*:\s*(right|center)/i', $source) === 0, $file . ' sem text-align right/center');
        }
    }

    public function testViewShowsScheduleAndCancelButton(): void
    {
        $source = $this->source('SubscriptionStatusView.php');

        Assert::stringContains("_t('Cancel subscription')", $source);
        Assert::stringContains('SubscriptionCancelForm', $source);
        Assert::stringContains("_t('Cancellation scheduled for ^1'", $source);
        Assert::stringContains('SubscriptionStatusPresenter::canCancel(', $source);
        Assert::stringContains('SubscriptionStatusPresenter::cancelScheduledFor(', $source);
    }

    private function source(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/app/control/admin/' . $file;
        $source = is_file($path) ? file_get_contents($path) : false;

        return $source === false ? '' : $source;
    }
}

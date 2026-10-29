<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-11: PaymentForm renders a submission token, passes it to
 * PaymentService::register() and turns DuplicateSubmissionException into an
 * info message; PendingReceivableList shows the receivable's origin in a
 * left-aligned column. Reads the source: both screens depend on Adianti and
 * the database, which the unit suite does not boot.
 */
final class PaymentFormSubmissionTokenTest
{
    public function testFormRendersATokenPerRender(): void
    {
        $source = $this->source('PaymentForm.php');

        Assert::stringContains("new THidden('submission_token')", $source);
        Assert::stringContains('SubmissionToken::generate()', $source);
    }

    public function testSavePassesTheTokenToTheService(): void
    {
        Assert::stringContains(
            "submissionToken: (string) (\$param['submission_token'] ?? '')",
            $this->source('PaymentForm.php'),
        );
    }

    public function testDuplicateSubmissionIsShownAsInfoAndReloads(): void
    {
        $source = $this->source('PaymentForm.php');
        $catch = strstr($source, 'catch (\\CentralVet\\Domain\\Exception\\DuplicateSubmissionException $e)');

        Assert::true($catch !== false, 'onSave must catch DuplicateSubmissionException');

        $block = substr($catch, 0, (int) strpos($catch, 'catch (', 10));
        Assert::stringContains('TTransaction::rollback();', $block);
        Assert::stringContains("new TMessage('info', _t('This submission was already processed'));", $block);
        Assert::stringContains('$this->reloadSelf($receivableId);', $block);
    }

    public function testBothScreensWireTheSubmissionKeyRepository(): void
    {
        foreach (['PaymentForm.php', 'PendingReceivableList.php'] as $file) {
            Assert::stringContains(
                'new \\CentralVet\\Persistence\\SubmissionKeyRepository($context, $connection)',
                $this->source($file),
            );
        }
    }

    public function testPendingListShowsOriginLeftAligned(): void
    {
        $source = $this->source('PendingReceivableList.php');

        Assert::stringContains("new TDataGridColumn('origin', _t('Origin'), 'left')", $source);
        Assert::stringContains("_t('Sale #^1', \$sale_id)", $source);
        Assert::stringContains("_t('Encounter account #^1', (int) \$receivable->encounterAccountId())", $source);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/control/clinic/' . $file);
        Assert::true(is_string($source), "{$file} must be readable");

        return $source;
    }
}

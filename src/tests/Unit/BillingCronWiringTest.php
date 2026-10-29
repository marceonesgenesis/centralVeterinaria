<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * Etapa 4, T-13: fiação dos crons da cobrança, lida no fonte (os scripts de
 * bin/ fazem I/O real). O reconciliador grava o heartbeat
 * `billing-reconcile` só no caminho de sucesso (depois de `->run(`, fora do
 * `catch`, antes do último `exit(0)`); a retenção do payload é só CLI, usa
 * `flock` e grava o heartbeat `billing-event-retention`.
 */
final class BillingCronWiringTest
{
    public function testReconcilerRecordsTheHeartbeatOnlyOnTheSuccessPath(): void
    {
        $source = $this->source('bin/billing-reconcile.php');

        $run = strpos($source, '->run(');
        $catch = strpos($source, 'catch (Throwable $exception)');
        $heartbeat = strrpos($source, 'HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE');
        $lastExit = strrpos($source, 'exit(0);');

        Assert::true($run !== false && $catch !== false && $lastExit !== false, 'billing-reconcile.php mudou de forma');
        Assert::true($heartbeat !== false, 'billing-reconcile.php não grava o heartbeat JOB_BILLING_RECONCILE');
        Assert::true($run < $heartbeat && $heartbeat < $lastExit, 'heartbeat fora do trecho entre ->run( e o último exit(0)');

        $catchEnd = strpos($source, '}', strpos($source, 'exit(1);', $catch));
        Assert::true($catchEnd !== false && $heartbeat > $catchEnd, 'heartbeat dentro do catch');
        Assert::same(1, substr_count($source, 'JOB_BILLING_RECONCILE'), 'heartbeat gravado uma vez só');

        Assert::stringContains('new PdoHeartbeatRepository($pdo)', $source);
        Assert::stringContains('->record(HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE, new DateTimeImmutable(), ', $source);
        Assert::stringContains("error_log('billing-reconcile heartbeat_failed=' . ", $source);
    }

    public function testRetentionBinIsCliOnlyLockedAndRecordsItsHeartbeat(): void
    {
        $source = $this->source('bin/billing-event-retention.php');

        Assert::stringContains("PHP_SAPI !== 'cli'", $source);
        Assert::stringContains('flock(', $source);
        Assert::stringContains("json_encode(['skipped' => 'locked'])", $source);
        Assert::stringContains('BillingEventPayloadRetention::forConnection(', $source);
        Assert::stringContains('HeartbeatRepositoryInterface::JOB_BILLING_EVENT_RETENTION', $source);
        Assert::true(!str_contains($source, 'DELETE'), 'a retenção nunca apaga linha');

        $guard = strpos($source, "PHP_SAPI !== 'cli'");
        $lock = strpos($source, 'flock(');
        Assert::true($guard < $lock, 'a guarda PHP_SAPI vem antes da trava');
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        Assert::true(is_file($path), $relative . ' ausente');

        return (string) file_get_contents($path);
    }
}

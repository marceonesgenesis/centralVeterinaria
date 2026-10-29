<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tests\Support\Assert;

/**
 * T-12: o fonte de bin/ops-monitor.php (depende do init.php e do banco, que
 * o teste unitário não sobe) só roda por CLI, usa a trava de arquivo, manda
 * os alertas ao BILLING_OPERATOR_EMAIL, grava o heartbeat ops-monitor e sai
 * 0 sem alertas, 2 com alertas e 1 em exceção.
 */
final class OpsMonitorWiringTest
{
    public function testOnlyRunsFromTheCommandLine(): void
    {
        Assert::stringContains("if (PHP_SAPI !== 'cli')", $this->source());
    }

    public function testUsesAFileLock(): void
    {
        Assert::stringContains('flock($lockHandle, LOCK_EX | LOCK_NB)', $this->source());
    }

    public function testAlertsGoToTheBillingOperator(): void
    {
        $source = $this->source();

        Assert::stringContains("getenv('BILLING_OPERATOR_EMAIL')", $source);
        Assert::stringContains('new OperatorAlertNotifier(', $source);
        Assert::stringContains("'Central Vet Pro: alerta operacional ' . \$alert->key", $source);
    }

    public function testRecordsTheOpsMonitorHeartbeat(): void
    {
        Assert::stringContains('HeartbeatRepositoryInterface::JOB_OPS_MONITOR', $this->source());
    }

    public function testExitCodes(): void
    {
        $source = $this->source();

        Assert::stringContains('exit($alerts === [] ? 0 : 2);', $source);
        Assert::stringContains("'ops-monitor error=' . \$exception::class", $source);
        Assert::true(preg_match("/ops-monitor error=.*?exit\\(1\\);/s", $source) === 1, 'exceção deveria sair 1');
    }

    public function testStorageProbeUsesTenantOneAndTheDocumentSystemUser(): void
    {
        Assert::stringContains(
            "TenantContext::authenticated(1, (int) (getenv('DOCUMENT_SYSTEM_USER_ID') ?: 1))",
            $this->source(),
        );
    }

    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/bin/ops-monitor.php');
        Assert::true(is_string($source), 'bin/ops-monitor.php não encontrado');

        return $source;
    }
}

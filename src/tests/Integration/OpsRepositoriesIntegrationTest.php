<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use CentralVet\Persistence\PdoAlertStateRepository;
use CentralVet\Persistence\PdoHeartbeatRepository;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use DateTimeImmutable;

/**
 * PdoHeartbeatRepository and PdoAlertStateRepository (T-10) against
 * `ops_heartbeat` / `ops_alert_state` (migration 0019). Everything runs
 * inside the rolled-back test transaction.
 */
final class OpsRepositoriesIntegrationTest extends MysqlIntegrationTestCase
{
    private const ALERT_KEY = 'zz_t10_alert';

    public function setUp(): void
    {
        parent::setUp();

        foreach (['ops_heartbeat', 'ops_alert_state'] as $table) {
            $exists = (bool) $this->pdo->query("SHOW TABLES LIKE '" . $table . "'")->fetchColumn();
            Assert::true($exists, 'Migration 0019 (' . $table . ') must be applied to the test database');
        }
    }

    public function testRecordTwiceKeepsOneRowWithTheSecondTime(): void
    {
        $heartbeats = new PdoHeartbeatRepository($this->pdo);
        $job = HeartbeatRepositoryInterface::JOB_BACKUP;
        $first = new DateTimeImmutable('2026-10-25 03:00:00.123456');
        $second = new DateTimeImmutable('2026-10-26 03:00:05.654321');

        $heartbeats->record($job, $first, 'primeiro');
        $heartbeats->record($job, $second, 'segundo');

        $rows = $this->pdo->query("SELECT job, detail FROM ops_heartbeat WHERE job = 'backup'")->fetchAll(\PDO::FETCH_ASSOC);
        Assert::count(1, $rows, 'record is an upsert');
        Assert::same('segundo', $rows[0]['detail']);
        Assert::same($second->format('Y-m-d H:i:s.u'), $heartbeats->lastSuccess($job)?->format('Y-m-d H:i:s.u'));
    }

    public function testDetailIsCutAt190Characters(): void
    {
        $heartbeats = new PdoHeartbeatRepository($this->pdo);
        $heartbeats->record(HeartbeatRepositoryInterface::JOB_OPS_MONITOR, new DateTimeImmutable('2026-10-26 10:00:00'), str_repeat('é', 250));

        $detail = (string) $this->pdo->query("SELECT detail FROM ops_heartbeat WHERE job = 'ops-monitor'")->fetchColumn();
        Assert::same(190, mb_strlen($detail));
    }

    public function testLastSuccessOfAnUnknownJobIsNull(): void
    {
        Assert::null((new PdoHeartbeatRepository($this->pdo))->lastSuccess('zz-t10-never-ran'));
    }

    public function testJobConstants(): void
    {
        Assert::same('billing-reconcile', HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE);
        Assert::same('backup', HeartbeatRepositoryInterface::JOB_BACKUP);
        Assert::same('ops-monitor', HeartbeatRepositoryInterface::JOB_OPS_MONITOR);
        Assert::same('billing-event-retention', HeartbeatRepositoryInterface::JOB_BILLING_EVENT_RETENTION);
    }

    public function testMarkSentTwiceCountsTwoAndKeepsTheLastTime(): void
    {
        $alerts = new PdoAlertStateRepository($this->pdo);
        $first = new DateTimeImmutable('2026-10-26 10:00:00.000001');
        $second = new DateTimeImmutable('2026-10-26 17:00:00.000002');

        Assert::null($alerts->lastSentAt(self::ALERT_KEY));
        $alerts->markSent(self::ALERT_KEY, 'Primeiro', $first);
        $alerts->markSent(self::ALERT_KEY, 'Segundo', $second);

        $statement = $this->pdo->prepare('SELECT last_subject, sent_count FROM ops_alert_state WHERE alert_key = ?');
        $statement->execute([self::ALERT_KEY]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        Assert::count(1, $rows);
        Assert::same(2, (int) $rows[0]['sent_count']);
        Assert::same('Segundo', $rows[0]['last_subject']);
        Assert::same($second->format('Y-m-d H:i:s.u'), $alerts->lastSentAt(self::ALERT_KEY)?->format('Y-m-d H:i:s.u'));
    }
}

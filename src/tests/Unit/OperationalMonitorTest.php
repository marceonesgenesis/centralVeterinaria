<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use CentralVet\Operations\Contract\OperationalCountsInterface;
use CentralVet\Operations\OperationalAlert;
use CentralVet\Operations\OperationalMonitor;
use CentralVet\Storage\Exception\StorageException;
use CentralVet\Storage\StorageInterface;
use CentralVet\Storage\StoredObjectMetadata;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeHeartbeatRepository;
use CentralVet\Tests\Support\FakeStorage;
use DateTimeImmutable;

/**
 * T-12: regras do monitor operacional com contagens falsas, heartbeats em
 * memória e storage de teste. O SQL das contagens é coberto por
 * PdoOperationalCountsIntegrationTest.
 */
final class OperationalMonitorTest
{
    private const NOW = '2026-10-26 12:00:00';

    public function testMissingReconcileHeartbeatRaisesAlert(): void
    {
        $heartbeats = $this->heartbeats(reconcileAgo: null);

        $keys = $this->keys($this->monitor([], $heartbeats, new FakeStorage())->check($this->now()));

        Assert::true(in_array('billing_reconcile_stale', $keys, true), 'sem heartbeat do reconciliador deveria alertar');
    }

    public function testRecentReconcileHeartbeatDoesNotAlert(): void
    {
        $heartbeats = $this->heartbeats(reconcileAgo: 600);

        $keys = $this->keys($this->monitor([], $heartbeats, new FakeStorage())->check($this->now()));

        Assert::false(in_array('billing_reconcile_stale', $keys, true), 'heartbeat de 10 min não deveria alertar');
    }

    public function testBackupHeartbeatOlderThan26HoursRaisesAlert(): void
    {
        $heartbeats = $this->heartbeats(backupAgo: 30 * 3600);

        $keys = $this->keys($this->monitor([], $heartbeats, new FakeStorage())->check($this->now()));

        Assert::true(in_array('backup_stale', $keys, true), 'backup de 30 h deveria alertar');
    }

    public function testStalledDocumentQueueRaisesAlertWithCount(): void
    {
        $alerts = $this->monitor(['document_queue_stalled' => 3], $this->heartbeats(), new FakeStorage())->check($this->now());

        $match = array_values(array_filter($alerts, static fn (OperationalAlert $a): bool => $a->key === 'document_queue_stalled'));
        Assert::count(1, $match);
        Assert::same(3, $match[0]->count);
        Assert::stringContains('3', $match[0]->summary);
    }

    public function testStorageThatThrowsOnPutRaisesAlert(): void
    {
        $storage = new class () implements StorageInterface {
            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                throw new StorageException('down');
            }

            public function get(string $key): string
            {
                throw new StorageException('down');
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void
            {
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return '';
            }
        };

        $keys = $this->keys($this->monitor([], $this->heartbeats(), $storage)->check($this->now()));

        Assert::same(['storage_unavailable'], $keys);
    }

    public function testStorageProbeIsCleanedUp(): void
    {
        $storage = new FakeStorage();

        $this->monitor([], $this->heartbeats(), $storage)->check($this->now());

        Assert::false($storage->exists(OperationalMonitor::PROBE_KEY), 'o probe deveria ser apagado');
    }

    public function testNullStorageIsNotChecked(): void
    {
        Assert::same([], $this->monitor([], $this->heartbeats(), null)->check($this->now()));
    }

    public function testAllHealthyGivesEmptyList(): void
    {
        Assert::same([], $this->monitor([], $this->heartbeats(), new FakeStorage())->check($this->now()));
    }

    public function testEveryPositiveCountBecomesAnAlert(): void
    {
        $counts = [
            'billing_event_failed' => 1,
            'billing_order_stuck' => 2,
            'message_failed' => 0,
            'message_queue_stalled' => 4,
            'document_failed' => 5,
            'document_queue_stalled' => 0,
        ];

        $keys = $this->keys($this->monitor($counts, $this->heartbeats(), new FakeStorage())->check($this->now()));
        sort($keys);

        Assert::same(['billing_event_failed', 'billing_order_stuck', 'document_failed', 'message_queue_stalled'], $keys);
    }

    public function testNoSummaryContainsAnEmailAddress(): void
    {
        $counts = array_fill_keys(
            ['billing_event_failed', 'billing_order_stuck', 'message_failed', 'message_queue_stalled', 'document_failed', 'document_queue_stalled'],
            7,
        );
        $storage = new class () implements StorageInterface {
            public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): StoredObjectMetadata
            {
                throw new StorageException('falha em ops@example.test');
            }

            public function get(string $key): string
            {
                return '';
            }

            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): void
            {
            }

            public function presignedUrl(string $key, int $ttlSeconds = 300): string
            {
                return '';
            }
        };

        $alerts = $this->monitor($counts, $this->heartbeats(reconcileAgo: 9000, backupAgo: null), $storage)->check($this->now());

        Assert::count(9, $alerts);
        foreach ($alerts as $alert) {
            Assert::false(str_contains($alert->summary, '@'), 'summary com @: ' . $alert->summary);
            Assert::stringContains($alert->key, $alert->summary);
        }
    }

    public function testThresholdConstants(): void
    {
        Assert::same(2700, OperationalMonitor::RECONCILE_MAX_AGE_SECONDS);
        Assert::same(93600, OperationalMonitor::BACKUP_MAX_AGE_SECONDS);
        Assert::same('ops-probe/heartbeat.txt', OperationalMonitor::PROBE_KEY);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function heartbeats(?int $reconcileAgo = 300, ?int $backupAgo = 3600): FakeHeartbeatRepository
    {
        $heartbeats = new FakeHeartbeatRepository();
        if ($reconcileAgo !== null) {
            $heartbeats->record(HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE, $this->now()->modify('-' . $reconcileAgo . ' seconds'), '');
        }
        if ($backupAgo !== null) {
            $heartbeats->record(HeartbeatRepositoryInterface::JOB_BACKUP, $this->now()->modify('-' . $backupAgo . ' seconds'), '');
        }

        return $heartbeats;
    }

    /**
     * @param array<string, int> $values
     */
    private function monitor(array $values, HeartbeatRepositoryInterface $heartbeats, ?StorageInterface $storage): OperationalMonitor
    {
        $counts = new class ($values) implements OperationalCountsInterface {
            /** @param array<string, int> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function counts(DateTimeImmutable $now): array
            {
                return $this->values + [
                    'billing_event_failed' => 0,
                    'billing_order_stuck' => 0,
                    'message_failed' => 0,
                    'message_queue_stalled' => 0,
                    'document_failed' => 0,
                    'document_queue_stalled' => 0,
                ];
            }
        };

        return new OperationalMonitor($counts, $heartbeats, $storage);
    }

    /**
     * @param list<OperationalAlert> $alerts
     * @return list<string>
     */
    private function keys(array $alerts): array
    {
        return array_map(static fn (OperationalAlert $alert): string => $alert->key, $alerts);
    }
}

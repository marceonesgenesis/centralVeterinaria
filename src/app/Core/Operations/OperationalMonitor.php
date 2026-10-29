<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use CentralVet\Operations\Contract\OperationalCountsInterface;
use CentralVet\Storage\StorageInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Regras do monitor operacional (T-12): heartbeats do reconciliador e do
 * backup, contagens de falhas/filas paradas e a disponibilidade do storage.
 * O resumo leva só chave, contagem e idade em minutos (sem dado de cliente
 * nem mensagem de exceção).
 */
final class OperationalMonitor
{
    public const RECONCILE_MAX_AGE_SECONDS = 2700;

    public const BACKUP_MAX_AGE_SECONDS = 93600;

    public const PROBE_KEY = 'ops-probe/heartbeat.txt';

    private const COUNT_LABELS = [
        'billing_event_failed' => 'eventos de cobrança com falha ou parados há mais de 60 min',
        'billing_order_stuck' => 'pedidos pagos ou com falha de provisionamento parados há mais de 60 min',
        'message_failed' => 'mensagens com falha nas últimas 24 h',
        'message_queue_stalled' => 'mensagens na fila há mais de 30 min',
        'document_failed' => 'documentos com falha nas últimas 24 h',
        'document_queue_stalled' => 'documentos na fila há mais de 30 min',
    ];

    public function __construct(
        private readonly OperationalCountsInterface $counts,
        private readonly HeartbeatRepositoryInterface $heartbeats,
        private readonly ?StorageInterface $storage,
    ) {
    }

    /**
     * @return list<OperationalAlert>
     */
    public function check(DateTimeImmutable $now): array
    {
        $alerts = [];

        foreach ([
            'billing_reconcile_stale' => [HeartbeatRepositoryInterface::JOB_BILLING_RECONCILE, self::RECONCILE_MAX_AGE_SECONDS],
            'backup_stale' => [HeartbeatRepositoryInterface::JOB_BACKUP, self::BACKUP_MAX_AGE_SECONDS],
        ] as $key => [$job, $maxAge]) {
            $alert = $this->heartbeatAlert($key, $job, $maxAge, $now);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        foreach ($this->counts->counts($now) as $key => $count) {
            $count = (int) $count;
            if ($count > 0) {
                $label = self::COUNT_LABELS[$key] ?? 'ocorrências';
                $alerts[] = new OperationalAlert($key, $count, sprintf('%s: %d %s.', $key, $count, $label));
            }
        }

        if ($this->storage !== null && !$this->storageAvailable($this->storage, $now)) {
            $alerts[] = new OperationalAlert(
                'storage_unavailable',
                1,
                'storage_unavailable: 1 falha ao gravar, ler ou apagar o arquivo de teste do storage de documentos.',
            );
        }

        return $alerts;
    }

    private function heartbeatAlert(string $key, string $job, int $maxAge, DateTimeImmutable $now): ?OperationalAlert
    {
        $last = $this->heartbeats->lastSuccess($job);
        if ($last === null) {
            return new OperationalAlert($key, 1, sprintf('%s: 1 job %s sem execução bem-sucedida registrada.', $key, $job));
        }

        $age = $now->getTimestamp() - $last->getTimestamp();
        if ($age <= $maxAge) {
            return null;
        }

        return new OperationalAlert(
            $key,
            1,
            sprintf('%s: 1 job %s com último sucesso há %d min (limite %d min).', $key, $job, intdiv($age, 60), intdiv($maxAge, 60)),
        );
    }

    private function storageAvailable(StorageInterface $storage, DateTimeImmutable $now): bool
    {
        $contents = 'ops-monitor ' . $now->format(DATE_ATOM);
        try {
            $storage->put(self::PROBE_KEY, $contents, 'text/plain');
            $read = $storage->get(self::PROBE_KEY);
            $storage->delete(self::PROBE_KEY);
        } catch (Throwable) {
            return false;
        }

        return $read === $contents;
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Operations\Contract\OperationalCountsInterface;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Contagens globais do monitor operacional (T-12), uma `SELECT COUNT(*)` por
 * chave. Job de manutenção da plataforma: sem TenantContext. Os limites de
 * tempo são calculados no PHP e enviados no fuso do PHP (`Y-m-d H:i:s.u`),
 * como PdoHeartbeatRepository.
 */
final class PdoOperationalCounts implements OperationalCountsInterface
{
    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    private const QUERIES = [
        'billing_event_failed' => [
            "SELECT COUNT(*) FROM billing_event WHERE status = 'failed' OR (status = 'received' AND received_at < :cutoff)",
            3600,
        ],
        'billing_order_stuck' => [
            "SELECT COUNT(*) FROM billing_order WHERE status IN ('paid', 'provisioning_failed') AND updated_at < :cutoff",
            3600,
        ],
        'message_failed' => [
            "SELECT COUNT(*) FROM communication_message WHERE status = 'failed' AND failed_at >= :cutoff",
            86400,
        ],
        'message_queue_stalled' => [
            "SELECT COUNT(*) FROM communication_message WHERE status = 'queued' AND created_at < :cutoff",
            1800,
        ],
        'document_failed' => [
            "SELECT COUNT(*) FROM generated_document WHERE status = 'failed' AND failed_at >= :cutoff",
            86400,
        ],
        'document_queue_stalled' => [
            "SELECT COUNT(*) FROM generated_document WHERE status = 'queued' AND created_at < :cutoff",
            1800,
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function counts(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $counts = [];
        foreach (self::QUERIES as $key => [$sql, $seconds]) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['cutoff' => $local->modify('-' . $seconds . ' seconds')->format(self::TIME_FORMAT)]);
            $counts[$key] = (int) $statement->fetchColumn();
        }

        return $counts;
    }
}

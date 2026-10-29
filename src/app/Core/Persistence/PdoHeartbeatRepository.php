<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Operations\Contract\HeartbeatRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO-backed `ops_heartbeat` (migration 0019). No TenantContext: jobs are
 * platform-wide. Times are written in the PHP time zone as
 * `Y-m-d H:i:s.u` (both columns explicitly; the table has no ON UPDATE on
 * last_success_at) and read back in the same zone.
 */
final class PdoHeartbeatRepository implements HeartbeatRepositoryInterface
{
    private const DETAIL_LENGTH = 190;

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $job, DateTimeImmutable $at, string $detail): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ops_heartbeat (job, last_success_at, detail) VALUES (:job, :at, :detail) '
            . 'ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at), detail = VALUES(detail)'
        );
        $statement->execute([
            'job' => $job,
            'at' => $this->local($at),
            'detail' => mb_substr($detail, 0, self::DETAIL_LENGTH),
        ]);
    }

    public function lastSuccess(string $job): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT last_success_at FROM ops_heartbeat WHERE job = :job');
        $statement->execute(['job' => $job]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function local(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(self::TIME_FORMAT);
    }
}

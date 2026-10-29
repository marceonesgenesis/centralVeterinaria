<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Operations\Contract\AlertStateRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * PDO-backed `ops_alert_state` (migration 0019). markSent() is an upsert
 * that moves last_sent_at, replaces the subject and adds 1 to sent_count.
 * Times are in the PHP time zone, formatted `Y-m-d H:i:s.u`.
 */
final class PdoAlertStateRepository implements AlertStateRepositoryInterface
{
    private const SUBJECT_LENGTH = 190;

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lastSentAt(string $alertKey): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT last_sent_at FROM ops_alert_state WHERE alert_key = :alert_key');
        $statement->execute(['alert_key' => $alertKey]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : new DateTimeImmutable((string) $value);
    }

    public function markSent(string $alertKey, string $subject, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ops_alert_state (alert_key, last_sent_at, last_subject, sent_count) '
            . 'VALUES (:alert_key, :at, :subject, 1) '
            . 'ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at), '
            . 'last_subject = VALUES(last_subject), sent_count = sent_count + 1'
        );
        $statement->execute([
            'alert_key' => $alertKey,
            'at' => $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(self::TIME_FORMAT),
            'subject' => mb_substr($subject, 0, self::SUBJECT_LENGTH),
        ]);
    }
}

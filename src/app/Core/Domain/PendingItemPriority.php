<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Priority rules of the Central de Pendências (Fase 7A, PRD §8.23).
 *
 * Single source of truth: queries only fill `dueAt`, services and screens
 * only read the result of {@see self::classify()} and {@see self::rank()}.
 *
 * - `hospitalization_administration` is always `urgent`;
 * - `document_failed` is always `high` (Fase 7B);
 * - otherwise `$now > $dueAt` is `high` (overdue);
 * - `$dueAt <= $now + 24 h` is `normal`;
 * - anything later is `low`.
 */
final class PendingItemPriority
{
    public const URGENT = 'urgent';
    public const HIGH = 'high';
    public const NORMAL = 'normal';
    public const LOW = 'low';

    public const NORMAL_WINDOW_HOURS = 24;

    private const RANKS = [
        self::URGENT => 0,
        self::HIGH => 1,
        self::NORMAL => 2,
        self::LOW => 3,
    ];

    private function __construct()
    {
    }

    public static function classify(string $type, DateTimeImmutable $dueAt, DateTimeImmutable $now): string
    {
        if ($type === PendingItem::TYPE_HOSPITALIZATION_ADMINISTRATION) {
            return self::URGENT;
        }

        if ($type === PendingItem::TYPE_DOCUMENT_FAILED) {
            return self::HIGH;
        }

        if ($now > $dueAt) {
            return self::HIGH;
        }

        if ($dueAt <= $now->modify('+' . self::NORMAL_WINDOW_HOURS . ' hours')) {
            return self::NORMAL;
        }

        return self::LOW;
    }

    /**
     * Sort key: urgent 0, high 1, normal 2, low 3.
     */
    public static function rank(string $priority): int
    {
        if (!array_key_exists($priority, self::RANKS)) {
            throw new InvalidArgumentException('Invalid pending item priority');
        }

        return self::RANKS[$priority];
    }
}

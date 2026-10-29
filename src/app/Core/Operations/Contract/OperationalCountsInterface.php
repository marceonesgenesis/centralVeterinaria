<?php

declare(strict_types=1);

namespace CentralVet\Operations\Contract;

use DateTimeImmutable;

/**
 * Contagens globais (sem tenant) que o monitor operacional (T-12) cobra.
 * Chaves: billing_event_failed, billing_order_stuck, message_failed,
 * message_queue_stalled, document_failed, document_queue_stalled; valores
 * inteiros >= 0.
 */
interface OperationalCountsInterface
{
    /**
     * @return array<string, int>
     */
    public function counts(DateTimeImmutable $now): array;
}

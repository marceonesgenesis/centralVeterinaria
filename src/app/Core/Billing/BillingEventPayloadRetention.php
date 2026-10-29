<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Retenção LGPD de `billing_event.payload` (Etapa 4, T-13), rodada por
 * `bin/billing-event-retention.php` a partir do cron da hospedagem.
 *
 * O payload guarda o JSON bruto do Stripe (com o e-mail do cliente) e não é
 * relido depois do processamento: passados RETENTION_DAYS de `received_at`,
 * os eventos `processed`/`ignored` têm o payload esvaziado (`''`), em lotes
 * de BATCH_SIZE por id, até um lote curto ou MAX_BATCHES (a próxima rodada
 * pega o resto). A linha nunca é apagada: `MAX(event_created)` é o cursor do
 * reconciliador e `event_id` deduplica a reentrega. `failed`/`received` não
 * são tocados (o alerta do monitor cobra a ação).
 */
final class BillingEventPayloadRetention
{
    public const RETENTION_DAYS = 30;
    public const BATCH_SIZE = 500;
    public const MAX_BATCHES = 200;

    private readonly Closure $clock;

    /** @param (Closure(): DateTimeImmutable)|null $clock */
    private function __construct(
        private readonly PDO $connection,
        ?Closure $clock,
        private readonly int $batchSize,
        private readonly int $maxBatches,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @throws InvalidArgumentException com lote ou máximo de lotes abaixo de 1
     */
    public static function forConnection(
        PDO $connection,
        ?Closure $clock = null,
        int $batchSize = self::BATCH_SIZE,
        int $maxBatches = self::MAX_BATCHES,
    ): self {
        if ($batchSize < 1 || $maxBatches < 1) {
            throw new InvalidArgumentException('Batch size and maximum batches must be at least 1');
        }

        return new self($connection, $clock, $batchSize, $maxBatches);
    }

    /**
     * @return array{purged: int, batches: int} `batches` conta os UPDATE que
     *         esvaziaram ao menos um payload.
     */
    public function runOnce(): array
    {
        /** @var DateTimeImmutable $now */
        $now = ($this->clock)();
        $cutoff = $now->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s.u');

        $statement = $this->connection->prepare(
            "UPDATE billing_event SET payload = '' WHERE status IN ('processed', 'ignored')"
            . " AND received_at < :cutoff AND payload <> '' ORDER BY id LIMIT " . $this->batchSize,
        );

        $purged = 0;
        $batches = 0;

        while ($batches < $this->maxBatches) {
            $statement->execute(['cutoff' => $cutoff]);
            $rows = $statement->rowCount();

            if ($rows === 0) {
                break;
            }

            $purged += $rows;
            ++$batches;

            if ($rows < $this->batchSize) {
                break;
            }
        }

        return ['purged' => $purged, 'batches' => $batches];
    }
}

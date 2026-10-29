<?php

declare(strict_types=1);

namespace CentralVet\Application;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * 90-day retention of the copilot conversations (Fase 8B, T-12), run by
 * `bin/ai-conversation-retention.php` from the hosting cron.
 *
 * Deletes, across every tenant (no tenant context: it is a maintenance job),
 * the conversations whose `updated_at` is older than RETENTION_DAYS, in
 * batches of BATCH_SIZE ordered by id, until a batch comes back short or
 * MAX_BATCHES is reached (the next run picks up the rest); both can be
 * injected through forConnection() (tests, manual runs). The messages
 * fall by the `ON DELETE CASCADE` of `ai_conversation_message`. The audit
 * — `mcp_tool_call` (no FK to the conversation) and `ai_interaction` — is
 * never touched.
 */
final class AiConversationRetention
{
    public const RETENTION_DAYS = 90;
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
     * @throws InvalidArgumentException when $batchSize or $maxBatches is below 1
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
     * @return array{deleted: int, batches: int} `batches` counts the DELETE
     *         statements that removed at least one row.
     */
    public function runOnce(): array
    {
        /** @var DateTimeImmutable $now */
        $now = ($this->clock)();
        $cutoff = $now->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s.u');

        $statement = $this->connection->prepare(
            'DELETE FROM ai_conversation WHERE updated_at < :cutoff ORDER BY id LIMIT ' . $this->batchSize,
        );

        $deleted = 0;
        $batches = 0;

        while ($batches < $this->maxBatches) {
            $statement->execute(['cutoff' => $cutoff]);
            $rows = $statement->rowCount();

            if ($rows === 0) {
                break;
            }

            $deleted += $rows;
            ++$batches;

            if ($rows < $this->batchSize) {
                break;
            }
        }

        return ['deleted' => $deleted, 'batches' => $batches];
    }
}

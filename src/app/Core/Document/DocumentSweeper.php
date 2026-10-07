<?php

declare(strict_types=1);

namespace CentralVet\Document;

use CentralVet\Application\DocumentJobPublisher;
use CentralVet\Domain\Contract\GeneratedDocumentRepositoryInterface;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Persistence\GeneratedDocumentRepository;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Sweeper of the documents left `queued` (Fase 7B, T-14), shared by the
 * worker tick and `bin/document-sweep.php`.
 *
 * For each active tenant (`$activeTenants(): list<int|array{id: int}>`) it
 * builds the repository through `$repositoryFactory(int $tenantId)` and
 * re-publishes the `document.generate` job of up to 100 documents queued
 * (or claimed) more than 10 minutes ago — a publish lost after the commit
 * or a worker that died mid-job. The generation is idempotent, so a double
 * publish only yields a `skipped`. A failure in one tenant is logged
 * (tenant id and exception class only), counted in `errors`, and the other
 * tenants still run.
 */
final class DocumentSweeper
{
    private const STALE_AFTER = '-10 minutes';
    private const STALE_LIMIT = 100;

    private readonly Closure $clock;

    /**
     * @param Closure(): list<int|array{id: int}> $activeTenants
     * @param Closure(int): GeneratedDocumentRepositoryInterface $repositoryFactory
     */
    public function __construct(
        private readonly Closure $activeTenants,
        private readonly Closure $repositoryFactory,
        private readonly DocumentJobPublisher $publisher,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Production wiring on one PDO (opened per tick / per command run): the
     * active tenants come from `tenant`, and each tenant runs as the system
     * actor `$systemUserId`.
     */
    public static function forConnection(
        PDO $connection,
        DocumentJobPublisher $publisher,
        LoggerInterface $logger,
        int $systemUserId,
    ): self {
        return new self(
            static function () use ($connection): array {
                $rows = $connection->query("SELECT id FROM tenant WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

                return array_map('intval', $rows);
            },
            static fn (int $tenantId): GeneratedDocumentRepositoryInterface => new GeneratedDocumentRepository(
                TenantContext::authenticated($tenantId, $systemUserId),
                $connection,
            ),
            $publisher,
            $logger,
        );
    }

    /** @return array{tenants: int, republished: int, errors: int} */
    public function runOnce(): array
    {
        $totals = ['tenants' => 0, 'republished' => 0, 'errors' => 0];
        $olderThan = (($this->clock)())->modify(self::STALE_AFTER);

        foreach (($this->activeTenants)() as $tenant) {
            $tenantId = is_array($tenant) ? (int) $tenant['id'] : (int) $tenant;
            $totals['tenants']++;

            try {
                /** @var GeneratedDocumentRepositoryInterface $documents */
                $documents = ($this->repositoryFactory)($tenantId);

                foreach ($documents->listStaleQueuedIds($olderThan, self::STALE_LIMIT) as $documentId) {
                    $this->publisher->publish($tenantId, (int) $documentId);
                    $totals['republished']++;
                }
            } catch (Throwable $exception) {
                $totals['errors']++;
                $this->logger->error('document.sweep.tenant_failed', [
                    'tenant_id' => $tenantId,
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->logger->info('document.sweep.completed', $totals);

        return $totals;
    }
}

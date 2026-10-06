<?php

declare(strict_types=1);

namespace CentralVet\Communication;

use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Application\ReminderGenerationService;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Persistence\CommunicationPreferenceRepository;
use CentralVet\Persistence\MessageTemplateRepository;
use CentralVet\Persistence\OutboundMessageRepository;
use CentralVet\Persistence\ReminderSourceQuery;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Scheduler of the automatic reminders (Fase 7A, T-15), shared by the worker
 * tick and `bin/communication-scheduler.php`.
 *
 * For each active tenant (`$activeTenants(): list<array{id: int, timezone: string}>`)
 * it builds the services through `$servicesFactory(int $tenantId)`, which
 * returns `['reminders' => ReminderGenerationService, 'messages' =>
 * OutboundMessageRepositoryInterface]`, generates the reminders in the
 * tenant time zone and publishes the e-mails just created plus the e-mails
 * left `queued` for more than 10 minutes (lost publish). A failure in one
 * tenant stage (services, generate, sweep, publish) is logged (tenant id,
 * stage and exception class only) and counted in
 * `errors`; the other tenants still run.
 */
final class CommunicationScheduler
{
    private const STALE_AFTER = '-10 minutes';
    private const STALE_LIMIT = 100;

    private readonly Closure $clock;

    /**
     * @param Closure(): list<array{id: int, timezone: string}> $activeTenants
     * @param Closure(int): array{reminders: ReminderGenerationService, messages: OutboundMessageRepositoryInterface} $servicesFactory
     */
    public function __construct(
        private readonly Closure $activeTenants,
        private readonly Closure $servicesFactory,
        private readonly MessageQueuePublisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $receivableReminderDays,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Production wiring on one PDO (opened per tick / per command run): the
     * active tenants come from `tenant`, and each tenant runs as the system
     * actor `$systemUserId` on the PDO repositories.
     */
    public static function forConnection(
        PDO $connection,
        MessageQueuePublisher $publisher,
        LoggerInterface $logger,
        int $receivableReminderDays,
        int $systemUserId,
    ): self {
        return new self(
            static function () use ($connection): array {
                $rows = $connection->query("SELECT id, timezone FROM tenant WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

                return array_map(
                    static fn (array $row): array => ['id' => (int) $row['id'], 'timezone' => (string) $row['timezone']],
                    $rows,
                );
            },
            static function (int $tenantId) use ($connection, $systemUserId): array {
                $context = TenantContext::authenticated($tenantId, $systemUserId);
                $messages = new OutboundMessageRepository($context, $connection);

                return [
                    'reminders' => new ReminderGenerationService(
                        new ReminderSourceQuery($context, $connection),
                        new CommunicationPreferenceRepository($context, $connection),
                        new MessageTemplateRepository($context, $connection),
                        $messages,
                        $context,
                    ),
                    'messages' => $messages,
                ];
            },
            $publisher,
            $logger,
            $receivableReminderDays,
        );
    }

    /**
     * @return array{tenants: int, created: int, duplicates: int, skipped_no_consent: int, skipped_no_contact: int, skipped_opted_out: int, published: int, errors: int}
     */
    public function runOnce(): array
    {
        $totals = [
            'tenants' => 0,
            'created' => 0,
            'duplicates' => 0,
            'skipped_no_consent' => 0,
            'skipped_no_contact' => 0,
            'skipped_opted_out' => 0,
            'published' => 0,
            'errors' => 0,
        ];

        foreach (($this->activeTenants)() as $tenant) {
            $tenantId = (int) $tenant['id'];
            $totals['tenants']++;

            try {
                $services = ($this->servicesFactory)($tenantId);
                /** @var ReminderGenerationService $reminders */
                $reminders = $services['reminders'];
                /** @var OutboundMessageRepositoryInterface $messages */
                $messages = $services['messages'];
            } catch (Throwable $exception) {
                $this->recordFailure($totals, $tenantId, 'services', $exception);
                continue;
            }

            // Generation, sweep and publish are isolated: a candidate that
            // makes generate() throw on every run must not keep the
            // tenant's queued e-mails from being swept and published.
            $emailIds = [];

            try {
                $summary = $reminders->generate(new DateTimeZone((string) $tenant['timezone']), $this->receivableReminderDays);

                foreach ($summary->toArray() as $key => $count) {
                    $totals[$key] += $count;
                }

                $emailIds = $summary->emailMessageIds();
            } catch (Throwable $exception) {
                $this->recordFailure($totals, $tenantId, 'generate', $exception);
            }

            try {
                $staleIds = $messages->listStaleQueuedEmailIds(($this->clock)()->modify(self::STALE_AFTER), self::STALE_LIMIT);
            } catch (Throwable $exception) {
                $staleIds = [];
                $this->recordFailure($totals, $tenantId, 'sweep', $exception);
            }

            try {
                foreach (array_values(array_unique(array_merge($emailIds, $staleIds))) as $messageId) {
                    $this->publisher->publish($tenantId, (int) $messageId);
                    $totals['published']++;
                }
            } catch (Throwable $exception) {
                $this->recordFailure($totals, $tenantId, 'publish', $exception);
            }
        }

        $this->logger->info('communication.scheduler.completed', $totals);

        return $totals;
    }

    /**
     * Counts the failure and logs only the tenant id, the stage
     * (services, generate, sweep or publish) and the exception class:
     * exception messages may carry personal data and are never logged.
     *
     * @param array<string, int> $totals
     */
    private function recordFailure(array &$totals, int $tenantId, string $stage, Throwable $exception): void
    {
        $totals['errors']++;
        $this->logger->error('communication.scheduler.tenant_failed', [
            'tenant_id' => $tenantId,
            'stage' => $stage,
            'exception' => $exception::class,
        ]);
    }
}

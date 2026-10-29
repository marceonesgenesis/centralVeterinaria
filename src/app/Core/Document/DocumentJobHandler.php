<?php

declare(strict_types=1);

namespace CentralVet\Document;

use CentralVet\Application\DocumentContentFactory;
use CentralVet\Application\DocumentGenerationService;
use CentralVet\Application\DocumentJobPublisher;
use CentralVet\Application\DocumentReadyNotifier;
use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Domain\Exception\DocumentGenerationFailed;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Persistence\CommunicationPreferenceRepository;
use CentralVet\Persistence\DocumentSourceQuery;
use CentralVet\Persistence\GeneratedDocumentRepository;
use CentralVet\Persistence\MessageTemplateRepository;
use CentralVet\Persistence\OutboundMessageRepository;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Persistence\SenderNamesQuery;
use CentralVet\Persistence\StoredObjectRepository;
use CentralVet\Queue\QueueInterface;
use CentralVet\Queue\QueueMessage;
use CentralVet\Storage\DocumentStorageFactory;
use CentralVet\Tenancy\TenantContext;
use Closure;
use PDO;
use Throwable;

/**
 * Worker handler of the `document.generate` job (Fase 7B, T-14).
 *
 * The payload carries only the document id; the tenant comes from the queue
 * envelope. A job without tenant or with an invalid document id is logged
 * and dropped (acked, no retry). The services are built per job by the
 * injected factory `(int $tenantId): array{0: DocumentGenerationService,
 * 1: MessageQueuePublisher}`, so the worker opens its PDO per job and tests
 * run on fakes.
 *
 * The `document_ready` e-mails created inside the generation transaction
 * are published only after it commits (generate() has returned). Logs carry
 * only `document_id`, `tenant_id` and the result code (LGPD).
 * {@see DocumentGenerationFailed} is not caught: the worker fails the job
 * and the queue applies backoff / dead-letter.
 */
final class DocumentJobHandler
{
    /**
     * @param Closure(int): array{0: DocumentGenerationService, 1: MessageQueuePublisher} $servicesFactory
     */
    public function __construct(
        private readonly Closure $servicesFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Production wiring: a new PDO per job ({@see PdoConnectionFactory::fromEnvironment()}),
     * the tenant context of the system actor `$systemUserId`, the document
     * storage of the environment and a real transaction on that PDO.
     */
    public static function forEnvironment(QueueInterface $queue, LoggerInterface $logger, int $systemUserId): self
    {
        $publisher = new MessageQueuePublisher($queue);

        return new self(
            static function (int $tenantId) use ($publisher, $systemUserId): array {
                $connection = PdoConnectionFactory::fromEnvironment();
                $context = TenantContext::authenticated($tenantId, $systemUserId);
                $sources = new DocumentSourceQuery($context, $connection);
                $names = new SenderNamesQuery($context, $connection);

                return [
                    new DocumentGenerationService(
                        new GeneratedDocumentRepository($context, $connection),
                        new DocumentContentFactory($sources, $names),
                        new DompdfDocumentRenderer(),
                        DocumentStorageFactory::fromEnvironment($context),
                        new StoredObjectRepository($context, $connection),
                        new DocumentReadyNotifier(
                            new CommunicationPreferenceRepository($context, $connection),
                            new MessageTemplateRepository($context, $connection),
                            new OutboundMessageRepository($context, $connection),
                            $sources,
                            $names,
                            $context,
                        ),
                        $context,
                        self::transactionOn($connection),
                    ),
                    $publisher,
                ];
            },
            $logger,
        );
    }

    /** @param array<mixed> $payload */
    public function supports(array $payload): bool
    {
        return ($payload['type'] ?? null) === DocumentJobPublisher::JOB_TYPE;
    }

    public function handle(QueueMessage $message): void
    {
        $tenantId = $message->tenantId;
        $documentId = $message->payload['document_id'] ?? null;

        if ($tenantId === null || $tenantId <= 0 || !is_int($documentId) || $documentId <= 0) {
            $this->logger->warning('document.job.invalid', [
                'job_id' => $message->id,
                'tenant_id' => $tenantId,
            ]);

            return;
        }

        $finalAttempt = $message->attempts + 1 >= $message->maxAttempts;

        /** @var DocumentGenerationService $service */
        /** @var MessageQueuePublisher $publisher */
        [$service, $publisher] = ($this->servicesFactory)($tenantId);
        $result = $service->generate($documentId, $finalAttempt);

        foreach ($result->emailMessageIds() as $messageId) {
            $publisher->publish($tenantId, $messageId);
        }

        $this->logger->info('document.job.' . $result->status(), [
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
            'code' => $result->status(),
        ]);
    }

    /** @return Closure(Closure): mixed */
    private static function transactionOn(PDO $connection): Closure
    {
        return static function (Closure $work) use ($connection): mixed {
            $connection->beginTransaction();

            try {
                $result = $work();
                $connection->commit();

                return $result;
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                throw $exception;
            }
        };
    }
}

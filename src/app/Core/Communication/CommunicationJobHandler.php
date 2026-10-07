<?php

declare(strict_types=1);

namespace CentralVet\Communication;

use CentralVet\Application\MessageDeliveryService;
use CentralVet\Application\MessageQueuePublisher;
use CentralVet\Observability\Logging\LoggerInterface;
use CentralVet\Persistence\CommunicationPreferenceRepository;
use CentralVet\Persistence\OutboundMessageRepository;
use CentralVet\Persistence\PdoConnectionFactory;
use CentralVet\Queue\QueueMessage;
use CentralVet\Tenancy\TenantContext;
use Closure;

/**
 * Worker handler of the `communication.message.send` job (Fase 7A, T-15).
 *
 * The payload carries only the message id; the tenant comes from the queue
 * envelope. A job without tenant or with an invalid message id is logged and
 * dropped (acked, no retry). The delivery service is built per job by the
 * injected factory `(int $tenantId): MessageDeliveryService`, so the worker
 * opens its PDO per job and tests run on fakes.
 *
 * Logs carry only `message_id`, `tenant_id` and the result code (LGPD).
 * {@see MessageDeliveryFailed} is not caught: the worker fails the job and
 * the queue applies backoff / dead-letter.
 */
final class CommunicationJobHandler
{
    /**
     * @param Closure(int): MessageDeliveryService $deliveryServiceFactory
     */
    public function __construct(
        private readonly Closure $deliveryServiceFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Production wiring: a new PDO per job ({@see PdoConnectionFactory::fromEnvironment()})
     * and the tenant context of the system actor `$systemUserId`.
     */
    public static function forEnvironment(
        MessageChannelProviderInterface $emailProvider,
        LoggerInterface $logger,
        int $systemUserId,
    ): self {
        return new self(
            static function (int $tenantId) use ($emailProvider, $systemUserId): MessageDeliveryService {
                $connection = PdoConnectionFactory::fromEnvironment();
                $context = TenantContext::authenticated($tenantId, $systemUserId);

                return new MessageDeliveryService(
                    new OutboundMessageRepository($context, $connection),
                    new CommunicationPreferenceRepository($context, $connection),
                    $emailProvider,
                    $context,
                );
            },
            $logger,
        );
    }

    /** @param array<mixed> $payload */
    public function supports(array $payload): bool
    {
        return ($payload['type'] ?? null) === MessageQueuePublisher::JOB_TYPE;
    }

    public function handle(QueueMessage $message): void
    {
        $tenantId = $message->tenantId;
        $messageId = $message->payload['message_id'] ?? null;

        if ($tenantId === null || $tenantId <= 0 || !is_int($messageId) || $messageId <= 0) {
            $this->logger->warning('communication.job.invalid', [
                'job_id' => $message->id,
                'tenant_id' => $tenantId,
            ]);

            return;
        }

        $finalAttempt = $message->attempts + 1 >= $message->maxAttempts;

        /** @var MessageDeliveryService $service */
        $service = ($this->deliveryServiceFactory)($tenantId);
        $result = $service->deliver($messageId, $finalAttempt);

        $this->logger->info('communication.job.' . $result, [
            'message_id' => $messageId,
            'tenant_id' => $tenantId,
            'code' => $result,
        ]);
    }
}

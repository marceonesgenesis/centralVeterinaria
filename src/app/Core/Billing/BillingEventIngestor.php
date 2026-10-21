<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Billing\Contract\BillingEventRepositoryInterface;
use DateTimeImmutable;
use PDOException;
use Throwable;

/**
 * Aplica um evento do Stripe já autenticado (T-17), uma única vez.
 *
 * 1. Transação 1: claim() registra o evento (`received`); null = já
 *    terminado → `duplicate`.
 * 2. Transação 2: claim() de novo, que agora trava a linha FOR UPDATE e
 *    relê o status — se outra entrega concorrente terminou o evento entre
 *    as transações, devolve null e o processador não roda (o mesmo evento
 *    nunca é aplicado duas vezes) —, depois process() e markDone(). Toda
 *    exceção (inclusive StripeApiException depois de orders->save) desfaz a
 *    transação inteira; o evento vira `failed` numa transação 3 e a exceção
 *    sobe (o webhook responde 500 e o Stripe reenvia).
 * 3. Depois do commit, fora de transação, fulfill() do pedido pago. Falha
 *    de provisionamento não falha o evento: o pedido fica
 *    `provisioning_failed` para o reconciliador (T-18).
 *
 * Deadlock (1213) e espera de trava esgotada (1205), como o do claim
 * concorrente do mesmo evento, são retentados até MAX_ATTEMPTS vezes cada
 * transação; a transação perdedora já foi desfeita, então repetir é seguro.
 *
 * Efeito colateral do passo 2: `attempts` conta as duas chamadas a claim()
 * por entrega (1ª entrega = 2).
 */
final class BillingEventIngestor
{
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const DUPLICATE = 'duplicate';

    private const MAX_ATTEMPTS = 3;

    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];

    public function __construct(
        private readonly TransactionRunner $tx,
        private readonly BillingEventRepositoryInterface $events,
        private readonly BillingEventProcessor $processor,
        private readonly TenantProvisioningService $provisioning,
    ) {
    }

    /**
     * @param array<string, mixed> $event evento do Stripe (`id`, `type`, `created`, `livemode`, `data`)
     * @return string `processed`, `ignored` ou `duplicate`
     */
    public function ingest(array $event, string $rawPayload, DateTimeImmutable $now): string
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $created = (int) ($event['created'] ?? 0);
        $livemode = (bool) ($event['livemode'] ?? false);
        $claim = fn (): ?int => $this->events->claim($eventId, $type, $created, $livemode, $rawPayload);

        $id = $this->retrying(fn (): ?int => $this->tx->run($claim));
        if ($id === null) {
            return self::DUPLICATE;
        }

        try {
            $outcome = $this->retrying(fn (): ?BillingEventOutcome => $this->tx->run(
                function () use ($claim, $event, $now): ?BillingEventOutcome {
                    $lockedId = $claim();
                    if ($lockedId === null) {
                        return null;
                    }

                    $outcome = $this->processor->process($event, $now);
                    $this->events->markDone($lockedId, $outcome->status(), $now);

                    return $outcome;
                },
            ));
        } catch (Throwable $e) {
            $this->markFailed($id, $e);

            throw $e;
        }

        if ($outcome === null) {
            return self::DUPLICATE;
        }

        $orderId = $outcome->orderToFulfill();
        if ($orderId !== null) {
            try {
                $this->provisioning->fulfill($orderId, $now);
            } catch (Throwable $e) {
                error_log(sprintf('[BillingEventIngestor] fulfill of billing order %d failed: %s', $orderId, $e::class));
            }
        }

        return $outcome->status();
    }

    private function markFailed(int $id, Throwable $cause): void
    {
        $error = $cause::class . ': ' . $cause->getMessage();

        try {
            $this->retrying(fn (): mixed => $this->tx->run(function () use ($id, $error): void {
                $this->events->markFailed($id, $error);
            }));
        } catch (Throwable $e) {
            error_log(sprintf('[BillingEventIngestor] markFailed of billing event %d failed: %s', $id, $e::class));
        }
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function retrying(\Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (PDOException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || !self::isRetryable($e)) {
                    throw $e;
                }
            }
        }
    }

    private static function isRetryable(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), self::RETRYABLE_MYSQL_ERRORS, true);
    }
}

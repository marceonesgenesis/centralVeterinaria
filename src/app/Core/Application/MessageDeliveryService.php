<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * Delivers a queued e-mail message from the worker (Fase 7A, T-12). System
 * actor: no authorization check. The tutor's current channel preference is
 * re-checked right before sending (an opt-out given after queueing cancels
 * the message), the message is claimed conditionally, and a provider
 * failure either releases the claim and rethrows (the queue retries) or, on
 * the final attempt, marks the message failed without rethrowing.
 *
 * Never logs nor puts the recipient or the body in an exception: only
 * MessageDeliveryFailed (error code only, no previous) leaves this class.
 */
final class MessageDeliveryService
{
    public const RESULT_SENT = 'sent';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    public const CODE_OPTED_OUT = 'opted_out';
    public const CODE_CONSENT_MISSING = 'consent_missing';
    public const CODE_PROVIDER_ERROR = 'provider_error';

    private readonly Closure $clock;

    public function __construct(
        private readonly OutboundMessageRepositoryInterface $messages,
        private readonly CommunicationPreferenceRepositoryInterface $preferences,
        private readonly MessageChannelProviderInterface $emailProvider,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @return string one of sent, skipped, cancelled or failed
     *
     * @throws MessageDeliveryFailed on a provider failure that is not the final attempt
     */
    public function deliver(int $messageId, bool $finalAttempt): string
    {
        $message = $this->messages->findById($messageId);

        if (
            !$message instanceof OutboundMessage
            || $message->tenantId() !== $this->context->tenantId()
            || $message->status() !== OutboundMessage::STATUS_QUEUED
            || $message->channel() !== CommunicationChannel::EMAIL
        ) {
            return self::RESULT_SKIPPED;
        }

        $preference = $this->preferences->findForTutor($message->tutorId())[CommunicationChannel::EMAIL] ?? null;

        if (!CommunicationPreference::permitsSending($preference, $message->legalBasis())) {
            $code = $preference !== null && !$preference->isOptedIn()
                ? self::CODE_OPTED_OUT
                : self::CODE_CONSENT_MISSING;

            return $this->messages->cancel($messageId, null, $code, $this->now())
                ? self::RESULT_CANCELLED
                : self::RESULT_SKIPPED;
        }

        if (!$this->messages->claim($messageId, $this->now())) {
            return self::RESULT_SKIPPED;
        }

        try {
            $providerMessageId = $this->emailProvider->deliver(new OutgoingMessage(
                $message->recipient(),
                $message->subject(),
                $message->bodyText(),
                'msg-' . $messageId,
            ));
        } catch (MessageDeliveryFailed $failure) {
            return $this->handleFailure($messageId, $failure, $finalAttempt);
        } catch (Throwable) {
            // The original message may carry the recipient: only the code survives.
            return $this->handleFailure($messageId, new MessageDeliveryFailed(self::CODE_PROVIDER_ERROR), $finalAttempt);
        }

        $this->messages->markSent($messageId, $this->emailProvider->name(), $providerMessageId, $this->now());

        return self::RESULT_SENT;
    }

    private function handleFailure(int $messageId, MessageDeliveryFailed $failure, bool $finalAttempt): string
    {
        if ($finalAttempt) {
            $this->messages->markFailed($messageId, $failure->errorCode(), $this->now());

            return self::RESULT_FAILED;
        }

        $this->messages->releaseClaim($messageId, $failure->errorCode());

        throw $failure;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}

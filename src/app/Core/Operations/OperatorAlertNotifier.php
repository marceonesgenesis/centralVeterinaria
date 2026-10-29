<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use CentralVet\Communication\MessageChannelProviderInterface;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Communication\OutgoingMessage;
use CentralVet\Operations\Contract\AlertStateRepositoryInterface;
use DateTimeImmutable;

/**
 * E-mails an operational alert to the operator (BILLING_OPERATOR_EMAIL,
 * the same channel as the Etapa 3 refund alert), at most once per alert key
 * every RESEND_AFTER_SECONDS. The e-mail body is exactly the $body the
 * caller passes: callers must keep it free of customer data. Logs carry
 * only the alert key and error codes, never the recipient or the body.
 */
final class OperatorAlertNotifier
{
    public const RESEND_AFTER_SECONDS = 21600;

    private const REFERENCE_PREFIX = 'ops-alert-';

    public function __construct(
        private readonly AlertStateRepositoryInterface $state,
        private readonly MessageChannelProviderInterface $email,
        private readonly string $operatorEmail,
    ) {
    }

    /** True when the alert was delivered now; false when skipped or failed. */
    public function notify(string $alertKey, string $subject, string $body, DateTimeImmutable $now): bool
    {
        $recipient = trim($this->operatorEmail);
        if ($recipient === '') {
            error_log('[ops-alert] ' . json_encode(['event' => 'ops_alert_no_recipient', 'key' => $alertKey]));

            return false;
        }

        $lastSent = $this->state->lastSentAt($alertKey);
        if ($lastSent !== null && $now->getTimestamp() - $lastSent->getTimestamp() < self::RESEND_AFTER_SECONDS) {
            return false;
        }

        try {
            $this->email->deliver(new OutgoingMessage($recipient, $subject, $body, self::REFERENCE_PREFIX . $alertKey));
        } catch (MessageDeliveryFailed $e) {
            error_log('[ops-alert] ' . json_encode([
                'event' => 'ops_alert_delivery_failed',
                'key' => $alertKey,
                'error' => $e->errorCode(),
            ]));

            return false;
        }

        $this->state->markSent($alertKey, $subject, $now);

        return true;
    }
}

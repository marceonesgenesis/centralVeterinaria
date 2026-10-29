<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Operations\OperatorAlertNotifier;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeAlertStateRepository;
use CentralVet\Tests\Support\FakeEmailProvider;
use DateTimeImmutable;

/**
 * OperatorAlertNotifier (Etapa 4, T-10): one e-mail per alert key to the
 * operator, deduplicated for 6 h, body passed through untouched.
 */
final class OperatorAlertNotifierTest
{
    private const OPERATOR = 'operador@centralvet.test';

    public function testFirstNotifyDeliversOneMessageToTheOperator(): void
    {
        $state = new FakeAlertStateRepository();
        $email = new FakeEmailProvider();
        $notifier = new OperatorAlertNotifier($state, $email, '  ' . self::OPERATOR . ' ');
        $now = new DateTimeImmutable('2026-10-26 10:00:00');

        $sent = $notifier->notify('backup_stale', 'Backup atrasado', 'Último backup há 30 h.', $now);

        Assert::same(21600, OperatorAlertNotifier::RESEND_AFTER_SECONDS);
        Assert::true($sent, 'first alert of a key is sent');
        Assert::count(1, $email->deliveries());
        $message = $email->deliveries()[0];
        Assert::same(self::OPERATOR, $message->recipient);
        Assert::same('Backup atrasado', $message->subject);
        Assert::same('Último backup há 30 h.', $message->body, 'the body is only what the caller passed');
        Assert::same('ops-alert-backup_stale', $message->reference);
        Assert::same($now->format('Y-m-d H:i:s.u'), $state->lastSentAt('backup_stale')?->format('Y-m-d H:i:s.u'));
    }

    public function testResendWithinSixHoursIsSuppressedAndAfterSixHoursIsSent(): void
    {
        $state = new FakeAlertStateRepository();
        $email = new FakeEmailProvider();
        $notifier = new OperatorAlertNotifier($state, $email, self::OPERATOR);
        $first = new DateTimeImmutable('2026-10-26 10:00:00');

        Assert::true($notifier->notify('backup_stale', 'Backup atrasado', 'corpo', $first));
        Assert::false(
            $notifier->notify('backup_stale', 'Backup atrasado', 'corpo', $first->modify('+1 hour')),
            'same key 1 h later is deduplicated',
        );
        Assert::count(1, $email->deliveries(), 'nothing delivered within 6 h');

        Assert::true(
            $notifier->notify('backup_stale', 'Backup atrasado', 'corpo', $first->modify('+7 hours')),
            'same key 7 h later is sent again',
        );
        Assert::count(2, $email->deliveries());
    }

    public function testDifferentKeysAreNotDeduplicatedTogether(): void
    {
        $state = new FakeAlertStateRepository();
        $email = new FakeEmailProvider();
        $notifier = new OperatorAlertNotifier($state, $email, self::OPERATOR);
        $now = new DateTimeImmutable('2026-10-26 10:00:00');

        Assert::true($notifier->notify('backup_stale', 'a', 'b', $now));
        Assert::true($notifier->notify('queue_stalled', 'a', 'b', $now));
        Assert::count(2, $email->deliveries());
    }

    public function testEmptyOperatorDeliversNothingAndLogsNoRecipient(): void
    {
        $state = new FakeAlertStateRepository();
        $email = new FakeEmailProvider();
        $notifier = new OperatorAlertNotifier($state, $email, '   ');

        [$sent, $logged] = $this->capturingLog(
            static fn (): bool => $notifier->notify('backup_stale', 'Backup atrasado', 'corpo', new DateTimeImmutable('2026-10-26 10:00:00')),
        );

        Assert::false($sent);
        Assert::count(0, $email->deliveries());
        Assert::null($state->lastSentAt('backup_stale'));
        Assert::stringContains('[ops-alert]', $logged);
        Assert::stringContains('ops_alert_no_recipient', $logged);
        Assert::stringContains('backup_stale', $logged);
    }

    public function testDeliveryFailureReturnsFalseAndKeepsStateIntact(): void
    {
        $state = new FakeAlertStateRepository();
        $email = new FakeEmailProvider();
        $email->failWith('smtp_connect');
        $notifier = new OperatorAlertNotifier($state, $email, self::OPERATOR);

        [$sent, $logged] = $this->capturingLog(
            static fn (): bool => $notifier->notify('backup_stale', 'Backup atrasado', 'corpo', new DateTimeImmutable('2026-10-26 10:00:00')),
        );

        Assert::false($sent);
        Assert::count(0, $email->deliveries());
        Assert::null($state->lastSentAt('backup_stale'), 'a failed delivery does not start the dedup window');
        Assert::stringContains('ops_alert_delivery_failed', $logged);
        Assert::stringContains('backup_stale', $logged);
        Assert::stringContains('smtp_connect', $logged);
        Assert::false(str_contains($logged, self::OPERATOR), 'the log never names the recipient');
    }

    /**
     * @param callable(): bool $action
     * @return array{0: bool, 1: string}
     */
    private function capturingLog(callable $action): array
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'cv-ops-alert-');
        $previousLog = ini_set('error_log', $logFile);
        try {
            $result = $action();
            $logged = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        return [$result, $logged];
    }
}

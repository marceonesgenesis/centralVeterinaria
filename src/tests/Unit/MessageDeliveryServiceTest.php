<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\MessageDeliveryService;
use CentralVet\Communication\MessageDeliveryFailed;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeCommunicationPreferenceRepository;
use CentralVet\Tests\Support\FakeEmailProvider;
use CentralVet\Tests\Support\FakeOutboundMessageRepository;
use DateTimeImmutable;

/**
 * Fase 7A, T-12: entrega de e-mail no worker (reconferência de
 * consentimento, claim, envio, retentativa e falha final), sem SMTP.
 */
final class MessageDeliveryServiceTest
{
    private const TENANT_ID = 1;
    private const TUTOR_ID = 7;
    private const RECIPIENT = 'f7a.teste@example.invalid';

    private FakeOutboundMessageRepository $messages;
    private FakeCommunicationPreferenceRepository $preferences;
    private FakeEmailProvider $provider;

    public function testConsentMessageWithOptInIsSent(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_CONSENT, CommunicationPreference::STATUS_OPTED_IN);

        Assert::same('sent', $this->service()->deliver($id, false));

        $stored = $this->messages->findById($id);
        Assert::same(OutboundMessage::STATUS_SENT, $stored->status());
        Assert::same('fake-provider', $stored->provider());
        Assert::same('fake-1', $stored->providerMessageId());
        Assert::count(1, $this->provider->deliveries());
        $delivery = $this->provider->deliveries()[0];
        Assert::same(self::RECIPIENT, $delivery->recipient);
        Assert::same('F7A teste', $delivery->subject);
        Assert::same('msg-' . $id, $delivery->reference);
    }

    public function testLegitimateInterestWithoutPreferenceIsSent(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);

        Assert::same('sent', $this->service()->deliver($id, false));
        Assert::same(OutboundMessage::STATUS_SENT, $this->messages->findById($id)->status());
        Assert::count(1, $this->provider->deliveries());
    }

    public function testOptOutAfterQueueingCancelsWithoutDelivery(): void
    {
        foreach ([MessagePurpose::LEGAL_BASIS_CONSENT, MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST] as $basis) {
            $id = $this->arrange($basis, CommunicationPreference::STATUS_OPTED_OUT);

            Assert::same('cancelled', $this->service()->deliver($id, false), $basis);

            $stored = $this->messages->findById($id);
            Assert::same(OutboundMessage::STATUS_CANCELLED, $stored->status(), $basis);
            Assert::same('opted_out', $stored->lastErrorCode(), $basis);
            Assert::null($stored->cancelledBySystemUserId(), $basis);
            Assert::same([], $this->provider->deliveries(), $basis);
        }
    }

    public function testConsentMessageWithoutOptInIsCancelledAsConsentMissing(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_CONSENT, null);

        Assert::same('cancelled', $this->service()->deliver($id, true));

        $stored = $this->messages->findById($id);
        Assert::same(OutboundMessage::STATUS_CANCELLED, $stored->status());
        Assert::same('consent_missing', $stored->lastErrorCode());
        Assert::same([], $this->provider->deliveries());
    }

    public function testNonFinalFailureReleasesClaimAndRethrows(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);
        $this->provider->failWith('smtp_connect');

        $caught = null;

        try {
            $this->service()->deliver($id, false);
        } catch (MessageDeliveryFailed $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'MessageDeliveryFailed was not rethrown');
        Assert::same('smtp_connect', $caught->errorCode());
        Assert::false(str_contains($caught->getMessage(), self::RECIPIENT));
        Assert::false(str_contains($caught->getMessage(), 'F7A teste corpo'));

        $stored = $this->messages->findById($id);
        Assert::same(OutboundMessage::STATUS_QUEUED, $stored->status());
        Assert::same(1, $stored->attemptCount());
        Assert::same('smtp_connect', $stored->lastErrorCode());
        Assert::null($stored->claimedAt());

        $this->provider->succeed();
        Assert::same('sent', $this->service()->deliver($id, false));
    }

    public function testFinalFailureMarksFailedWithoutPersonalData(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);
        $this->provider->failWith('smtp_connect');

        Assert::same('failed', $this->service()->deliver($id, true));

        $stored = $this->messages->findById($id);
        Assert::same(OutboundMessage::STATUS_FAILED, $stored->status());
        Assert::same('smtp_connect', $stored->lastErrorCode());
        Assert::same(1, $stored->attemptCount());
        Assert::notNull($stored->failedAt());
    }

    public function testUnexpectedProviderErrorBecomesProviderErrorCode(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);
        $service = new MessageDeliveryService(
            $this->messages,
            $this->preferences,
            new class implements \CentralVet\Communication\MessageChannelProviderInterface {
                public function channel(): string
                {
                    return CommunicationChannel::EMAIL;
                }

                public function name(): string
                {
                    return 'broken';
                }

                public function deliver(\CentralVet\Communication\OutgoingMessage $message): ?string
                {
                    throw new \RuntimeException('could not reach ' . $message->recipient);
                }
            },
            TenantContext::authenticated(self::TENANT_ID, 1),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00'),
        );

        $caught = null;

        try {
            $service->deliver($id, false);
        } catch (MessageDeliveryFailed $e) {
            $caught = $e;
        }

        Assert::notNull($caught, 'Unexpected provider error was not converted');
        Assert::same('provider_error', $caught->errorCode());
        Assert::null($caught->getPrevious());
        Assert::false(str_contains($caught->getMessage(), self::RECIPIENT));
        Assert::same('provider_error', $this->messages->findById($id)->lastErrorCode());

        Assert::same('failed', $service->deliver($id, true));
        Assert::same(OutboundMessage::STATUS_FAILED, $this->messages->findById($id)->status());
    }

    public function testRedeliveryOfSentMessageIsSkipped(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);

        Assert::same('sent', $this->service()->deliver($id, false));
        Assert::same('skipped', $this->service()->deliver($id, false));
        Assert::same('skipped', $this->service()->deliver($id, true));
        Assert::count(1, $this->provider->deliveries());
    }

    public function testMissingWhatsappOrClaimedMessageIsSkipped(): void
    {
        $id = $this->arrange(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, null);

        Assert::same('skipped', $this->service()->deliver(999, false));

        $whatsapp = self::message(MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST, CommunicationChannel::WHATSAPP);
        $this->messages->seed($whatsapp);
        Assert::same('skipped', $this->service()->deliver((int) $whatsapp->id(), false));

        Assert::true($this->messages->claim($id, new DateTimeImmutable('2026-10-06 09:59:00')));
        Assert::same('skipped', $this->service()->deliver($id, false));
        Assert::same(OutboundMessage::STATUS_QUEUED, $this->messages->findById($id)->status());
        Assert::same([], $this->provider->deliveries());
    }

    private function arrange(string $legalBasis, ?string $preferenceStatus): int
    {
        $message = self::message($legalBasis, CommunicationChannel::EMAIL);
        $this->messages = new FakeOutboundMessageRepository(self::TENANT_ID, $message);
        $this->preferences = new FakeCommunicationPreferenceRepository(self::TENANT_ID);
        $this->provider = new FakeEmailProvider('fake-provider');

        if ($preferenceStatus !== null) {
            $this->preferences->upsert(CommunicationPreference::record(
                self::TENANT_ID,
                self::TUTOR_ID,
                CommunicationChannel::EMAIL,
                $preferenceStatus,
                'in_person',
                2,
                new DateTimeImmutable('2026-10-06 08:00:00'),
            ));
        }

        return (int) $message->id();
    }

    private function service(): MessageDeliveryService
    {
        return new MessageDeliveryService(
            $this->messages,
            $this->preferences,
            $this->provider,
            TenantContext::authenticated(self::TENANT_ID, 1),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-06 10:00:00'),
        );
    }

    private static function message(string $legalBasis, string $channel): OutboundMessage
    {
        $purpose = $legalBasis === MessagePurpose::LEGAL_BASIS_CONSENT
            ? MessagePurpose::VACCINE_DUE
            : MessagePurpose::APPOINTMENT_CONFIRMATION;

        return OutboundMessage::compose(
            tenantId: self::TENANT_ID,
            systemUnitId: 3,
            tutorId: self::TUTOR_ID,
            patientId: null,
            templateId: null,
            purpose: $purpose,
            channel: $channel,
            origin: OutboundMessage::ORIGIN_AUTOMATION,
            legalBasis: $legalBasis,
            sourceType: null,
            sourceId: null,
            dedupeKey: null,
            recipient: $channel === CommunicationChannel::EMAIL ? self::RECIPIENT : '5585999990000',
            subject: $channel === CommunicationChannel::EMAIL ? 'F7A teste' : null,
            bodyText: 'F7A teste corpo',
            createdBySystemUserId: null,
        );
    }
}

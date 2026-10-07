<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Exception\CommunicationConsentRequiredException;
use CentralVet\Domain\MessagePurpose;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Domain\MessageTemplateDefaults;
use CentralVet\Domain\MessageTemplateRenderer;
use CentralVet\Domain\OutboundMessage;
use CentralVet\Tests\Support\Assert;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fase 7A, T-02: domínio de comunicação (renderizador, template, padrões,
 * base legal, consentimento e chave de idempotência), sem banco.
 */
final class CommunicationDomainTest
{
    public function testListsMirrorTheMigrationChecks(): void
    {
        Assert::same(['email', 'whatsapp'], CommunicationChannel::all());
        Assert::same(
            ['appointment_confirmation', 'vaccine_due', 'return_reminder', 'receivable_open', 'document_ready', 'custom'],
            MessagePurpose::all(),
        );
        Assert::same(['in_person', 'phone', 'written', 'online'], CommunicationPreference::SOURCES);
        Assert::same('legitimate_interest', MessagePurpose::LEGAL_BASIS_LEGITIMATE_INTEREST);
        Assert::same('consent', MessagePurpose::LEGAL_BASIS_CONSENT);
        Assert::same(
            ['queued', 'sent', 'failed', 'manual', 'cancelled'],
            [
                OutboundMessage::STATUS_QUEUED,
                OutboundMessage::STATUS_SENT,
                OutboundMessage::STATUS_FAILED,
                OutboundMessage::STATUS_MANUAL,
                OutboundMessage::STATUS_CANCELLED,
            ],
        );
    }

    public function testRenderReplacesTokenAndEmptiesMissingVariable(): void
    {
        $text = 'Olá {{tutor_name}}, {{patient_name}} em {{appointment_date}}.';

        Assert::same(
            'Olá Ana, Rex em .',
            MessageTemplateRenderer::render($text, ['tutor_name' => 'Ana', 'patient_name' => 'Rex']),
        );
    }

    public function testRenderLeavesUnknownTokensUntouched(): void
    {
        Assert::same('{{cpf}} Ana', MessageTemplateRenderer::render('{{cpf}} {{tutor_name}}', ['tutor_name' => 'Ana', 'cpf' => '1']));
    }

    public function testAssertKnownPlaceholdersRejectsCpf(): void
    {
        MessageTemplateRenderer::assertKnownPlaceholders('Olá {{tutor_name}} {{ due_date }}');

        try {
            MessageTemplateRenderer::assertKnownPlaceholders('Olá {{tutor_name}}, CPF {{cpf}}');
        } catch (InvalidArgumentException $e) {
            Assert::same('Unknown placeholder "cpf" in template', $e->getMessage());

            return;
        }

        throw new \RuntimeException('assertKnownPlaceholders accepted {{cpf}}');
    }

    public function testEmailTemplateWithoutSubjectIsRejected(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'email', 'Aviso', null, 'Olá {{tutor_name}}', 2));
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'email', 'Aviso', '   ', 'Olá', 2));

        $whatsapp = MessageTemplate::create(1, 'custom', 'whatsapp', 'Aviso', null, 'Olá {{tutor_name}}', 2);
        Assert::null($whatsapp->subject());
        Assert::true($whatsapp->isActive());
    }

    public function testTemplateRejectsUnknownPlaceholderAndInvalidLists(): void
    {
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'whatsapp', 'Aviso', null, 'CPF {{cpf}}', 2));
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'marketing', 'whatsapp', 'Aviso', null, 'Olá', 2));
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'sms', 'Aviso', null, 'Olá', 2));
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'whatsapp', '', null, 'Olá', 2));
        Assert::throws(InvalidArgumentException::class, static fn () => MessageTemplate::create(1, 'custom', 'whatsapp', 'Aviso', null, str_repeat('a', 2001), 2));
    }

    public function testTemplateUpdateAndActivation(): void
    {
        $template = MessageTemplate::create(1, 'custom', 'whatsapp', 'Aviso', null, 'Olá', 2);
        $template->update('vaccine_due', 'email', 'Vacina', 'Vacina vencendo', 'Vacina {{vaccine_name}} em {{due_date}}', 3);
        $template->deactivate();

        Assert::same('vaccine_due', $template->purpose());
        Assert::same('email', $template->channel());
        Assert::same('Vacina vencendo', $template->subject());
        Assert::false($template->isActive());
        Assert::same('inactive', $template->status());

        $template->activate();
        Assert::true($template->isActive());
    }

    public function testBuildDedupeKey(): void
    {
        Assert::same('vaccine_due:vaccination:7:email', OutboundMessage::buildDedupeKey('vaccine_due', 'vaccination', 7, 'email'));
    }

    public function testDefaultsExistForEveryPurposeAndChannel(): void
    {
        $count = 0;

        foreach (MessagePurpose::all() as $purpose) {
            foreach (CommunicationChannel::all() as $channel) {
                $default = MessageTemplateDefaults::for($purpose, $channel);
                Assert::true(trim($default['body']) !== '', "empty body for {$purpose}/{$channel}");
                MessageTemplateRenderer::assertKnownPlaceholders($default['body']);

                if ($channel === CommunicationChannel::EMAIL) {
                    Assert::true(is_string($default['subject']) && trim($default['subject']) !== '', "missing subject for {$purpose}");
                }

                ++$count;
            }
        }

        Assert::same(12, $count);
    }

    public function testLegalBasisFor(): void
    {
        Assert::same('legitimate_interest', MessagePurpose::legalBasisFor('appointment_confirmation'));
        Assert::same('legitimate_interest', MessagePurpose::legalBasisFor('return_reminder'));
        Assert::same('consent', MessagePurpose::legalBasisFor('vaccine_due'));
        Assert::same('consent', MessagePurpose::legalBasisFor('receivable_open'));
        Assert::same('consent', MessagePurpose::legalBasisFor('document_ready'));
        Assert::same('consent', MessagePurpose::legalBasisFor('custom'));
    }

    public function testPermitsSendingCoversTheSixCombinations(): void
    {
        $optedIn = self::preference('opted_in');
        $optedOut = self::preference('opted_out');

        Assert::false(CommunicationPreference::permitsSending(null, 'consent'));
        Assert::true(CommunicationPreference::permitsSending($optedIn, 'consent'));
        Assert::false(CommunicationPreference::permitsSending($optedOut, 'consent'));
        Assert::true(CommunicationPreference::permitsSending(null, 'legitimate_interest'));
        Assert::true(CommunicationPreference::permitsSending($optedIn, 'legitimate_interest'));
        Assert::false(CommunicationPreference::permitsSending($optedOut, 'legitimate_interest'));
    }

    public function testConsentExceptionMessages(): void
    {
        Assert::same('Tutor 5 has not opted in to email messages', CommunicationConsentRequiredException::notOptedIn(5, 'email')->getMessage());
        Assert::same('Tutor 5 has opted out of whatsapp messages', CommunicationConsentRequiredException::optedOut(5, 'whatsapp')->getMessage());
    }

    public function testComposeStartsQueued(): void
    {
        $message = OutboundMessage::compose(
            1, 2, 3, 4, null, 'vaccine_due', 'email', 'automation', 'consent',
            'vaccination', 7, OutboundMessage::buildDedupeKey('vaccine_due', 'vaccination', 7, 'email'),
            'f7a.teste@example.invalid', 'Vacina', 'Corpo', null,
        );

        Assert::null($message->id());
        Assert::same('queued', $message->status());
        Assert::same(0, $message->attemptCount());
        Assert::same('vaccine_due:vaccination:7:email', $message->dedupeKey());
    }

    private static function preference(string $status): CommunicationPreference
    {
        return CommunicationPreference::record(1, 3, 'email', $status, 'in_person', 2, new DateTimeImmutable('2026-10-06 09:00:00'));
    }
}

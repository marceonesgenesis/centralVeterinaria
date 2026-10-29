<?php

declare(strict_types=1);

namespace CentralVet\Domain;

/**
 * Built-in pt-BR texts used when a tenant has no active template for a
 * purpose and channel (Fase 7A). Short, plain text, with no clinical data
 * beyond the patient name, the vaccine name and dates. Every purpose has a
 * default on both channels; e-mail defaults always carry a subject.
 */
final class MessageTemplateDefaults
{
    private const SUBJECTS = [
        MessagePurpose::APPOINTMENT_CONFIRMATION => 'Lembrete de consulta de {{patient_name}}',
        MessagePurpose::VACCINE_DUE => 'Vacina de {{patient_name}} a vencer',
        MessagePurpose::RETURN_REMINDER => 'Lembrete de retorno de {{patient_name}}',
        MessagePurpose::RECEIVABLE_OPEN => 'Pagamento em aberto na {{clinic_name}}',
        MessagePurpose::DOCUMENT_READY => 'Documento disponível na {{clinic_name}}',
        MessagePurpose::CUSTOM => 'Mensagem da {{clinic_name}}',
    ];

    private const BODIES = [
        MessagePurpose::APPOINTMENT_CONFIRMATION => 'Olá {{tutor_name}}, lembramos a consulta de {{patient_name}} em {{appointment_date}} às {{appointment_time}} na {{unit_name}}. Responda para confirmar ou remarcar.',
        MessagePurpose::VACCINE_DUE => 'Olá {{tutor_name}}, a vacina {{vaccine_name}} de {{patient_name}} vence em {{due_date}}. Entre em contato com a {{unit_name}} para agendar.',
        MessagePurpose::RETURN_REMINDER => 'Olá {{tutor_name}}, lembramos o retorno de {{patient_name}} em {{appointment_date}} às {{appointment_time}} na {{unit_name}}. Responda para confirmar ou remarcar.',
        MessagePurpose::RECEIVABLE_OPEN => 'Olá {{tutor_name}}, consta um valor em aberto de {{amount_due}} na {{unit_name}}. Entre em contato para regularizar ou desconsidere se já pagou.',
        MessagePurpose::DOCUMENT_READY => 'Olá {{tutor_name}}, há um documento de {{patient_name}} disponível na {{unit_name}}. Entre em contato para recebê-lo.',
        MessagePurpose::CUSTOM => 'Olá {{tutor_name}}, a {{unit_name}} tem um recado para você. Entre em contato conosco.',
    ];

    private function __construct()
    {
    }

    /** @return array{subject: ?string, body: string} */
    public static function for(string $purpose, string $channel): array
    {
        MessagePurpose::assertValid($purpose);
        CommunicationChannel::assertValid($channel);

        return [
            'subject' => $channel === CommunicationChannel::EMAIL ? self::SUBJECTS[$purpose] : null,
            'body' => self::BODIES[$purpose],
        ];
    }
}

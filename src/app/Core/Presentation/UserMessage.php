<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

/**
 * Catálogo de mensagens de domínio (texto cru, em inglês, lançado pelos
 * serviços do Core) → chave de tradução com ^1/^2 para `_t()`.
 *
 * Sem dependência do Adianti: só resolve a chave e os parâmetros crus. Quem
 * exibe (CvFormat::userError/userMessage) traduz e escapa os parâmetros.
 */
final class UserMessage
{
    /** Mensagem exata → chave de tradução. */
    public const STATIC = [
        'Patient sex must be one of M, F, U' => 'Patient sex must be one of M, F, U',
        'Patient weight_kg cannot be negative' => 'Patient weight_kg cannot be negative',
        'bank_name must have at most 120 characters' => 'bank_name must have at most 120 characters',
        'name must have at most 120 characters' => 'name must have at most 120 characters',
        'balance_cents must be an integer' => 'balance_cents must be an integer',
        'Service not found for this tenant' => 'Service not found for this tenant',
        'valid_until cannot be in the past' => 'valid_until cannot be in the past',
        'valid_until must be a Y-m-d date' => 'valid_until must be a Y-m-d date',
        'items must be a non-empty list' => 'items must be a non-empty list',
        'full_name is required' => 'full_name is required',
        'phone is required' => 'phone is required',
        'A tutor with this document already exists in this tenant' => 'A tutor with this document already exists in this tenant',
        'Invalid amount' => 'Invalid amount',
        'Invalid date and time' => 'Invalid date and time',
        'Invalid file' => 'Invalid file',
        'Photo must be a JPEG, PNG or WEBP image' => 'Photo must be a JPEG, PNG or WEBP image',
        'Photo must be at most 2 MB' => 'Photo must be at most 2 MB',
        'Name must not contain < or >' => 'Name must not contain < or >',
        // Fase 6A — internação.
        'ends_at must be after starts_at' => 'ends_at must be after starts_at',
        'Prescription period cannot exceed 30 days' => 'Prescription period cannot exceed 30 days',
        'frequency_hours must be between 1 and 168' => 'frequency_hours must be between 1 and 168',
        'quantity_per_administration is required when a product is selected' => 'quantity_per_administration is required when a product is selected',
        'quantity_per_administration requires a product' => 'quantity_per_administration requires a product',
        'At least one vital sign is required' => 'At least one vital sign is required',
        'pain_score must be between 0 and 10' => 'pain_score must be between 0 and 10',
        'responsible_system_user_id must be an active user of this tenant' => 'responsible_system_user_id must be an active user of this tenant',
        'to_bed_id must differ from from_bed_id' => 'to_bed_id must differ from from_bed_id',
        'expected_discharge_date must be a Y-m-d date' => 'expected_discharge_date must be a Y-m-d date',
        'code must be between 1 and 30 characters' => 'code must be between 1 and 30 characters',
        'name must be between 1 and 120 characters' => 'name must be between 1 and 120 characters',
        'daily_rate_cents cannot be negative' => 'daily_rate_cents cannot be negative',
        'reason_text must be at most 500 characters' => 'reason_text must be at most 500 characters',
        'temperature_c cannot be negative' => 'temperature_c cannot be negative',
        'heart_rate_bpm cannot be negative' => 'heart_rate_bpm cannot be negative',
        'respiratory_rate_rpm cannot be negative' => 'respiratory_rate_rpm cannot be negative',
        'weight_kg cannot be negative' => 'weight_kg cannot be negative',
        'from_bed_id and to_bed_id are required for a transfer' => 'from_bed_id and to_bed_id are required for a transfer',
        'bed_id must be positive' => 'bed_id must be positive',
        'responsible_system_user_id must be positive' => 'responsible_system_user_id must be positive',
        // Fase 6B — cirurgia.
        'Surgery duration cannot exceed 24 hours' => 'Surgery duration cannot exceed 24 hours',
        'duration_minutes must be between 15 and 1440' => 'duration_minutes must be between 15 and 1440',
        'scheduled_end_at must be after scheduled_start_at' => 'scheduled_end_at must be after scheduled_start_at',
        'quantity must be between 1 and 9999' => 'quantity must be between 1 and 9999',
        'consent_signer_name is required' => 'consent_signer_name is required',
        'consent_text is required' => 'consent_text is required',
        'cancellation_reason_text is required' => 'cancellation_reason_text is required',
        'surgeon_system_user_id must be an active user of this tenant' => 'surgeon_system_user_id must be an active user of this tenant',
        // Fase 7A — comunicação e central de pendências.
        'Another active template already exists for this purpose and channel' => 'Another active template already exists for this purpose and channel',
        'subject is required for email templates' => 'subject is required for email templates',
        'subject must be at most 190 characters' => 'subject must be at most 190 characters',
        'body must be between 1 and 2000 characters' => 'body must be between 1 and 2000 characters',
        'body must not be empty' => 'body must not be empty',
        'recipient must be between 1 and 190 characters' => 'recipient must be between 1 and 190 characters',
        'subject must be between 1 and 190 characters for email messages' => 'subject must be between 1 and 190 characters for email messages',
        'source_type and a positive source_id must be given together' => 'source_type and a positive source_id must be given together',
        'Invalid phone number for WhatsApp' => 'Invalid phone number for WhatsApp',
        'Identifiers must be positive integers' => 'Identifiers must be positive integers',
        'Invalid pending item type' => 'Invalid pending item type',
        'Invalid pending item priority' => 'Invalid pending item priority',
        'Invalid deep-link class' => 'Invalid deep-link class',
        'Invalid deep-link parameter key' => 'Invalid deep-link parameter key',
        'Replace the template placeholders before sending the message' => 'Replace the template placeholders before sending the message',
        // Fase 7B — documentos.
        'Document not found' => 'Document not found',
        'Document source not found' => 'Document source not found',
        'Patient has no vaccinations to print' => 'Patient has no vaccinations to print',
        'Surgery consent has not been recorded' => 'Surgery consent has not been recorded',
        'Document text has unresolved placeholders' => 'Document text has unresolved placeholders',
        'Document template is not available' => 'Document template is not available',
        'A document template with this name already exists' => 'A document template with this name already exists',
        'Could not allocate document version' => 'Could not allocate document version',
        'Document body must be between 1 and 20000 characters' => 'Document body must be between 1 and 20000 characters',
        'body must be between 1 and 20000 characters' => 'body must be between 1 and 20000 characters',
        'Generated document has no id yet' => 'Generated document has no id yet',
    ];

    /** Regex ancorada (com /D: `$` não aceita "\n" final) → chave de tradução; cada grupo capturado vira ^1, ^2. */
    public const PATTERNS = [
        '/^Encounter (\d+|\(new\)) is finished and cannot be paused$/D' => 'Encounter ^1 is finished and cannot be paused',
        '/^Encounter (\d+|\(new\)) is already paused$/D' => 'Encounter ^1 is already paused',
        '/^Encounter (\d+|\(new\)) is not paused$/D' => 'Encounter ^1 is not paused',
        '/^A bank account named "(.+)" already exists for this unit$/D' => 'A bank account named "^1" already exists for this unit',
        '/^Bank account \d+ not found for this tenant$/D' => 'Bank account not found',
        '/^Service \d+ has appointments; deactivate it instead$/D' => 'This service has appointments; deactivate it instead',
        '/^A service named "(.+)" already exists for this tenant$/D' => 'A service named "^1" already exists',
        '/^A product named "(.+)" already exists for this tenant$/D' => 'A product named "^1" already exists',
        '/^A product with code "(.+)" already exists for this tenant$/D' => 'A product with code "^1" already exists',
        '/^A template named "(.+)" already exists for this tenant$/D' => 'A template named "^1" already exists',
        '/^Appointment (\d+) cannot be rescheduled from status (\S+)$/D' => 'Appointment ^1 cannot be rescheduled from status ^2',
        '/^Appointment (\d+) is already in the queue$/D' => 'This appointment is already in the queue',
        '/^Requested slot .+ conflicts with an existing appointment for professional_system_user_id \d+$/D' => 'Requested slot conflicts with an existing appointment',
        '/^Exam request (?:\d+|\(new\)) cannot move to "[^"]+" from status "[^"]*"$/D' => 'This exam request cannot move to this status',
        '/^Encounter (\d+|\(new\)) is already finished$/D' => 'Encounter ^1 is already finished',
        '/^Bank account must belong to the current unit \d+$/D' => 'Bank account must belong to the current unit',
        '/^(?:Appointment|Patient|Tutor|Product|Payable|Bed|Hospitalization|Administration|Order) \d+ not found for this tenant$/D' => 'Record not found',
        '/^[a-z_]+ \d+ was not found (?:for|among the active products of) the authenticated tenant$/D' => 'Record not found',
        '/^items\[\]\.([a-z_]+) is required$/D' => 'Fill in ^1 on every item',
        '/^Payment of \d+ cent\(s\) would raise paid_cents to \d+, exceeding total_cents of \d+ cent\(s\)$/D' => 'The payment exceeds the open balance',
        '/^Discount of \d+ cent\(s\) exceeds subtotal of \d+ cent\(s\)$/D' => 'The discount cannot be greater than the subtotal',
        '/^Insufficient stock for product_id \d+: short by (\d+) unit\(s\)$/D' => 'Insufficient stock: ^1 unit(s) missing',
        // Fase 6A — internação (ids internos não aparecem na tela).
        '/^Bed \d+ is not available$/D' => 'This bed is not available',
        '/^Bed \d+ is occupied and cannot be deactivated$/D' => 'This bed is occupied and cannot be deactivated',
        '/^Bed \d+ is not occupied by hospitalization \d+$/D' => 'The bed is not occupied by this hospitalization',
        '/^A bed with code "(.+)" already exists in this unit$/D' => 'A bed with code "^1" already exists in this unit',
        '/^Patient \d+ already has an active hospitalization$/D' => 'This patient is already hospitalized',
        '/^Hospitalization (?:\d+|\(new\))? ?is not admitted$/D' => 'This hospitalization is no longer active',
        '/^Administration (?:\d+|\(new\)) is not pending$/D' => 'This administration is no longer pending',
        '/^Order (?:\d+|\(new\)) is not active$/D' => 'This prescription is no longer active',
        '/^Unknown route "[^"]*"$/D' => 'Invalid route',
        '/^Unknown order_type "[^"]*"$/D' => 'Invalid prescription type',
        '/^Encounter account \d+ cannot be modified: status is "[^"]*", not "open"$/D' => 'The encounter account is not open',
        // Fase 6B — cirurgia (ids internos e códigos de fase/status não aparecem na tela).
        '/^Surgery room \d+ is already booked for this period$/D' => 'This surgery room is already booked for this period',
        '/^Surgery room \d+ is not active$/D' => 'This surgery room is not active',
        '/^Surgery room \d+ belongs to another unit$/D' => 'This surgery room belongs to another unit',
        '/^A surgery room with code "(.+)" already exists in this unit$/D' => 'A surgery room with code "^1" already exists in this unit',
        '/^Surgery \d+ is not scheduled$/D' => 'This surgery is not scheduled',
        '/^Surgery \d+ is not in pre-op$/D' => 'This surgery is not in pre-op',
        '/^Surgery \d+ is not in progress$/D' => 'This surgery is not in progress',
        '/^Surgery \d+ is not completed$/D' => 'This surgery is not completed',
        '/^Surgery \d+ is cancelled$/D' => 'This surgery is cancelled',
        '/^Surgery \d+ has no recorded consent$/D' => 'This surgery has no recorded consent',
        '/^Surgery \d+ is not open for pre-operative changes$/D' => 'This surgery is no longer open for pre-operative changes',
        '/^Surgery \d+ cannot be cancelled in its current status$/D' => 'This surgery cannot be cancelled in its current status',
        '/^Surgery \d+ changed status concurrently$/D' => 'This surgery was changed by someone else; reload it and try again',
        '/^Surgery \d+ already has a follow-up appointment$/D' => 'This surgery already has a follow-up appointment',
        '/^Checklist phase "[^"]*" is already confirmed for surgery \d+$/D' => 'This checklist phase is already confirmed',
        '/^Checklist phase "sign_in" is not confirmed for surgery \d+$/D' => 'The "Before induction" checklist phase is not confirmed',
        '/^Checklist phase "time_out" is not confirmed for surgery \d+$/D' => 'The "Before incision" checklist phase is not confirmed',
        '/^Checklist phase "sign_out" is not confirmed for surgery \d+$/D' => 'The "Before leaving the room" checklist phase is not confirmed',
        '/^Checklist phase "[^"]*" cannot be confirmed while surgery \d+ is \S+$/D' => 'This checklist phase cannot be confirmed in the current surgery status',
        '/^All checklist items of phase "[^"]*" must be checked$/D' => 'All items of this checklist phase must be checked',
        '/^Team member \d+ must be an active user of this tenant$/D' => 'Every team member must be an active user of this tenant',
        '/^Material \d+ was already removed$/D' => 'This material was already removed',
        '/^Unknown surgery event type "[^"]*"$/D' => 'Unknown surgery event type',
        '/^Unknown checklist phase "[^"]*"$/D' => 'Unknown checklist phase',
        '/^Unknown checklist item "[^"]*"$/D' => 'Unknown checklist item',
        '/^Unknown team role "[^"]*"$/D' => 'Unknown team role',
        // Fase 7A — comunicação (ids internos, códigos de canal/status e contato não aparecem na tela).
        '/^Tutor \d+ has not opted in to email messages$/D' => 'The tutor has not consented to e-mail messages',
        '/^Tutor \d+ has not opted in to whatsapp messages$/D' => 'The tutor has not consented to WhatsApp messages',
        '/^Tutor \d+ has opted out of email messages$/D' => 'The tutor refused e-mail messages',
        '/^Tutor \d+ has opted out of whatsapp messages$/D' => 'The tutor refused WhatsApp messages',
        '/^Tutor \d+ has no e-mail address$/D' => 'The tutor has no e-mail address',
        '/^Unknown placeholder "(.+)" in template$/D' => 'Unknown placeholder "^1" in template',
        '/^Unknown communication channel "[^"]*"$/D' => 'Invalid communication channel',
        '/^Unknown message purpose "[^"]*"$/D' => 'Invalid message purpose',
        '/^Unknown legal basis "[^"]*"$/D' => 'Invalid legal basis',
        '/^Unknown consent source "[^"]*"$/D' => 'Invalid consent source',
        '/^Unknown communication preference status "[^"]*"$/D' => 'Invalid communication preference status',
        '/^Unknown message template status "[^"]*"$/D' => 'Invalid message template status',
        '/^Appointment \d+ and encounter \d+ belong to different patients$/D' => 'The appointment and the encounter belong to different patients',
        '/^Message \d+ is no longer awaiting manual send$/D' => 'This message is no longer awaiting manual send',
        '/^Message \d+ is no longer queued$/D' => 'This message is no longer queued',
        '/^Message \d+ was cancelled because the tutor opted out of whatsapp messages$/D' => 'The tutor refused WhatsApp messages. The message was cancelled',
        '/^Message \d+ was cancelled because the tutor has not opted in to whatsapp messages$/D' => 'The tutor has not consented to WhatsApp messages. The message was cancelled',
        '/^Message \d+ has not failed$/D' => 'This message has not failed',
        '/^Message \d+ is not a WhatsApp message$/D' => 'This message is not a WhatsApp message',
        '/^Template \d+ does not match the message purpose and channel$/D' => 'This template does not match the message purpose and channel',
        '/^patient_id \d+ was not found for tutor_id \d+$/D' => 'The patient does not belong to this tutor',
        '/^Invalid deep-link parameter \S+$/D' => 'Invalid deep-link parameter',
        // Fase 7B — documentos (códigos de tipo, status e falha não aparecem na tela).
        '/^Document (\d+) can no longer be retried$/D' => 'Document ^1 can no longer be retried',
        '/^Unknown placeholder: \{\{(.+)\}\}$/D' => 'Unknown placeholder: {{^1}}',
        '/^Document generation failed: [a-z_]+$/D' => 'The document could not be generated',
        '/^Unknown document kind "[^"]*"$/D' => 'Unknown document kind',
        '/^Document kind "[^"]*" (?:does not accept a body|does not use templates|has no default template)$/D' => 'This document type does not support this option',
        '/^Unknown document template status "[^"]*"$/D' => 'Invalid document template status',
        '/^Document source type "[^"]*" does not match kind "[^"]*"$/D' => 'The document source does not match the document type',
        // Genéricos por último: STATIC é consultado antes e os padrões específicos vêm primeiro.
        '/^([a-z_]+) must have at most (\d+) characters$/D' => '^1 must have at most ^2 characters',
        '/^([a-z_]+) is required$/D' => '^1 is required',
        '/^([a-z_]+) must be a positive integer$/D' => '^1 must be a positive integer',
        '/^([a-z_]+) must be a whole number$/D' => '^1 must be a whole number',
        '/^([a-z_]+) must be a number$/D' => '^1 must be a number',
    ];

    private function __construct()
    {
    }

    /**
     * @return array{key: string, params: list<string>}|null
     */
    public static function resolve(string $message): ?array
    {
        if (isset(self::STATIC[$message])) {
            return ['key' => self::STATIC[$message], 'params' => []];
        }

        foreach (self::PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return ['key' => $key, 'params' => array_values(array_slice($matches, 1))];
            }
        }

        return null;
    }
}

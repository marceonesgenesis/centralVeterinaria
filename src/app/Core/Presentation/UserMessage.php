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
        '/^(?:Appointment|Patient|Tutor|Product|Payable) \d+ not found for this tenant$/D' => 'Record not found',
        '/^items\[\]\.([a-z_]+) is required$/D' => 'Fill in ^1 on every item',
        '/^Payment of \d+ cent\(s\) would raise paid_cents to \d+, exceeding total_cents of \d+ cent\(s\)$/D' => 'The payment exceeds the open balance',
        '/^Discount of \d+ cent\(s\) exceeds subtotal of \d+ cent\(s\)$/D' => 'The discount cannot be greater than the subtotal',
        '/^Insufficient stock for product_id \d+: short by (\d+) unit\(s\)$/D' => 'Insufficient stock: ^1 unit(s) missing',
        // Genéricos por último: STATIC é consultado antes e os padrões específicos vêm primeiro.
        '/^([a-z_]+) must have at most (\d+) characters$/D' => '^1 must have at most ^2 characters',
        '/^([a-z_]+) is required$/D' => '^1 is required',
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

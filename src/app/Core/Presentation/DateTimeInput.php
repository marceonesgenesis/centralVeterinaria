<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use DateTimeImmutable;

/**
 * Parser único de data e hora digitada (rodada 2, T-53). Sem Adianti.
 *
 * `new DateTimeImmutable('01/10/2026 11:00')` lê m/d/Y (10 de janeiro); aqui
 * a barra é sempre d/m/Y. Aceita, depois de trim e de forma estrita
 * (createFromFormat com `!`, sem warning nem erro em getLastErrors()):
 *
 *  - `Y-m-d H:i` e `Y-m-d H:i:s` (formato do banco / setDatabaseMask);
 *  - `d/m/Y H:i` e `d/m/Y H:i:s` (formato digitado).
 *
 * Ano fora de 1900..2100 ou qualquer outro texto lança
 * \InvalidArgumentException('Invalid date and time') (catálogo UserMessage →
 * "Data e hora inválidas"). Mesma regra de EncounterView::parseFollowUpScheduledAt.
 */
final class DateTimeInput
{
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2100;

    private const FORMATS = ['!Y-m-d H:i', '!Y-m-d H:i:s', '!d/m/Y H:i', '!d/m/Y H:i:s'];

    private const INVALID = 'Invalid date and time';

    private function __construct()
    {
    }

    public static function parse(string $raw): DateTimeImmutable
    {
        $raw = trim($raw);

        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            $year = (int) $parsed->format('Y');

            if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
                break;
            }

            return $parsed;
        }

        throw new \InvalidArgumentException(self::INVALID);
    }
}

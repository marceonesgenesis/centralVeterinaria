<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use InvalidArgumentException;

/**
 * Defesa na entrada (rodada 3, T-14): nomes de Patient, Tutor, Service e
 * Product não aceitam `<` nem `>`. Chamado só no caminho de gravação dos
 * services (create/update/duplicate/importCsv), nunca no reconstitute, para
 * registros antigos com `<`/`>` continuarem listando e abrindo.
 */
final class NameText
{
    public const MARKUP_MESSAGE = 'Name must not contain < or >';

    private function __construct()
    {
    }

    public static function assertNoMarkup(string $name): void
    {
        if (strpbrk($name, '<>') !== false) {
            throw new InvalidArgumentException(self::MARKUP_MESSAGE);
        }
    }
}

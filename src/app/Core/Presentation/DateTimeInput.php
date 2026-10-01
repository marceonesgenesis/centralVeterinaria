<?php

declare(strict_types=1);

namespace CentralVet\Presentation;

use DateTimeImmutable;

/**
 * Fachada de Presentation para o parser de data e hora (rodada 3, T-02).
 * A regra (formatos, 1900..2100, 'Invalid date and time') vive em
 * \CentralVet\Support\DateTimeInput; aqui só se delega, para as telas e os
 * testes que importam este nome não mudarem.
 */
final class DateTimeInput
{
    private function __construct()
    {
    }

    public static function parse(string $raw): DateTimeImmutable
    {
        return \CentralVet\Support\DateTimeInput::parse($raw);
    }
}

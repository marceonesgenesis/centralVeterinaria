<?php

declare(strict_types=1);

namespace CentralVet\Operations;

/**
 * Um alerta do monitor operacional: chave estável (deduplicação no
 * OperatorAlertNotifier), contagem e resumo em pt-BR só com números.
 */
final class OperationalAlert
{
    public function __construct(
        public readonly string $key,
        public readonly int $count,
        public readonly string $summary,
    ) {
    }
}

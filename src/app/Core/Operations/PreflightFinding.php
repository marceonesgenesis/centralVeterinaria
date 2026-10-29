<?php

declare(strict_types=1);

namespace CentralVet\Operations;

/**
 * Um achado do preflight de produção (Etapa 4, T-09): nível e código da
 * regra. Nunca carrega o valor da variável checada.
 */
final class PreflightFinding
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    public function __construct(
        public readonly string $level,
        public readonly string $code,
    ) {
    }
}

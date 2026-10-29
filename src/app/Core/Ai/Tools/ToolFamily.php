<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;

/**
 * Tool families and their allowlist. A tool whose name is not listed under
 * its family is never offered to the model, whatever the permissions.
 */
final class ToolFamily
{
    public const CLINICAL = 'clinical';
    public const MANAGEMENT = 'management';

    public const ALL = [self::CLINICAL, self::MANAGEMENT];

    public const ALLOWLIST = [
        self::CLINICAL => [
            'buscar_paciente',
            'obter_paciente',
            'obter_historico_atendimentos',
            'obter_prontuario',
            'obter_prescricoes',
            'obter_exames',
            'consultar_vacinacao',
        ],
        self::MANAGEMENT => [
            'consultar_agenda',
            'verificar_horarios',
            'consultar_estoque',
            'listar_debitos',
            'indicadores_clinica',
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> feature flags that must all be on for the family */
    public static function requiredFlags(string $family): array
    {
        return match ($family) {
            self::CLINICAL => [FeatureFlagRepositoryInterface::AI_ASSISTANT],
            self::MANAGEMENT => [
                FeatureFlagRepositoryInterface::AI_ASSISTANT,
                FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT,
            ],
            default => throw new \InvalidArgumentException('Unknown tool family: ' . $family),
        };
    }

    public static function isAllowed(string $family, string $toolName): bool
    {
        return in_array($toolName, self::ALLOWLIST[$family] ?? [], true);
    }
}

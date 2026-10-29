<?php

declare(strict_types=1);

namespace CentralVet\Pilot;

use CentralVet\Billing\PlanEntitlements;
use ReflectionClass;

/**
 * Configuração do piloto lida do ambiente (Etapa 5, T-03): planos ofertados,
 * recursos fora do recorte ligados em prévia, canal de suporte e retenção dos
 * dados após o cancelamento. Valor inválido cai no padrão; nada é logado.
 */
final class PilotSettings
{
    public const DEFAULT_RETENTION_DAYS = 30;

    public const DEFAULT_SUPPORT_HOURS = 'Segunda a sexta, das 8h às 18h';

    private const VARIABLES = [
        'OFFER_PLANS',
        'OFFER_PREVIEW_FEATURES',
        'SUPPORT_EMAIL',
        'SUPPORT_WHATSAPP',
        'SUPPORT_HOURS',
        'DATA_RETENTION_DAYS_AFTER_CANCEL',
    ];

    /** @var array<string, string> */
    private readonly array $env;

    /** @param array<string, mixed> $env */
    private function __construct(array $env)
    {
        $values = [];
        foreach (self::VARIABLES as $name) {
            $value = $env[$name] ?? '';
            $values[$name] = is_scalar($value) ? trim((string) $value) : '';
        }
        $this->env = $values;
    }

    /** @param array<string, mixed> $env */
    public static function fromArray(array $env): self
    {
        return new self($env);
    }

    public static function fromEnvironment(): self
    {
        $env = [];
        foreach (self::VARIABLES as $name) {
            $env[$name] = (string) (getenv($name) ?: '');
        }

        return new self($env);
    }

    /** @return list<string> ids de PlanEntitlements::PLAN_IDS, na ordem desse array */
    public function offeredPlans(): array
    {
        $listed = $this->csv('OFFER_PLANS');
        $plans = array_values(array_filter(
            PlanEntitlements::PLAN_IDS,
            static fn (string $planId): bool => in_array($planId, $listed, true),
        ));

        return $plans === [] ? PlanEntitlements::PLAN_IDS : $plans;
    }

    /** @return list<string> constantes FEATURE_* de PlanEntitlements */
    public function previewFeatures(): array
    {
        $known = self::knownFeatures();
        $features = [];
        foreach ($this->csv('OFFER_PREVIEW_FEATURES') as $feature) {
            if (in_array($feature, $known, true) && !in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }

        return $features;
    }

    public function supportEmail(): ?string
    {
        $email = $this->env['SUPPORT_EMAIL'];

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function supportWhatsapp(): ?string
    {
        $number = $this->env['SUPPORT_WHATSAPP'];

        return preg_match('/^\d{10,15}$/', $number) === 1 ? $number : null;
    }

    public function supportHours(): string
    {
        $hours = $this->env['SUPPORT_HOURS'];

        return $hours !== '' ? $hours : self::DEFAULT_SUPPORT_HOURS;
    }

    public function retentionDaysAfterCancel(): int
    {
        $days = filter_var($this->env['DATA_RETENTION_DAYS_AFTER_CANCEL'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3650],
        ]);

        return is_int($days) ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /** @return list<string> */
    private function csv(string $name): array
    {
        $items = array_map('trim', explode(',', $this->env[$name]));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /** @return list<string> */
    private static function knownFeatures(): array
    {
        $features = [];
        foreach ((new ReflectionClass(PlanEntitlements::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'FEATURE_') && is_string($value)) {
                $features[] = $value;
            }
        }

        return $features;
    }
}

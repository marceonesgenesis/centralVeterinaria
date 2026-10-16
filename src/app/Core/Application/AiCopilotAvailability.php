<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Ai\AiConfig;
use CentralVet\Ai\Tools\ToolFamily;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Lightweight copilot availability for the host screens (PatientForm,
 * EncounterView): reads only the feature flags, the accepted terms, the
 * monthly quota and the AI configuration. Never builds the gateway, the
 * tool rate limiter (Redis) nor the tool registry, so the "Copiloto" link
 * does not depend on Redis and costs a few queries per render.
 *
 * Same rule as AiCopilotService::availability().
 */
final class AiCopilotAvailability
{
    /** @var Closure(): bool */
    private readonly Closure $configured;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param Closure(): bool $configured whether the AI provider is configured
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AiTenantSettingRepositoryInterface $settings,
        private readonly AiInteractionRepositoryInterface $interactions,
        Closure $configured,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->configured = $configured;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    public static function forContext(TenantContext $context, PDO $connection): self
    {
        return new self(
            new FeatureFlagRepository($context, $connection),
            new AiTenantSettingRepository($context, $connection),
            new AiInteractionRepository($context, $connection),
            static function (): bool {
                try {
                    $config = AiConfig::fromEnvironment();
                } catch (Throwable $e) {
                    error_log('AiCopilotAvailability: invalid AI configuration (' . $e::class . ')');

                    return false;
                }

                // Same rule as AnthropicMessagesGateway::isConfigured (the stub is always configured).
                return $config->provider() === 'stub'
                    || ($config->provider() === 'anthropic' && $config->apiKey() !== null);
            },
            $context,
        );
    }

    /**
     * `ai_assistant` (and `ai_copilot_management` for management) →
     * `ai_disabled`; terms of AiTerms::VERSION not accepted →
     * `ai_terms_pending`; monthly quota reached (application timezone) →
     * `ai_quota_exceeded`; provider not configured → `ai_not_configured`;
     * null when available.
     *
     * @throws InvalidArgumentException for an unknown family.
     */
    public function reason(string $family): ?string
    {
        if (!in_array($family, ToolFamily::ALL, true)) {
            throw new InvalidArgumentException('Unknown copilot family');
        }

        if (!$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT)) {
            return 'ai_disabled';
        }

        if ($family === ToolFamily::MANAGEMENT && !$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_COPILOT_MANAGEMENT)) {
            return 'ai_disabled';
        }

        $setting = $this->settings->find() ?? AiTenantSetting::defaults($this->context->tenantId());

        if (!$setting->hasAcceptedTerms(AiTerms::VERSION)) {
            return 'ai_terms_pending';
        }

        if ($this->interactions->monthlyTokenUsage($this->monthStart()) >= $setting->monthlyTokenQuota()) {
            return 'ai_quota_exceeded';
        }

        if (!($this->configured)()) {
            return 'ai_not_configured';
        }

        return null;
    }

    /** First instant of the current month in the application timezone (as AiCopilotService). */
    private function monthStart(): DateTimeImmutable
    {
        return ($this->clock)()
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->modify('first day of this month')
            ->setTime(0, 0);
    }
}

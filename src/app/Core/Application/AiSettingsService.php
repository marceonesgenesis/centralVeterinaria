<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Audit\AuditEvent;
use CentralVet\Audit\Contract\AuditLogWriterInterface;
use CentralVet\Audit\PdoAuditLogWriter;
use CentralVet\Authorization\AdiantiProgramPermissionProvider;
use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Authorization\RbacAuthorizationService;
use CentralVet\Domain\AiInteraction;
use CentralVet\Domain\AiTenantSetting;
use CentralVet\Domain\AiTerms;
use CentralVet\Domain\Contract\AiInteractionRepositoryInterface;
use CentralVet\Domain\Contract\AiTenantSettingRepositoryInterface;
use CentralVet\Domain\Contract\FeatureFlagRepositoryInterface;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Observability\CorrelationId\CorrelationIdContext;
use CentralVet\Persistence\AiInteractionRepository;
use CentralVet\Persistence\AiTenantSettingRepository;
use CentralVet\Persistence\FeatureFlagRepository;
use CentralVet\Tenancy\AdiantiSessionContextSource;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Tenant admin settings of the AI assistant (T-12, Fase 8A): turn it on
 * (accepting the current AiTerms::VERSION when not yet accepted) or off,
 * change the monthly token quota and read the month usage and cost by
 * purpose. Settings belong to the tenant, so authorization never requires a
 * unit scope. Each effective change is audited (`ai.terms.accepted`,
 * `ai.settings.enabled`, `ai.settings.disabled`,
 * `ai.settings.quota_changed`) with ids and numbers only; a call that
 * changes nothing (e.g. disable() on a flag already off) writes nothing.
 * No transaction is opened: the controller owns it.
 */
final class AiSettingsService
{
    public const ACTION_OVERVIEW = 'AiSettingsForm::onReload';
    public const ACTION_SAVE = 'AiSettingsForm::onSave';
    public const ACTION_INTERACTIONS = 'AiInteractionList::onReload';
    public const MAX_MONTHLY_TOKEN_QUOTA = 100000000;
    public const MAX_RECENT_LIMIT = 200;

    private const ENTITY_TYPE = 'ai_tenant_setting';

    private readonly Closure $clock;

    public function __construct(
        private readonly AiTenantSettingRepositoryInterface $settings,
        private readonly FeatureFlagRepositoryInterface $flags,
        private readonly AiInteractionRepositoryInterface $interactions,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly AuditLogWriterInterface $audit,
        private readonly TenantContext $context,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Wires the PDO repositories, RBAC and audit writer on `$connection` (an open 'permission' transaction). */
    public static function forContext(TenantContext $context, PDO $connection): self
    {
        $audit = new PdoAuditLogWriter($connection);

        return new self(
            new AiTenantSettingRepository($context, $connection),
            new FeatureFlagRepository($context, $connection),
            new AiInteractionRepository($context, $connection),
            new RbacAuthorizationService(
                new AdiantiProgramPermissionProvider(new AdiantiSessionContextSource()),
                $audit,
            ),
            $audit,
            $context,
        );
    }

    /**
     * @return array{enabled: bool, terms_version: ?string, terms_accepted_at: ?DateTimeImmutable, terms_current: bool, monthly_token_quota: int, month_tokens: int, month_cost_micro_usd: int, by_purpose: list<array{purpose: string, requests: int, tokens: int, cost_micro_usd: int}>}
     *
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function overview(): array
    {
        $this->authorize(self::ACTION_OVERVIEW);

        $setting = $this->setting();
        $monthStart = $this->monthStart();
        $byPurpose = array_values($this->interactions->monthlySummary($monthStart));
        $cost = 0;

        foreach ($byPurpose as $row) {
            $cost += (int) $row['cost_micro_usd'];
        }

        return [
            'enabled' => $this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT),
            'terms_version' => $setting->termsVersion(),
            'terms_accepted_at' => $setting->termsAcceptedAt(),
            'terms_current' => $setting->hasAcceptedTerms(AiTerms::VERSION),
            'monthly_token_quota' => $setting->monthlyTokenQuota(),
            'month_tokens' => $this->interactions->monthlyTokenUsage($monthStart),
            'month_cost_micro_usd' => $cost,
            'by_purpose' => $byPurpose,
        ];
    }

    /**
     * Turns the assistant on. With `$acceptTerms` the current term is
     * accepted first (when not yet accepted).
     *
     * @throws AiUnavailableException `ai_terms_pending` when the current term is not accepted (flag stays off).
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied (nothing is written)
     */
    public function enable(bool $acceptTerms): void
    {
        $this->authorize(self::ACTION_SAVE);

        $setting = $this->setting();

        if (!$setting->hasAcceptedTerms(AiTerms::VERSION)) {
            if (!$acceptTerms) {
                throw new AiUnavailableException('ai_terms_pending');
            }

            $before = $setting->termsVersion();
            $setting->acceptTerms(AiTerms::VERSION, $this->context->userId(), ($this->clock)());
            $this->settings->save($setting, $this->context->userId());
            $this->record('ai.terms.accepted', ['terms_version' => $before], ['terms_version' => AiTerms::VERSION]);
        }

        if (!$this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT)) {
            $this->flags->set(FeatureFlagRepositoryInterface::AI_ASSISTANT, true, $this->context->userId());
            $this->record('ai.settings.enabled', ['enabled' => false], ['enabled' => true]);
        }
    }

    /**
     * Turns the assistant off; the accepted term stays recorded.
     *
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function disable(): void
    {
        $this->authorize(self::ACTION_SAVE);

        if ($this->flags->isEnabled(FeatureFlagRepositoryInterface::AI_ASSISTANT)) {
            $this->flags->set(FeatureFlagRepositoryInterface::AI_ASSISTANT, false, $this->context->userId());
            $this->record('ai.settings.disabled', ['enabled' => true], ['enabled' => false]);
        }
    }

    /**
     * @throws InvalidArgumentException `Invalid monthly token quota` outside 0..100000000.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function changeMonthlyQuota(int $tokens): void
    {
        $this->authorize(self::ACTION_SAVE);

        if ($tokens < 0 || $tokens > self::MAX_MONTHLY_TOKEN_QUOTA) {
            throw new InvalidArgumentException('Invalid monthly token quota');
        }

        $stored = $this->settings->find();
        $setting = $stored ?? AiTenantSetting::defaults($this->context->tenantId());
        $before = $setting->monthlyTokenQuota();

        if ($before === $tokens && $stored !== null) {
            return;
        }

        $setting->changeQuota($tokens);
        $this->settings->save($setting, $this->context->userId());
        $this->record('ai.settings.quota_changed', ['monthly_token_quota' => $before], ['monthly_token_quota' => $tokens]);
    }

    /**
     * Most recent interactions of the tenant (limit clamped to 1..200).
     *
     * @return list<AiInteraction>
     *
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function recentInteractions(int $limit = 50): array
    {
        $this->authorize(self::ACTION_INTERACTIONS);

        return array_values($this->interactions->listRecent(max(1, min(self::MAX_RECENT_LIMIT, $limit))));
    }

    private function setting(): AiTenantSetting
    {
        return $this->settings->find() ?? AiTenantSetting::defaults($this->context->tenantId());
    }

    /**
     * First day of the current month at 00:00 in the application timezone
     * (`date_default_timezone_get()`, i.e. APP_TIMEZONE), the same cut used by
     * AiAssistService for the quota; assumes the MySQL session runs in it.
     */
    private function monthStart(): DateTimeImmutable
    {
        return ($this->clock)()
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->modify('first day of this month')
            ->setTime(0, 0);
    }

    private function authorize(string $action): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: false,
            resourceTenantId: $this->context->tenantId(),
            entityType: self::ENTITY_TYPE,
            entityId: $this->context->tenantId(),
        ))->assertAllowed();
    }

    /**
     * @param array<string, scalar|null> $before
     * @param array<string, scalar|null> $after
     */
    private function record(string $action, array $before, array $after): void
    {
        $this->audit->record(new AuditEvent(
            tenantId: $this->context->tenantId(),
            unitId: $this->context->unitId(),
            userId: $this->context->userId(),
            correlationId: CorrelationIdContext::current() ?? CorrelationIdContext::start(),
            action: $action,
            entityType: self::ENTITY_TYPE,
            entityId: $this->context->tenantId(),
            beforeData: $before,
            afterData: $after,
            metadata: [],
            ipAddress: null,
            userAgent: null,
        ));
    }
}

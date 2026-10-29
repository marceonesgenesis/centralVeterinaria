<?php

declare(strict_types=1);

namespace CentralVet\Onboarding;

use CentralVet\Tenancy\TenantContext;
use DateTimeImmutable;
use PDO;

/**
 * Checklist de primeiros passos (Etapa 5, T-09) do tenant da sessão.
 * Os itens derivam dos dados (equipe, serviços com preço) e das marcas em
 * tenant_onboarding; toda leitura e escrita filtra por $context->tenantId().
 */
final class OnboardingChecklistService
{
    public const ITEM_CLINIC = 'clinic_profile';
    public const ITEM_TEAM = 'team';
    public const ITEM_SERVICES = 'services';
    public const ITEM_GUIDE = 'first_encounter_guide';
    public const ITEMS = [self::ITEM_CLINIC, self::ITEM_TEAM, self::ITEM_SERVICES, self::ITEM_GUIDE];

    private const TEAM_MIN_MEMBERS = 2;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantContext $context,
    ) {
    }

    /** @return list<array{item: string, done: bool}> */
    public function status(): array
    {
        $tenantId = $this->context->tenantId();
        $marks = $this->marks($tenantId);

        $done = [
            self::ITEM_CLINIC => $marks['clinic_confirmed_at'] !== null,
            self::ITEM_TEAM => $marks['team_reviewed_at'] !== null || $this->teamSize($tenantId) >= self::TEAM_MIN_MEMBERS,
            self::ITEM_SERVICES => $this->hasPricedService($tenantId),
            self::ITEM_GUIDE => $marks['guide_read_at'] !== null,
        ];

        return array_map(static fn (string $item): array => ['item' => $item, 'done' => $done[$item]], self::ITEMS);
    }

    public function isComplete(): bool
    {
        foreach ($this->status() as $row) {
            if (!$row['done']) {
                return false;
            }
        }

        return true;
    }

    public function markTeamReviewed(DateTimeImmutable $now): void
    {
        self::mark($this->pdo, $this->context->tenantId(), 'team_reviewed_at', $now);
    }

    public function markGuideRead(DateTimeImmutable $now): void
    {
        self::mark($this->pdo, $this->context->tenantId(), 'guide_read_at', $now);
    }

    /**
     * Upsert da linha do tenant em tenant_onboarding com a marca informada.
     *
     * @param 'clinic_confirmed_at'|'team_reviewed_at'|'guide_read_at' $column
     */
    public static function mark(PDO $pdo, int $tenantId, string $column, DateTimeImmutable $now): void
    {
        if (!in_array($column, ['clinic_confirmed_at', 'team_reviewed_at', 'guide_read_at'], true)) {
            throw new \InvalidArgumentException('Unknown onboarding mark');
        }

        $at = $now->format('Y-m-d H:i:s.u');
        $pdo->prepare(sprintf(
            'INSERT INTO tenant_onboarding (tenant_id, %1$s) VALUES (?, ?) ON DUPLICATE KEY UPDATE %1$s = ?',
            $column,
        ))->execute([$tenantId, $at, $at]);
    }

    /** @return array{clinic_confirmed_at: ?string, team_reviewed_at: ?string, guide_read_at: ?string} */
    private function marks(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT clinic_confirmed_at, team_reviewed_at, guide_read_at FROM tenant_onboarding WHERE tenant_id = ?',
        );
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'clinic_confirmed_at' => $row['clinic_confirmed_at'] ?? null,
            'team_reviewed_at' => $row['team_reviewed_at'] ?? null,
            'guide_read_at' => $row['guide_read_at'] ?? null,
        ];
    }

    private function teamSize(int $tenantId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM tenant_user WHERE tenant_id = ? AND status IN ('active', 'invited')",
        );
        $statement->execute([$tenantId]);

        return (int) $statement->fetchColumn();
    }

    private function hasPricedService(int $tenantId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM service WHERE tenant_id = ? AND active = 1 AND price_cents > 0 LIMIT 1',
        );
        $statement->execute([$tenantId]);

        return $statement->fetchColumn() !== false;
    }
}

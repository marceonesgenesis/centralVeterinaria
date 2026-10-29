<?php

declare(strict_types=1);

namespace CentralVet\Billing;

use CentralVet\Landing\LandingCatalog;
use InvalidArgumentException;

/**
 * Direitos de um plano no servidor: quotas (usuários, unidades), recursos e
 * programas (controllers) liberados. Nome e preço vêm de
 * LandingCatalog::plan(), a fonte única de preço; quotas e recursos foram
 * confirmados pelo usuário (notes.md, T-07 onda 1).
 *
 * Controller fora de gatedPrograms() é liberado em todos os planos (caixa,
 * recebimento e contas de atendimento incluídos: o PaymentService exige
 * caixa aberto).
 */
final class PlanEntitlements
{
    public const PLAN_IDS = ['starter', 'pro', 'business', 'enterprise'];

    public const FEATURE_INVENTORY_POS = 'inventory_pos';
    public const FEATURE_FINANCE_FULL = 'finance_full';
    public const FEATURE_COMMUNICATION = 'communication';
    public const FEATURE_MULTI_UNIT = 'multi_unit';
    public const FEATURE_HOSPITAL = 'hospital';
    public const FEATURE_AI = 'ai_advanced';

    /** @var array<string, array{users: ?int, units: ?int, features: list<string>}> */
    private const PLANS = [
        'starter' => ['users' => 2, 'units' => 1, 'features' => []],
        'pro' => ['users' => 10, 'units' => 1, 'features' => [
            self::FEATURE_INVENTORY_POS, self::FEATURE_FINANCE_FULL, self::FEATURE_COMMUNICATION,
        ]],
        'business' => ['users' => 30, 'units' => 5, 'features' => [
            self::FEATURE_INVENTORY_POS, self::FEATURE_FINANCE_FULL, self::FEATURE_COMMUNICATION,
            self::FEATURE_MULTI_UNIT,
        ]],
        'enterprise' => ['users' => null, 'units' => null, 'features' => [
            self::FEATURE_INVENTORY_POS, self::FEATURE_FINANCE_FULL, self::FEATURE_COMMUNICATION,
            self::FEATURE_MULTI_UNIT, self::FEATURE_HOSPITAL, self::FEATURE_AI,
        ]],
    ];

    /** @var array<string, list<string>> feature → controllers */
    private const GATED = [
        self::FEATURE_INVENTORY_POS => [
            'ProductForm', 'ProductList', 'StockBatchForm', 'SaleForm', 'SaleList', 'SaleCancelForm',
        ],
        self::FEATURE_FINANCE_FULL => [
            'PayableForm', 'PayableList', 'BankAccountForm', 'BankAccountList',
            'FinancialEntryForm', 'FinancialEntryList', 'FinancialOverview',
        ],
        self::FEATURE_COMMUNICATION => [
            'CommunicationComposeForm', 'CommunicationMessageList', 'CommunicationMessageView',
            'MessageTemplateForm', 'MessageTemplateList', 'TutorCommunicationForm',
        ],
        self::FEATURE_MULTI_UNIT => ['SystemUnitForm', 'SystemUnitList'],
        // Controllers Hospitalization*, Surgery* e Bed* do seed de permissões.
        self::FEATURE_HOSPITAL => [
            'BedForm', 'BedList',
            'HospitalizationAdministrationForm', 'HospitalizationAdmissionForm', 'HospitalizationBoard',
            'HospitalizationEventForm', 'HospitalizationOrderForm', 'HospitalizationView',
            'SurgeryChecklistForm', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryList',
            'SurgeryMaterialForm', 'SurgeryRoomForm', 'SurgeryRoomList', 'SurgeryScheduleForm', 'SurgeryView',
        ],
        self::FEATURE_AI => [
            'AiConversationList', 'AiCopilotPanel', 'AiInteractionList', 'AiSettingsForm', 'AiToolCallList',
        ],
    ];

    /** @param list<string> $features */
    private function __construct(
        private readonly string $planId,
        private readonly string $name,
        private readonly int $priceCents,
        private readonly ?int $maxUsers,
        private readonly ?int $maxUnits,
        private readonly array $features,
    ) {
    }

    public static function forPlan(string $planId): self
    {
        $plan = isset(self::PLANS[$planId]) ? LandingCatalog::plan($planId) : null;
        if ($plan === null) {
            throw new InvalidArgumentException(sprintf('Unknown plan "%s"', $planId));
        }

        $rules = self::PLANS[$planId];

        return new self(
            $planId,
            $plan['name'],
            $plan['price_cents'],
            $rules['users'],
            $rules['units'],
            $rules['features'],
        );
    }

    /** @return array<string, string> controller → feature */
    public static function gatedPrograms(): array
    {
        $map = [];
        foreach (self::GATED as $feature => $controllers) {
            foreach ($controllers as $controller) {
                $map[$controller] = $feature;
            }
        }

        return $map;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    /** Null = sem limite. */
    public function maxUsers(): ?int
    {
        return $this->maxUsers;
    }

    /** Null = sem limite. */
    public function maxUnits(): ?int
    {
        return $this->maxUnits;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->features;
    }

    public function allowsProgram(string $controller): bool
    {
        $feature = self::gatedPrograms()[$controller] ?? null;

        return $feature === null || $this->hasFeature($feature);
    }
}

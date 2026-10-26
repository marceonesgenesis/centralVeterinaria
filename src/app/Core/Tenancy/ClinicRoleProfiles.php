<?php

declare(strict_types=1);

namespace CentralVet\Tenancy;

use InvalidArgumentException;

/**
 * Etapa 5, T-04: perfis restritos fixos da clínica (Recepção, Veterinário e
 * Financeiro), além do administrador, que fica no grupo do plano. Cada perfil
 * é um system_group global criado por role-groups.sql com exatamente os
 * programas de programs(). O perfil só restringe: o plano continua limitando
 * pela guarda (SubscriptionAccessGuard). Nenhum perfil alcança gestão da
 * clínica (equipe, unidades, assinatura, exportação, onboarding) nem
 * administração global do sistema; só o autoatendimento SystemProfile*.
 */
final class ClinicRoleProfiles
{
    public const RECEPTION = 'reception';
    public const VETERINARIAN = 'veterinarian';
    public const FINANCE = 'finance';

    public const GROUP_NAMES = [
        self::RECEPTION => 'Central Vet - Perfil Recepção',
        self::VETERINARIAN => 'Central Vet - Perfil Veterinário',
        self::FINANCE => 'Central Vet - Perfil Financeiro',
    ];

    /** Shell, busca, pendências, suporte, guia do 1º atendimento e autoatendimento (perfil, senha, 2FA). */
    private const COMMON = [
        'CvShellController', 'GlobalSearchController', 'PendingCenter', 'SupportView',
        'FirstEncounterGuideView', 'SystemProfileView', 'SystemProfileForm', 'SystemProfile2FAForm',
    ];

    private const SPECIFIC = [
        self::RECEPTION => [
            'AgendaView', 'AppointmentForm', 'TutorList', 'TutorForm', 'PatientList', 'PatientForm',
            'QueueEntryView', 'VaccinationCardView', 'DocumentList',
        ],
        self::VETERINARIAN => [
            'AgendaView', 'QueueEntryView', 'EncounterView', 'TutorList', 'PatientList', 'PatientForm',
            'PrescriptionForm', 'ExamRequestForm', 'ExamResultForm', 'PendingExamResultList',
            'VaccinationForm', 'VaccinationCardView', 'ProcedureExecutionForm', 'ProcedureInputForm',
            'DocumentList', 'DocumentRequestForm',
        ],
        self::FINANCE => [
            'EncounterAccountForm', 'PaymentForm', 'CashSessionForm', 'CashSessionList',
            'PendingReceivableList', 'FinancialOverview', 'FinancialEntryForm', 'FinancialEntryList',
            'PayableForm', 'PayableList', 'BankAccountForm', 'BankAccountList', 'SaleList',
            'TutorList', 'ServiceList',
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::GROUP_NAMES);
    }

    /** @return list<string> */
    public static function groupNames(): array
    {
        return array_values(self::GROUP_NAMES);
    }

    /**
     * @return list<string> controllers do perfil, ordenados e sem repetição
     * @throws InvalidArgumentException papel desconhecido (inclusive 'admin')
     */
    public static function programs(string $role): array
    {
        if (!isset(self::SPECIFIC[$role])) {
            throw new InvalidArgumentException('Unknown clinic role: ' . $role);
        }

        $programs = array_values(array_unique(array_merge(self::COMMON, self::SPECIFIC[$role])));
        sort($programs);

        return $programs;
    }

    public static function roleOfGroup(string $groupName): ?string
    {
        $role = array_search($groupName, self::GROUP_NAMES, true);

        return is_string($role) ? $role : null;
    }
}

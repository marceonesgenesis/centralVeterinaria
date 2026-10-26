<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Tenancy\ClinicRoleProfiles;
use CentralVet\Tests\Support\Assert;

/**
 * T-04 (Etapa 5): três perfis restritos fixos (Recepção, Veterinário,
 * Financeiro) além do administrador (grupo do plano). O perfil só restringe;
 * o plano continua limitado pela guarda. Nenhum perfil alcança gestão da
 * clínica (equipe, unidades, assinatura, exportação, onboarding) nem
 * administração global do sistema.
 */
final class ClinicRoleProfilesTest
{
    private const COMMON = [
        'CvShellController', 'GlobalSearchController', 'PendingCenter', 'SupportView',
        'FirstEncounterGuideView', 'SystemProfileView', 'SystemProfileForm', 'SystemProfile2FAForm',
    ];

    private const ADMIN_ONLY = [
        'ClinicUserForm', 'ClinicUserList', 'ClinicUnitForm', 'ClinicUnitList',
        'SubscriptionStatusView', 'SubscriptionCancelForm', 'ClinicProfileForm',
        'ClinicDataExportView', 'OnboardingChecklistView', 'LandingLeadList',
    ];

    private const SELF_SERVICE = ['SystemProfileView', 'SystemProfileForm', 'SystemProfile2FAForm'];

    public function testRolesAndGroupNames(): void
    {
        Assert::same('reception', ClinicRoleProfiles::RECEPTION);
        Assert::same('veterinarian', ClinicRoleProfiles::VETERINARIAN);
        Assert::same('finance', ClinicRoleProfiles::FINANCE);
        Assert::same(['reception', 'veterinarian', 'finance'], ClinicRoleProfiles::roles());
        Assert::same([
            'reception' => 'Central Vet - Perfil Recepção',
            'veterinarian' => 'Central Vet - Perfil Veterinário',
            'finance' => 'Central Vet - Perfil Financeiro',
        ], ClinicRoleProfiles::GROUP_NAMES);
        Assert::same(
            ['Central Vet - Perfil Recepção', 'Central Vet - Perfil Veterinário', 'Central Vet - Perfil Financeiro'],
            ClinicRoleProfiles::groupNames(),
        );
    }

    public function testRoleOfGroup(): void
    {
        Assert::same('reception', ClinicRoleProfiles::roleOfGroup('Central Vet - Perfil Recepção'));
        Assert::same('veterinarian', ClinicRoleProfiles::roleOfGroup('Central Vet - Perfil Veterinário'));
        Assert::same('finance', ClinicRoleProfiles::roleOfGroup('Central Vet - Perfil Financeiro'));
        Assert::null(ClinicRoleProfiles::roleOfGroup('Central Vet - Plano Pro'));
        Assert::null(ClinicRoleProfiles::roleOfGroup('Admin'));
    }

    public function testEveryProfileHasTheCommonPrograms(): void
    {
        foreach (ClinicRoleProfiles::roles() as $role) {
            $programs = ClinicRoleProfiles::programs($role);
            foreach (self::COMMON as $controller) {
                Assert::true(in_array($controller, $programs, true), $role . ' must have ' . $controller);
            }
        }
    }

    public function testProgramsAreASortedListWithoutDuplicates(): void
    {
        foreach (ClinicRoleProfiles::roles() as $role) {
            $programs = ClinicRoleProfiles::programs($role);
            $sorted = array_values(array_unique($programs));
            sort($sorted);
            Assert::same($sorted, $programs, $role . ' programs sorted and unique');
        }
    }

    public function testReceptionProfile(): void
    {
        $programs = ClinicRoleProfiles::programs(ClinicRoleProfiles::RECEPTION);
        $expected = array_merge(self::COMMON, [
            'AgendaView', 'AppointmentForm', 'TutorList', 'TutorForm', 'PatientList', 'PatientForm',
            'QueueEntryView', 'VaccinationCardView', 'DocumentList',
        ]);
        sort($expected);
        Assert::same($expected, $programs);
        Assert::true(in_array('AppointmentForm', $programs, true));
        foreach (['PrescriptionForm', 'PaymentForm', 'FinancialOverview'] as $denied) {
            Assert::false(in_array($denied, $programs, true), 'reception must not have ' . $denied);
        }
    }

    public function testVeterinarianProfile(): void
    {
        $programs = ClinicRoleProfiles::programs(ClinicRoleProfiles::VETERINARIAN);
        $expected = array_merge(self::COMMON, [
            'AgendaView', 'QueueEntryView', 'EncounterView', 'TutorList', 'PatientList', 'PatientForm',
            'PrescriptionForm', 'ExamRequestForm', 'ExamResultForm', 'PendingExamResultList',
            'VaccinationForm', 'VaccinationCardView', 'ProcedureExecutionForm', 'ProcedureInputForm',
            'DocumentList', 'DocumentRequestForm',
        ]);
        sort($expected);
        Assert::same($expected, $programs);
        foreach (['PaymentForm', 'CashSessionForm'] as $denied) {
            Assert::false(in_array($denied, $programs, true), 'veterinarian must not have ' . $denied);
        }
    }

    public function testFinanceProfile(): void
    {
        $programs = ClinicRoleProfiles::programs(ClinicRoleProfiles::FINANCE);
        $expected = array_merge(self::COMMON, [
            'EncounterAccountForm', 'PaymentForm', 'CashSessionForm', 'CashSessionList',
            'PendingReceivableList', 'FinancialOverview', 'FinancialEntryForm', 'FinancialEntryList',
            'PayableForm', 'PayableList', 'BankAccountForm', 'BankAccountList', 'SaleList',
            'TutorList', 'ServiceList',
        ]);
        sort($expected);
        Assert::same($expected, $programs);
        foreach (['PrescriptionForm', 'AppointmentForm'] as $denied) {
            Assert::false(in_array($denied, $programs, true), 'finance must not have ' . $denied);
        }
    }

    public function testNoProfileReachesClinicAdministrationOrSystemPrograms(): void
    {
        foreach (ClinicRoleProfiles::roles() as $role) {
            foreach (ClinicRoleProfiles::programs($role) as $controller) {
                Assert::false(in_array($controller, self::ADMIN_ONLY, true), $role . ' must not have ' . $controller);
                Assert::false(
                    str_starts_with($controller, 'System') && !in_array($controller, self::SELF_SERVICE, true),
                    $role . ' must not have global system program ' . $controller,
                );
            }
        }
    }

    public function testUnknownRoleThrows(): void
    {
        Assert::throws(\InvalidArgumentException::class, static fn () => ClinicRoleProfiles::programs('admin'));
        Assert::throws(\InvalidArgumentException::class, static fn () => ClinicRoleProfiles::programs(''));
    }
}

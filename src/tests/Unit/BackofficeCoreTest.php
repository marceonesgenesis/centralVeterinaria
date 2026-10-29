<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Backoffice\BackofficeProfile;
use CentralVet\Backoffice\BackofficeInviteService;
use CentralVet\Tests\Support\Assert;

final class BackofficeCoreTest
{
    public function testBackofficeProfilePermissionsMatrix(): void
    {
        // Admin pode tudo
        Assert::true(BackofficeProfile::can(BackofficeProfile::ROLE_ADMIN, 'clients_view'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_ADMIN, 'clients_manage'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_ADMIN, 'plans_manage'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_ADMIN, 'team_users'));

        // Comercial pode emitir convites e ver marketing, mas não gerencia planos nem equipe
        Assert::true(BackofficeProfile::can(BackofficeProfile::ROLE_COMMERCIAL, 'clients_view'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_COMMERCIAL, 'clients_manage'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_COMMERCIAL, 'invites_create'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_COMMERCIAL, 'marketing_campaigns'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_COMMERCIAL, 'team_users'));

        // Financeiro pode gerenciar clientes e ver faturas, mas não envia campanhas nem edita planos
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_FINANCIAL, 'clients_manage'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_FINANCIAL, 'billing_manage'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_FINANCIAL, 'marketing_campaigns'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_FINANCIAL, 'invites_create'));

        // Suporte pode consultar faturas e reenviar convites, além de horários
        Assert::true(BackofficeProfile::can(BackofficeProfile::ROLE_SUPPORT, 'clients_view'));
        Assert::true(BackofficeProfile::can(BackofficeProfile::ROLE_SUPPORT, 'billing_manage'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_SUPPORT, 'billing_manage'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_SUPPORT, 'invites_resend'));
        Assert::true(BackofficeProfile::canMutate(BackofficeProfile::ROLE_SUPPORT, 'support_hours'));
        Assert::false(BackofficeProfile::canMutate(BackofficeProfile::ROLE_SUPPORT, 'plans_manage'));
    }

    public function testInviteDefaultConstants(): void
    {
        Assert::same(15, BackofficeInviteService::DEFAULT_TRIAL_DAYS);
        Assert::same(7, BackofficeInviteService::DEFAULT_INVITE_VALIDITY_DAYS);
    }
}

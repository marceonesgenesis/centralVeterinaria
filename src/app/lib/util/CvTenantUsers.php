<?php

use Adianti\Database\TCriteria;
use Adianti\Database\TFilter;
use Adianti\Widget\Wrapper\TDBCombo;

/**
 * Filtro único dos combos de usuário (SystemUser) pelo tenant da sessão
 * (final-fix). Extraído de EncounterAccountForm::tenantUsersCriteria():
 * usuários ativos vinculados ao tenant (tenant_user); sem tenant
 * resolvido, nenhum usuário (fail-closed).
 *
 * O tenant é resolvido pelo callable do controller (cada um tem o seu
 * resolveTenantContext() privado); qualquer Exception vira tenant 0.
 * O serviço de Application revalida no save (TenantUserDirectory).
 */
class CvTenantUsers
{
    /**
     * @param callable(): \CentralVet\Tenancy\TenantContext $resolveTenantContext
     */
    public static function criteria(callable $resolveTenantContext): TCriteria
    {
        $tenantId = 0;
        try
        {
            $tenantId = (int) $resolveTenantContext()->tenantId();
        }
        catch (Exception $e)
        {
            $tenantId = 0;
        }

        $criteria = new TCriteria;
        $criteria->add(new TFilter('active', '=', 'Y'));
        $criteria->add(new TFilter('id', 'IN', 'NOESC:(SELECT system_user_id FROM tenant_user WHERE tenant_id = ' . $tenantId . ')'));

        return $criteria;
    }

    /**
     * TDBCombo de SystemUser (id => name, ordenado por name) restrito ao
     * tenant da sessão.
     *
     * @param callable(): \CentralVet\Tenancy\TenantContext $resolveTenantContext
     */
    public static function combo(string $name, callable $resolveTenantContext): TDBCombo
    {
        return new TDBCombo($name, 'permission', 'SystemUser', 'id', 'name', 'name', self::criteria($resolveTenantContext));
    }
}

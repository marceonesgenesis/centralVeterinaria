<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use InvalidArgumentException;

/**
 * Perfis e Matriz de Autorização da Equipe de Gestão da Plataforma (Back-office)
 * Conforme especificado na seção 2.8 de pendencias-mvp.md
 */
final class BackofficeProfile
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_COMMERCIAL = 'commercial';
    public const ROLE_FINANCIAL = 'financial';
    public const ROLE_SUPPORT = 'support';

    public const ROLES = [
        self::ROLE_ADMIN => 'Administrador do Sistema',
        self::ROLE_COMMERCIAL => 'Comercial',
        self::ROLE_FINANCIAL => 'Financeiro',
        self::ROLE_SUPPORT => 'Suporte',
    ];

    /**
     * Matriz de permissões por ação e perfil.
     * Valores: true = permitido; false = negado; 'read_only' = apenas consulta.
     */
    private const PERMISSIONS = [
        'clients_view' => [
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => true,
            self::ROLE_FINANCIAL => true,
            self::ROLE_SUPPORT => true,
        ],
        'clients_manage' => [ // suspender, reativar, trocar plano, prorrogar
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => true,
            self::ROLE_SUPPORT => false,
        ],
        'billing_manage' => [ // faturas, estornos e conciliação Stripe
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => true,
            self::ROLE_SUPPORT => 'read_only',
        ],
        'plans_manage' => [ // cadastro de planos e ofertas
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => 'read_only',
            self::ROLE_FINANCIAL => 'read_only',
            self::ROLE_SUPPORT => false,
        ],
        'invites_create' => [ // convites com bônus: enviar, cancelar
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => true,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => false,
        ],
        'invites_resend' => [ // convites: reenviar
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => true,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => true,
        ],
        'invites_config' => [ // prazo padrão do bônus e validade
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => false,
        ],
        'marketing_view' => [ // painel de leads e exportação CSV
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => true,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => false,
        ],
        'marketing_campaigns' => [ // enviar campanhas de e-mail
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => true,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => false,
        ],
        'support_hours' => [ // horários de atendimento do suporte
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => true,
        ],
        'team_users' => [ // gerenciar usuários e perfis da equipe de gestão
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => false,
            self::ROLE_SUPPORT => false,
        ],
        'audit_view' => [ // trilha de auditoria da gestão
            self::ROLE_ADMIN => true,
            self::ROLE_COMMERCIAL => false,
            self::ROLE_FINANCIAL => 'read_only',
            self::ROLE_SUPPORT => false,
        ],
    ];

    public static function can(string $role, string $action): bool
    {
        if (!isset(self::ROLES[$role])) {
            return false;
        }

        $allowed = self::PERMISSIONS[$action][$role] ?? false;
        return $allowed === true || $allowed === 'read_only';
    }

    public static function canMutate(string $role, string $action): bool
    {
        if (!isset(self::ROLES[$role])) {
            return false;
        }

        return (self::PERMISSIONS[$action][$role] ?? false) === true;
    }

    public static function getLabel(string $role): string
    {
        return self::ROLES[$role] ?? 'Desconhecido';
    }
}


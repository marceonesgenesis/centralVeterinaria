<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeProfile;
use CentralVet\Persistence\PdoConnectionFactory;

/**
 * GestaoTeamList — Gestão dos Membros e Perfis da Equipe de Gestão
 */
class GestaoTeamList extends TPage
{
    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Equipe de Gestão da Plataforma</h3>
                <small class="text-muted">Operadores internos com os 4 perfis fixos: Administrador, Comercial, Financeiro e Suporte (2FA Obrigatório)</small>
            </div>
        </div>');

        $pdo = PdoConnectionFactory::create();
        $stmt = $pdo->query('
            SELECT u.id, u.name, u.email, u.login, u.active, u.otp_secret, p.profile_role 
            FROM system_user u 
            LEFT JOIN backoffice_user_profile p ON p.user_id = u.id 
            WHERE u.tenant_id IS NULL 
            ORDER BY u.id ASC
        ');
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rows = '';
        foreach ($users as $u) {
            $roleLabel = BackofficeProfile::getLabel((string)($u['profile_role'] ?? 'admin'));
            $roleBadge = match ($u['profile_role'] ?? 'admin') {
                'admin' => '<span class="badge bg-danger">' . $roleLabel . '</span>',
                'commercial' => '<span class="badge bg-primary">' . $roleLabel . '</span>',
                'financial' => '<span class="badge bg-success">' . $roleLabel . '</span>',
                'support' => '<span class="badge bg-info text-dark">' . $roleLabel . '</span>',
                default => '<span class="badge bg-secondary">' . $roleLabel . '</span>',
            };

            $has2fa = !empty($u['otp_secret']) ? '<span class="badge bg-success">Ativo (Obrigatório)</span>' : '<span class="badge bg-warning text-dark">Pendente de Ativação</span>';

            $name = htmlspecialchars((string)$u['name']);
            $email = htmlspecialchars((string)$u['email']);
            $login = htmlspecialchars((string)$u['login']);

            $rows .= "
                <tr>
                    <td><strong>{$name}</strong><br><small class='text-muted'>Login: {$login}</small></td>
                    <td>{$email}</td>
                    <td>{$roleBadge}</td>
                    <td>{$has2fa}</td>
                    <td><span class='badge bg-light text-dark'>Acesso Interno</span></td>
                </tr>
            ";
        }

        $tableHtml = new TLabel("
            <div class='card border-0 shadow-sm p-4'>
                <h5 class='fw-bold mb-3'>Operadores Internos Ativos</h5>
                <div class='table-responsive'>
                    <table class='table align-middle'>
                        <thead class='table-light'>
                            <tr>
                                <th>Nome / Usuário</th>
                                <th>E-mail</th>
                                <th>Perfil Interno</th>
                                <th>2FA (TOTP)</th>
                                <th>Escopo</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rows}
                        </tbody>
                    </table>
                </div>
            </div>
        ");

        $container->add($header);
        $container->add($tableHtml);

        parent::add($container);
    }
}


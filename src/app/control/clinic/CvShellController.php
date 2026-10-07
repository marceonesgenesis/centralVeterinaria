<?php

use CentralVet\Security\CsrfToken;

/**
 * CvShellController
 *
 * Contexto da casca (T-08): alimenta o cartão do usuário (#cv-user-role) e o
 * seletor de unidade ([data-cv-unit-switch]) montados por cv-shell.js.
 *
 * - onContext (engine.php?class=CvShellController&method=onContext&static=1)
 *   devolve JSON {"user":{"name","role"},"units":[{"id","name","current"}],
 *   "csrf_token","labels":{coming_soon,open_from_encounter,unit,error}}.
 *   O token fica em TSession 'cv_shell_csrf' (gerado só quando vazio).
 * - onSwitchUnit (POST unit_id + csrf_token) troca a unidade da sessão via
 *   ApplicationAuthenticationService::setUnit() e responde só JSON:
 *   403 {error} sem token válido (T-26: GET é sempre recusado) ou fora das
 *   unidades permitidas; 200 {switched:true} em caso de sucesso (o reload
 *   é feito por cv-shell.js).
 * - onComingSoon: destino dos itens de menu desabilitados ("Em breve").
 *   cv-shell.js neutraliza esses links; sem JS, o clique só mostra o aviso.
 *
 * Defesa em profundidade (T-19): onContext e onSwitchUnit só consideram as
 * unidades do usuário cujo system_unit.tenant_id é o tenant da sessão
 * (TSession 'tenantid'), pela mesma função allowedUnitIds().
 *
 * @package    control
 * @subpackage clinic
 */
class CvShellController extends TPage
{
    /**
     * Contexto do usuário logado em JSON.
     */
    public static function onContext($param = null)
    {
        try
        {
            $allowed = self::allowedUnitIds();

            TTransaction::open('permission');

            $user = SystemUser::newFromLogin(TSession::getValue('login'));
            if (!$user)
            {
                throw new Exception(_t('Permission denied'));
            }

            $groups = [];
            foreach ($user->getSystemUserGroups() ?: [] as $group)
            {
                $groups[(int) $group->id] = (string) $group->name;
            }
            ksort($groups);

            $current_unit = (int) TSession::getValue('userunitid');
            $units = [];
            foreach ($user->getSystemUserUnits() ?: [] as $unit)
            {
                if (!in_array((int) $unit->id, $allowed, true))
                {
                    continue;
                }

                $units[(int) $unit->id] = [
                    'id'      => (int) $unit->id,
                    'name'    => (string) $unit->name,
                    'current' => ((int) $unit->id === $current_unit),
                ];
            }
            ksort($units);

            $payload = [
                'user' => [
                    'name' => (string) $user->name,
                    'role' => $groups ? (string) reset($groups) : '',
                ],
                'units' => array_values($units),
                'csrf_token' => self::csrfToken(),
                'labels' => [
                    'coming_soon'         => _t('Coming soon'),
                    'open_from_encounter' => _t('Open from the encounter'),
                    'unit'                => _t('Unit'),
                    'error'               => _t('Error'),
                ],
            ];

            TTransaction::close();

            self::sendJson($payload);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log('CvShellController::onContext: ' . $e->getMessage());
            self::sendJson(['error' => _t('Could not load the user context')], 500);
        }
    }

    /**
     * Troca a unidade atual da sessão.
     */
    public static function onSwitchUnit($param)
    {
        $valid_token = CsrfToken::isValid(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_POST['csrf_token'] ?? null,
            TSession::getValue('cv_shell_csrf')
        );

        if (!$valid_token)
        {
            self::sendJson(['error' => _t('Invalid or expired request. Reload the page')], 403);
            return;
        }

        try
        {
            $unit_id = isset($param['unit_id']) ? (int) $param['unit_id'] : 0;

            if ($unit_id <= 0 || !in_array($unit_id, self::allowedUnitIds(), true))
            {
                self::sendJson(['error' => _t('Unauthorized access to that unit')], 403);
                return;
            }

            ApplicationAuthenticationService::setUnit($unit_id);

            self::sendJson(['switched' => true]);
        }
        catch (Exception $e)
        {
            error_log('CvShellController::onSwitchUnit: ' . $e->getMessage());
            self::sendJson(['error' => _t('Unauthorized access to that unit')], 403);
        }
    }

    /**
     * Destino dos itens de menu desabilitados (sem tela ainda).
     */
    public static function onComingSoon($param = null)
    {
        new TMessage('info', _t('Coming soon'));
    }

    /**
     * Ids das unidades do usuário logado que pertencem ao tenant da sessão.
     * Sem tenant na sessão, nenhuma unidade é permitida.
     *
     * @return int[]
     */
    private static function allowedUnitIds(): array
    {
        $tenant_id = (int) TSession::getValue('tenantid');
        if ($tenant_id <= 0)
        {
            return [];
        }

        TTransaction::open('permission');

        try
        {
            $user = SystemUser::newFromLogin(TSession::getValue('login'));
            $user_unit_ids = $user ? array_map('intval', (array) $user->getSystemUserUnitIds()) : [];

            $allowed = [];
            if ($user_unit_ids)
            {
                $placeholders = implode(',', array_fill(0, count($user_unit_ids), '?'));
                $stmt = TTransaction::get()->prepare(
                    "SELECT id FROM system_unit WHERE tenant_id = ? AND id IN ({$placeholders}) ORDER BY id"
                );
                $stmt->execute(array_merge([$tenant_id], $user_unit_ids));
                $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            TTransaction::close();

            return $allowed;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            throw $e;
        }
    }

    /**
     * Token CSRF da casca, guardado na sessão e gerado só quando vazio.
     */
    private static function csrfToken(): string
    {
        $token = TSession::getValue('cv_shell_csrf');
        if (!is_string($token) || $token === '')
        {
            $token = CsrfToken::generate();
            TSession::setValue('cv_shell_csrf', $token);
        }

        return $token;
    }

    private static function sendJson(array $payload, int $status = 200): void
    {
        if (!headers_sent())
        {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

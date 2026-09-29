<?php
/**
 * CvShellController
 *
 * Contexto da casca (T-08): alimenta o cartão do usuário (#cv-user-role) e o
 * seletor de unidade ([data-cv-unit-switch]) montados por cv-shell.js.
 *
 * - onContext (engine.php?class=CvShellController&method=onContext&static=1)
 *   devolve JSON {"user":{"name","role"},"units":[{"id","name","current"}]}.
 * - onSwitchUnit (parâmetro unit_id) troca a unidade da sessão via
 *   ApplicationAuthenticationService::setUnit(), que valida o vínculo do
 *   usuário com a unidade; em caso de sucesso recarrega a página atual.
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
            ];

            TTransaction::close();

            self::sendJson($payload);
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            self::sendJson(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Troca a unidade atual da sessão.
     */
    public static function onSwitchUnit($param)
    {
        try
        {
            $unit_id = isset($param['unit_id']) ? (int) $param['unit_id'] : 0;

            if ($unit_id <= 0 || !in_array($unit_id, self::allowedUnitIds(), true))
            {
                throw new Exception(_t('Unauthorized access to that unit'));
            }

            ApplicationAuthenticationService::setUnit($unit_id);

            TScript::create('window.location.reload();');
        }
        catch (Exception $e)
        {
            new TMessage('error', $e->getMessage());
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

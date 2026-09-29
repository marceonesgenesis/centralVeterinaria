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
 *
 * Nenhuma regra de vínculo usuário/unidade mora aqui: a validação é a do
 * serviço de autenticação.
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

            if ($unit_id <= 0)
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

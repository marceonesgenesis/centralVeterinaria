<?php
/**
 * ClinicDataExportView
 *
 * Exportação dos dados da clínica (LGPD, Etapa 5, T-17). A tela explica o
 * conteúdo e o botão "Exportar dados da clínica" chama onDownload, que:
 * gera o ZIP de CentralVet\Privacy\TenantDataExporter num arquivo
 * temporário, registra `clinic_data_exported` no audit_log (AuditEvent com
 * o tenant da sessão), envia `application/zip` como anexo
 * `dados-clinica-<Y-m-d>.zip` com `Cache-Control: private, no-store`
 * (padrão de FinancialOverview::onExport) e apaga o temporário.
 *
 * onDownload é leitura para a MutationRequestPolicy (READ_METHODS): o
 * registro de auditoria não altera dado da clínica. Sem tenant responde
 * 403; outra falha, 500 — sem corpo HTML.
 *
 * @version    8.6
 * @package    control
 * @subpackage admin
 * @license    https://adiantiframework.com.br/license-template
 */
class ClinicDataExportView extends TPage
{
    public const AUDIT_ACTION = 'clinic_data_exported';

    public function __construct($param = null)
    {
        parent::__construct();

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Export clinic data'), null, [], false));

        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->add(TElement::tag('p', CvFormat::e(_t('The file contains the clinic records in CSV format, one file per table'))));

        $link = new TElement('a');
        $link->{'class'} = 'btn btn-primary cv-touch-target';
        $link->{'href'} = 'engine.php?class=ClinicDataExportView&method=onDownload&static=1';
        $link->{'rel'} = 'nofollow';
        $link->add(new TImage('fa:download'));
        $link->add(TElement::tag('span', CvFormat::e(_t('Export clinic data'))));
        $body->add($link);

        $card->add($body);
        $container->add($card);

        parent::add($container);
    }

    public static function onDownload($param = null)
    {
        $now = new DateTimeImmutable();
        $zipPath = null;
        $status = 500;
        $ready = false;

        try
        {
            TTransaction::open('permission');

            $context = self::resolveTenantContext();
            $connection = TTransaction::get();

            $zipPath = tempnam(sys_get_temp_dir(), 'cv-export-');
            if ($zipPath === false)
            {
                $zipPath = null;
                throw new RuntimeException('Could not create the temporary export file');
            }

            $counts = (new CentralVet\Privacy\TenantDataExporter($connection))->export($context->tenantId(), $zipPath, $now);

            (new CentralVet\Audit\PdoAuditLogWriter($connection))->record(new CentralVet\Audit\AuditEvent(
                tenantId: $context->tenantId(),
                unitId: $context->unitId(),
                userId: (int) TSession::getValue('userid'),
                correlationId: CentralVet\Observability\CorrelationId\CorrelationIdContext::current()
                    ?? CentralVet\Observability\CorrelationId\CorrelationIdContext::start(),
                action: self::AUDIT_ACTION,
                entityType: 'tenant',
                entityId: $context->tenantId(),
                beforeData: null,
                afterData: null,
                metadata: ['tables' => count($counts['tables']), 'users' => $counts['users']],
                ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
                occurredAt: $now,
            ));

            TTransaction::close();
            $ready = true;
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $status = 403;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e) . ': ' . $e->getMessage());
        }

        while (ob_get_level() > 0)
        {
            ob_end_clean();
        }

        if (!$ready)
        {
            if ($zipPath !== null && is_file($zipPath))
            {
                unlink($zipPath);
            }
            http_response_code($status);
            exit;
        }

        $file_name = 'dados-clinica-' . $now->format('Y-m-d') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Cache-Control: private, no-store');
        // nosniff vem só do nginx (fastcgi_hide_header + add_header), T-46

        try
        {
            readfile($zipPath);
        }
        finally
        {
            unlink($zipPath);
        }
        exit;
    }

    /**
     * Contexto de tenant da sessão autenticada; mesmo fallback de
     * FinancialOverview::resolveTenantContext() para sessões legadas.
     */
    private static function resolveTenantContext(): CentralVet\Tenancy\TenantContext
    {
        $source = new CentralVet\Tenancy\AdiantiSessionContextSource();

        try
        {
            return CentralVet\Tenancy\TenantContext::fromAuthenticatedSession($source);
        }
        catch (CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenantId = $stmt->fetchColumn();

            if (empty($tenantId))
            {
                throw $e;
            }

            $unitId = TSession::getValue('userunitid');

            return CentralVet\Tenancy\TenantContext::authenticated((int) $tenantId, (int) $userid, $unitId ? (int) $unitId : null);
        }
    }
}

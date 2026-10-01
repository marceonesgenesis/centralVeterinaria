<?php
/**
 * ServiceImportForm
 *
 * Importação do catálogo de serviços por CSV (rodada 2, T-10). O TFile envia
 * o arquivo para tmp/ (AdiantiUploaderService, extensão csv, até 1 MB);
 * onImport lê o arquivo e delega tudo a
 * CentralVet\Application\ServiceCatalogService::importCsv() — cabeçalho
 * `name;category;duration_minutes;price`, separador `;`, linhas inválidas ou
 * com nome repetido são puladas e reportadas. Nenhuma regra de negócio aqui.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class ServiceImportForm extends TPage
{
    private const MAX_BYTES = 1048576; // 1 MB

    protected $form;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_ServiceImport');
        $this->form->setFormTitle(_t('Import services'));

        $csv_file = new TFile('csv_file');
        $csv_file->setAllowedExtensions(['csv']);
        $csv_file->setLimitUploadSize(1);
        $csv_file->setSize('100%');

        $this->form->addFields([new TLabel(_t('CSV file'))], [$csv_file]);

        $help = TElement::tag(
            'p',
            CvFormat::e(_t('Separator ";", first line with the header:')) . ' <code>'
                . CvFormat::e(\CentralVet\Application\ServiceCatalogService::CSV_HEADER) . '</code>',
            ['class' => 'text-muted mb-0']
        );
        $this->form->addContent([$help]);

        CvForm::decorate($this->form, 1);

        $btn = $this->form->addAction(_t('Import'), new TAction([$this, 'onImport']), 'fa:file-import');
        $btn->class = 'btn btn-primary';

        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('Import services'), null, [
            ['icon' => 'fa:arrow-left', 'href' => 'index.php?class=ServiceList'],
        ]));
        $container->add(CvNav::tabs('services', 'services'));
        $container->add($this->form);

        parent::add($container);
    }

    /**
     * Lê o CSV enviado para tmp/, chama importCsv() e mostra quantos
     * serviços foram criados e a lista "linha: motivo" das linhas puladas.
     */
    public function onImport($param = null)
    {
        $sourcePath = null;

        try
        {
            $fileName = is_array($param) && isset($param['csv_file']) ? trim(urldecode((string) $param['csv_file'])) : '';

            if ($fileName === '')
            {
                throw new InvalidArgumentException(_t('Choose a CSV file to import'));
            }

            // T-62: only a regular file inside tmp/ (no ../, separators or
            // symlink out); anything else throws 'Invalid file'
            $sourcePath = \CentralVet\Presentation\UploadedTmpFile::resolve($fileName);

            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv' || filesize($sourcePath) > self::MAX_BYTES)
            {
                throw new InvalidArgumentException(_t('The file must be a CSV of up to 1 MB'));
            }

            $contents = (string) file_get_contents($sourcePath);

            TTransaction::open('permission');

            $result = self::buildServiceCatalogService()->importCsv($contents);

            TTransaction::close();

            @unlink($sourcePath);

            $message = CvFormat::e(_t('Services created: ^1', (string) (int) $result['created']));

            if (!empty($result['skipped']))
            {
                $items = '';
                foreach ($result['skipped'] as $skipped)
                {
                    // Motivo vindo de create() (catálogo UserMessage) → texto pt já escapado;
                    // os motivos fixos do importCsv são chaves diretas de _t().
                    $reason = (string) $skipped['reason'];
                    $reasonText = \CentralVet\Presentation\UserMessage::resolve($reason) !== null
                        ? CvFormat::userMessage($reason)
                        : CvFormat::e(_t($reason));
                    $items .= '<li>' . CvFormat::e(_t('Line ^1', (string) (int) $skipped['line'])) . ': ' . $reasonText . '</li>';
                }
                $message .= '<br>' . CvFormat::e(_t('Skipped lines')) . ':<ul class="mb-0">' . $items . '</ul>';
            }

            new TMessage('info', $message, new TAction(['ServiceList', 'onReload']));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('Your session does not have an active tenant. Please log in again'));
        }
        catch (InvalidArgumentException $e)
        {
            // Cabeçalho inválido ou problema no arquivo: a mensagem é do domínio/do form.
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            // T-62: 'Invalid file' → "Arquivo inválido"; outros textos já vêm de _t() e são escapados
            new TMessage('error', CvFormat::userError($e));
        }
        catch (Exception $e)
        {
            // PDOException e afins: nada de SQLSTATE na tela; o detalhe vai para o log.
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . $e->getMessage());
            new TMessage('error', _t('Could not import the file. No service was created'));
        }
    }

    /**
     * Monta o serviço de aplicação. Exige TTransaction('permission') aberta.
     */
    private static function buildServiceCatalogService()
    {
        $tenant_context = self::resolveTenantContext();
        $connection = TTransaction::get();

        $repository = new \CentralVet\Persistence\ServiceRepository($tenant_context, $connection);

        return new \CentralVet\Application\ServiceCatalogService($repository, $tenant_context);
    }

    /**
     * Resolve o tenant da sessão autenticada; sessões legadas sem 'tenantid'
     * caem no vínculo tenant_user (mesma convenção de ServiceList).
     */
    private static function resolveTenantContext()
    {
        $source = new \CentralVet\Tenancy\AdiantiSessionContextSource();

        try
        {
            return \CentralVet\Tenancy\TenantContext::fromAuthenticatedSession($source);
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            $userid = TSession::getValue('userid');

            if (TSession::getValue('logged') !== true || empty($userid))
            {
                throw $e;
            }

            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}

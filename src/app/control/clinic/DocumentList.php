<?php
/**
 * DocumentList
 *
 * Documentos gerados da unidade ativa (Fase 7B, T-16), opcionalmente de um
 * paciente (`DocumentList&patient_id=<id>`): colunas documento, versão,
 * status (CvBadge), pedido em e ações. `ready` → link de download só por id
 * (`onDownload&id=<id>&static=1`); `failed` → nova tentativa por TQuestion
 * (só o id); `queued` → texto de processamento.
 *
 * O download responde 404 com o mesmo texto fixo para documento
 * inexistente, de outra unidade, ainda não pronto, sem permissão ou sem
 * sessão: nada distingue um caso do outro (sem oráculo). O error_log leva
 * só a classe da exceção, nunca mensagem, nome ou texto do documento.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class DocumentList extends TPage
{
    private const ACTION_RELOAD = 'DocumentList::onReload';
    private const ACTION_DOWNLOAD = 'DocumentList::onDownload';
    private const ACTION_RETRY = 'DocumentList::onRetry';

    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        // com method, o dispatcher chama o método, e onReload já carrega
        if (empty($param['method']))
        {
            $this->onReload($param);
        }
    }

    /**
     * Lista os documentos da unidade ativa (até 200, mais novos primeiro),
     * filtrados pelo paciente quando `patient_id` vem na URL.
     */
    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $patientId = self::positiveInt($param['patient_id'] ?? ($_GET['patient_id'] ?? null));

        $container = new TVBox;
        $container->style = 'width: 100%';

        $refresh = new TAction([__CLASS__, 'onReload']);
        if ($patientId !== null)
        {
            $refresh->setParameter('patient_id', $patientId);
        }
        $refreshButton = [
            'label' => _t('Refresh'),
            'action' => $refresh,
            'icon' => 'fa:sync',
            'class' => 'btn btn-default cv-touch-target',
        ];

        try
        {
            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            $connection = TTransaction::get();

            $documents = self::makeService($context)->listForUnit($patientId, self::ACTION_RELOAD);

            $subtitle = (string) TSession::getValue('userunitname');
            if ($patientId !== null)
            {
                $patient = (new \CentralVet\Persistence\DocumentSourceQuery($context, $connection))->patientSummary($patientId);
                $subtitle = $patient !== null ? (string) $patient['patient_name'] : $subtitle;
            }

            TTransaction::close();
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            $container->add(CvPage::header(_t('Documents'), null, [$refreshButton]));
            new TMessage('error', _t('You are not allowed to view the documents'));
            parent::add($container);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            $container->add(CvPage::header(_t('Documents'), null, [$refreshButton]));
            new TMessage('error', _t('An authenticated session with an active unit is required'));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            $container->add(CvPage::header(_t('Documents'), null, [$refreshButton]));
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $container->add(CvPage::header(_t('Documents'), $subtitle !== '' ? $subtitle : null, [$refreshButton]));
        $container->add($documents === [] ? self::emptyState() : self::table($documents));

        parent::add($container);
    }

    /**
     * Download do PDF só por id (estático: `&static=1`). Qualquer falha
     * vira o mesmo 404 com texto fixo, sem Content-Disposition.
     */
    public static function onDownload($param)
    {
        $response = self::downloadResponse($param);

        while (ob_get_level() > 0)
        {
            ob_end_clean();
        }

        http_response_code($response['status']);
        foreach ($response['headers'] as $header)
        {
            header($header);
        }
        echo $response['body'];
        exit;
    }

    /** Confirmação da nova tentativa: o TQuestion carrega só o id. */
    public static function onAskRetry($param = null)
    {
        $action = new TAction([__CLASS__, 'onRetry']);
        $action->setParameter('id', (int) self::positiveInt($param['id'] ?? null));
        $action->setParameter('static', '1');

        new TQuestion(_t('Try to generate this document again?'), $action);
    }

    /**
     * failed → queued (DocumentRequestService::retry); depois do commit o job
     * é publicado. Falha no push só registra o id (o varredor republica).
     */
    public static function onRetry($param)
    {
        $id = self::positiveInt($param['id'] ?? null);

        try
        {
            if ($id === null)
            {
                throw new \CentralVet\Domain\Exception\DocumentNotAvailableException();
            }

            $context = self::resolveTenantContext();

            TTransaction::open('permission');
            self::makeService($context)->retry($id, self::ACTION_RETRY);
            TTransaction::close();

            try
            {
                (new \CentralVet\Application\DocumentJobPublisher(\CentralVet\Queue\RedisQueue::fromEnvironment()))
                    ->publish($context->tenantId(), $id);
            }
            catch (Throwable $publishError)
            {
                error_log(__METHOD__ . ': queue publish failed for document ' . $id . ' (' . get_class($publishError) . ')');
            }

            TToast::show('success', _t('Document requeued'));
            TScript::create("__adianti_goto_page('index.php?class=DocumentList')");
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('You are not allowed to change this document'));
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            TTransaction::rollback();
            new TMessage('error', _t('An authenticated session with an active unit is required'));
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }
    }

    /**
     * Resposta do download: 200 com o PDF ou 404 com o texto fixo.
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function downloadResponse($param): array
    {
        $id = self::positiveInt($param['id'] ?? null);
        $document = null;

        if ($id !== null)
        {
            try
            {
                $context = self::resolveTenantContext();

                TTransaction::open('permission');
                $document = self::makeService($context)->download($id, self::ACTION_DOWNLOAD);
                TTransaction::close();
            }
            catch (Throwable $e)
            {
                TTransaction::rollback();
                // só a classe: a mensagem pode carregar ids de outra unidade
                error_log(__METHOD__ . ': ' . get_class($e));
                $document = null;
            }
        }

        if ($document === null)
        {
            return [
                'status' => 404,
                'headers' => [
                    'Content-Type: text/plain; charset=utf-8',
                    'Cache-Control: private, no-store',
                    'X-Content-Type-Options: nosniff',
                ],
                'body' => _t('Document not found'),
            ];
        }

        // nome `<kind>-<id>-v<versão>.pdf`, sem dado pessoal; saneado por garantia
        $fileName = (string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $document['file_name']);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type: application/pdf',
                'Content-Disposition: attachment; filename="' . $fileName . '"',
                'Content-Length: ' . strlen($document['contents']),
                'Cache-Control: private, no-store',
                'Content-Security-Policy: sandbox',
                'X-Content-Type-Options: nosniff',
            ],
            'body' => $document['contents'],
        ];
    }

    private static function emptyState(): TElement
    {
        $state = new TElement('div');
        $state->{'class'} = 'cv-state cv-state--empty';
        $state->{'role'} = 'status';

        $icon = new TElement('span');
        $icon->{'class'} = 'cv-state__icon';
        $icon->{'aria-hidden'} = 'true';
        $icon->add(new TImage('fa:file-pdf'));
        $state->add($icon);

        $text = new TElement('div');
        $text->add(TElement::tag('p', CvFormat::e(_t('No documents yet')), ['class' => 'cv-state__title']));
        $state->add($text);

        return $state;
    }

    /**
     * @param list<\CentralVet\Domain\GeneratedDocument> $documents
     */
    private static function table(array $documents): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';
        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $body->style = 'overflow-x:auto';
        $card->add($body);

        $table = new TElement('table');
        $table->{'class'} = 'table cv-table';
        $table->style = 'width:100%';

        $thead = new TElement('thead');
        $head = new TElement('tr');
        foreach ([_t('Document'), _t('Version'), _t('Status'), _t('Requested at'), _t('Actions')] as $title)
        {
            $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col', 'style' => 'text-align:left']));
        }
        $thead->add($head);
        $table->add($thead);

        $tbody = new TElement('tbody');
        foreach ($documents as $document)
        {
            $createdAt = $document->createdAt();

            $tr = new TElement('tr');
            $tr->add(TElement::tag('td', CvFormat::e($document->title())));
            $tr->add(TElement::tag('td', CvFormat::e('v' . $document->version())));

            $status = new TElement('td');
            $status->add(self::statusBadge($document->status()));
            $tr->add($status);

            $tr->add(TElement::tag('td', CvFormat::e($createdAt !== null ? $createdAt->format('d/m/Y H:i') : '—')));

            $actions = new TElement('td');
            $actions->add(self::actionFor($document));
            $tr->add($actions);

            $tbody->add($tr);
        }
        $table->add($tbody);

        $body->add($table);

        return $card;
    }

    private static function actionFor(\CentralVet\Domain\GeneratedDocument $document): TElement
    {
        $id = (int) $document->id();

        switch ($document->status())
        {
            case \CentralVet\Domain\GeneratedDocument::STATUS_READY:
                // fora do roteador do Adianti: a resposta é o PDF, não HTML
                $link = new TElement('a');
                $link->{'class'} = 'btn btn-sm btn-primary cv-touch-target';
                $link->{'href'} = CvFormat::e('index.php?class=DocumentList&method=onDownload&id=' . $id . '&static=1');
                $link->style = 'display:inline-flex; align-items:center; gap:var(--cv-space-1)';
                $link->add(new TImage('fa:download'));
                $link->add(TElement::tag('span', CvFormat::e(_t('Download'))));

                return $link;

            case \CentralVet\Domain\GeneratedDocument::STATUS_FAILED:
                $action = new TAction([__CLASS__, 'onAskRetry']);
                $action->setParameter('id', $id);
                $action->setParameter('static', '1');

                $link = new TElement('a');
                $link->{'class'} = 'btn btn-sm btn-outline-secondary cv-touch-target';
                $link->{'href'} = CvFormat::e($action->serialize(true));
                $link->{'generator'} = 'adianti';
                $link->style = 'display:inline-flex; align-items:center; gap:var(--cv-space-1)';
                $link->add(new TImage('fa:redo'));
                $link->add(TElement::tag('span', CvFormat::e(_t('Try again'))));

                return $link;

            default:
                return TElement::tag('span', CvFormat::e(_t('Processing…')), ['class' => 'text-muted']);
        }
    }

    private static function statusBadge(string $status): TElement
    {
        return match ($status) {
            \CentralVet\Domain\GeneratedDocument::STATUS_READY  => CvBadge::create(_t('Ready'), 'success'),
            \CentralVet\Domain\GeneratedDocument::STATUS_FAILED => CvBadge::create(_t('Failed'), 'danger'),
            default                                             => CvBadge::create(_t('Queued'), 'warning'),
        };
    }

    private static function positiveInt($value): ?int
    {
        if (is_int($value))
        {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1 && (int) $value > 0)
        {
            return (int) $value;
        }

        return null;
    }

    private static function makeService(\CentralVet\Tenancy\TenantContext $context): \CentralVet\Application\DocumentRequestService
    {
        $connection = TTransaction::get();

        return new \CentralVet\Application\DocumentRequestService(
            new \CentralVet\Persistence\GeneratedDocumentRepository($context, $connection),
            new \CentralVet\Persistence\DocumentSourceQuery($context, $connection),
            new \CentralVet\Persistence\DocumentTemplateRepository($context, $connection),
            \CentralVet\Storage\DocumentStorageFactory::fromEnvironment($context),
            new \CentralVet\Authorization\RbacAuthorizationService(
                new \CentralVet\Authorization\AdiantiProgramPermissionProvider(new \CentralVet\Tenancy\AdiantiSessionContextSource()),
                new \CentralVet\Audit\PdoAuditLogWriter($connection),
            ),
            $context,
        );
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * CommunicationMessageList::resolveTenantContext() para sessões legadas.
     */
    private static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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

            TTransaction::open('permission');
            $stmt = TTransaction::get()->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
            $stmt->execute(['userid' => (int) $userid]);
            $tenant_id = $stmt->fetchColumn();
            TTransaction::close();

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}

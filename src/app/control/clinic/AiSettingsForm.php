<?php
/**
 * AiSettingsForm — configuração do assistente de IA do tenant (Fase 8A,
 * T-13).
 *
 * Interruptor "Ativar assistente de IA" (`ai_enabled`), termo de uso
 * (AiTerms::text do idioma da sessão, num bloco rolável, com a declaração
 * do ditado por voz do navegador), caixa "Li e aceito o termo"
 * (`accept_terms`, obrigatória para ligar enquanto o termo atual não foi
 * aceito), interruptor do copiloto de gestão (`ai_copilot_management`,
 * Fase 8B.2), versão e data do aceite, cota mensal de tokens
 * (`monthly_token_quota`), cartão do mês (tokens usados / cota, custo em
 * US$ e pedidos por finalidade) e links para AiInteractionList e
 * AiToolCallList (auditoria de ferramentas do copiloto). Mostra o
 * modelo configurado (AI_MODEL) e se o provedor está configurado; nunca a
 * chave. Sem regra própria: tudo passa por
 * CentralVet\Application\AiSettingsService (RBAC, termo, cota, auditoria).
 *
 * Os valores do POST vêm só do corpo da requisição (`$_POST`): a query
 * string nunca liga a IA.
 *
 * Rotas: index.php?class=AiSettingsForm (onReload) e o POST onSave.
 *
 * @version    1.0
 * @package    control
 * @subpackage clinic
 */
class AiSettingsForm extends TPage
{
    protected $form;
    private bool $loaded = false;

    public function __construct($param = null)
    {
        parent::__construct();

        $this->form = new BootstrapFormBuilder('form_AiSettings');

        $enabled = new TCheckButton('ai_enabled');
        $enabled->setIndexValue('1');
        $enabled->setUseSwitch(true, 'blue');

        // rótulo do switch leva cv-touch-target (alvo de toque de 44 px)
        $management = new TCheckButton('ai_copilot_management');
        $management->setIndexValue('1');
        $management->setUseSwitch(true, 'blue cv-touch-target');

        $accept = new TCheckButton('accept_terms');
        $accept->setIndexValue('1');

        $quota = new TEntry('monthly_token_quota');
        $quota->setMask('9!');
        $quota->setMaxLength(9);

        CvForm::decorate($this->form, 1);

        $this->form->addFields([new TLabel(_t('Turn on the AI assistant'))], [$enabled]);
        $this->form->addFields([new TLabel(_t('I have read and accept the term'))], [$accept]);
        $this->form->addFields([new TLabel(_t('Enable the management copilot (schedule, stock, debts and indicators)'))], [$management]);
        $this->form->addFields([new TLabel(_t('Monthly token quota'))], [$quota]);

        $btn = $this->form->addAction(_t('Save'), new TAction([$this, 'onSave']), 'fa:check');
        $btn->class = 'btn btn-primary cv-touch-target';

        // com method, o dispatcher chama onReload()/onSave(), que já desenham
        if (empty($param['method']))
        {
            $this->onReload();
        }
    }

    public function onReload($param = null)
    {
        if ($this->loaded)
        {
            return;
        }
        $this->loaded = true;

        $this->render();
    }

    /**
     * Grava cota, liga/desliga o assistente e o copiloto de gestão numa
     * transação. Ligar sem o termo atual
     * aceito mostra o aviso de termo pendente e nada é gravado.
     */
    public function onSave($param = null)
    {
        $this->loaded = true;

        $enable = (string) ($_POST['ai_enabled'] ?? '') === '1';
        $accept = (string) ($_POST['accept_terms'] ?? '') === '1';
        $management = (string) ($_POST['ai_copilot_management'] ?? '') === '1';
        $quotaRaw = trim((string) ($_POST['monthly_token_quota'] ?? ''));

        try
        {
            if (preg_match('/^\d{1,9}$/', $quotaRaw) !== 1)
            {
                throw new InvalidArgumentException('Invalid monthly token quota');
            }

            $context = static::resolveTenantContext();

            static::transaction(static function (PDO $connection) use ($context, $enable, $accept, $management, $quotaRaw): void
            {
                $service = static::makeService($context, $connection);
                $service->changeMonthlyQuota((int) $quotaRaw);

                if ($enable)
                {
                    $service->enable($accept);
                }
                else
                {
                    $service->disable();
                }

                $service->setCopilotManagement($management);
            });

            TToast::show('success', _t('Record saved'));
        }
        catch (\CentralVet\Domain\Exception\AiUnavailableException $e)
        {
            new TMessage('warning', CvFormat::e($e->reason() === 'ai_terms_pending'
                ? _t('Accept the AI terms to turn the assistant on')
                : _t('The AI assistant is unavailable')));
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', CvFormat::e(_t('You are not allowed to manage the AI settings')));
            parent::add(self::page());
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', CvFormat::e(_t('An authenticated session with an active unit is required')));
            parent::add(self::page());
            return;
        }
        catch (InvalidArgumentException $e)
        {
            // cota fora de 0..100000000 (serviço) ou não numérica (acima)
            new TMessage('error', CvFormat::e(_t('Enter the monthly token quota as a whole number')));
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
        }

        $this->render();
    }

    /**
     * Desenha a página com o estado atual (overview do serviço).
     */
    private function render(): void
    {
        $container = self::page();

        try
        {
            $context = static::resolveTenantContext();
            $overview = static::transaction(static fn (PDO $connection): array => static::makeService($context, $connection)->overview());
        }
        catch (\CentralVet\Authorization\Exception\AuthorizationDenied $e)
        {
            new TMessage('error', CvFormat::e(_t('You are not allowed to manage the AI settings')));
            parent::add($container);
            return;
        }
        catch (\CentralVet\Tenancy\Exception\MissingTenantContext $e)
        {
            new TMessage('error', CvFormat::e(_t('An authenticated session with an active unit is required')));
            parent::add($container);
            return;
        }
        catch (Exception $e)
        {
            error_log(__METHOD__ . ': ' . get_class($e));
            new TMessage('error', CvFormat::userError($e));
            parent::add($container);
            return;
        }

        $data = new stdClass;
        $data->ai_enabled = $overview['enabled'] ? '1' : '';
        $data->accept_terms = '';
        $data->ai_copilot_management = $overview['copilot_management'] ? '1' : '';
        $data->monthly_token_quota = (string) $overview['monthly_token_quota'];
        $this->form->setData($data);

        $container->add(self::providerCard());
        $container->add(self::termsCard($overview));
        $container->add($this->form);
        $container->add(self::usageCard($overview));

        parent::add($container);
    }

    private static function page(): TVBox
    {
        $container = new TVBox;
        $container->style = 'width: 100%';
        $container->add(CvPage::header(_t('AI assistant'), _t('Settings'), [
            ['icon' => 'fa:list', 'label' => _t('View interactions'), 'href' => 'index.php?class=AiInteractionList', 'class' => 'btn btn-default cv-touch-target'],
            ['icon' => 'fa:toolbox', 'label' => _t('Tool audit'), 'href' => 'index.php?class=AiToolCallList', 'class' => 'btn btn-default cv-touch-target'],
        ], false));

        return $container;
    }

    /**
     * Modelo e situação do provedor, sem nunca mostrar a chave.
     */
    private static function providerCard(): TElement
    {
        try
        {
            $config = static::aiConfig();
            $model = $config->model();
            $configured = $config->provider() === 'stub' || $config->apiKey() !== null;
        }
        catch (InvalidArgumentException $e)
        {
            $model = '—';
            $configured = false;
        }

        $card = self::card(_t('Provider'));
        $body = $card->get(1);
        $body->add(TElement::tag('p', CvFormat::e(_t('Model')) . ': <strong>' . CvFormat::e($model) . '</strong>'));
        $body->add(TElement::tag('p', CvFormat::e($configured ? _t('The AI provider is configured') : _t('The AI provider is not configured'))));

        return $card;
    }

    /**
     * Termo do idioma da sessão (rolável; a declaração do ditado faz parte
     * do texto versionado) e a situação do aceite.
     *
     * @param array<string, mixed> $overview
     */
    private static function termsCard(array $overview): TElement
    {
        $card = self::card(_t('AI terms of use'));
        $body = $card->get(1);

        $scroll = new TElement('div');
        $scroll->{'class'} = 'cv-ai-terms';
        $scroll->{'tabindex'} = '0';
        $scroll->{'role'} = 'region';
        $scroll->{'aria-label'} = _t('AI terms of use');
        $scroll->style = 'max-height: 12rem; overflow-y: auto';
        $scroll->add(TElement::tag('p', CvFormat::e(\CentralVet\Domain\AiTerms::text(self::locale()))));
        $body->add($scroll);

        $version = $overview['terms_version'];
        $acceptedAt = $overview['terms_accepted_at'];

        if ($version !== null && $acceptedAt instanceof DateTimeInterface)
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('Version ^1 accepted on ^2', (string) $version, $acceptedAt->format('d/m/Y H:i')))));
        }

        if (!$overview['terms_current'])
        {
            $body->add(TElement::tag('p', CvFormat::e(_t('The current term (version ^1) has not been accepted yet', \CentralVet\Domain\AiTerms::VERSION)), ['class' => 'text-warning']));
        }

        return $card;
    }

    /**
     * Cartão do mês: tokens usados / cota, custo em US$ (4 casas) e pedidos
     * por finalidade.
     *
     * @param array<string, mixed> $overview
     */
    private static function usageCard(array $overview): TElement
    {
        $card = self::card(_t('Usage this month'));
        $body = $card->get(1);

        $body->add(TElement::tag('p', CvFormat::e(_t('Tokens used')) . ': <strong>'
            . CvFormat::e(self::number((int) $overview['month_tokens']) . ' / ' . self::number((int) $overview['monthly_token_quota'])) . '</strong>'));
        $body->add(TElement::tag('p', CvFormat::e(_t('Cost')) . ': <strong>' . CvFormat::e(self::usd((int) $overview['month_cost_micro_usd'])) . '</strong>'));

        if ($overview['by_purpose'] !== [])
        {
            $table = new TElement('table');
            $table->{'class'} = 'table cv-table';
            $table->style = 'width:100%';

            $head = new TElement('tr');
            foreach ([_t('Purpose'), _t('Requests'), _t('Tokens'), _t('Cost')] as $title)
            {
                $head->add(TElement::tag('th', CvFormat::e($title), ['scope' => 'col']));
            }
            $thead = new TElement('thead');
            $thead->add($head);
            $table->add($thead);

            $tbody = new TElement('tbody');
            foreach ($overview['by_purpose'] as $row)
            {
                $tr = new TElement('tr');
                $tr->add(TElement::tag('td', CvFormat::e(self::purposeLabel((string) $row['purpose']))));
                $tr->add(TElement::tag('td', CvFormat::e(self::number((int) $row['requests']))));
                $tr->add(TElement::tag('td', CvFormat::e(self::number((int) $row['tokens']))));
                $tr->add(TElement::tag('td', CvFormat::e(self::usd((int) $row['cost_micro_usd']))));
                $tbody->add($tr);
            }
            $table->add($tbody);
            $body->add($table);
        }

        return $card;
    }

    /** Rótulo traduzido da finalidade (também usado por AiInteractionList). */
    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose)
        {
            \CentralVet\Domain\AiPurpose::PATIENT_SUMMARY    => _t('Patient summary'),
            \CentralVet\Domain\AiPurpose::ANAMNESIS_ORGANIZE => _t('Anamnesis organization'),
            \CentralVet\Domain\AiPurpose::EXAM_SUMMARY       => _t('Exam summary'),
            \CentralVet\Domain\AiPurpose::COPILOT_CLINICAL   => _t('Clinical copilot'),
            \CentralVet\Domain\AiPurpose::COPILOT_MANAGEMENT => _t('Management copilot'),
            default                                          => $purpose,
        };
    }

    /** Custo em US$ com 4 casas a partir de micro-USD. */
    public static function usd(int $microUsd): string
    {
        $pt = self::locale() === 'pt';

        return 'US$ ' . number_format($microUsd / 1000000, 4, $pt ? ',' : '.', $pt ? '.' : ',');
    }

    public static function number(int $value): string
    {
        $pt = self::locale() === 'pt';

        return number_format($value, 0, $pt ? ',' : '.', $pt ? '.' : ',');
    }

    private static function locale(): string
    {
        return \CentralVet\Ai\AiLocale::fromLanguage(ApplicationTranslator::getLanguage());
    }

    /** cv-card com cabeçalho; get(1) é o corpo. */
    private static function card(string $title): TElement
    {
        $card = new TElement('div');
        $card->{'class'} = 'cv-card';

        $header = new TElement('div');
        $header->{'class'} = 'cv-card__header';
        $header->add(TElement::tag('h2', CvFormat::e($title), ['class' => 'cv-card__title']));
        $card->add($header);

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        $card->add($body);

        return $card;
    }

    protected static function aiConfig(): \CentralVet\Ai\AiConfig
    {
        return \CentralVet\Ai\AiConfig::fromEnvironment();
    }

    protected static function makeService(\CentralVet\Tenancy\TenantContext $context, PDO $connection): \CentralVet\Application\AiSettingsService
    {
        return \CentralVet\Application\AiSettingsService::forContext($context, $connection);
    }

    /**
     * Executa `$work` numa transação 'permission' (commit no fim, rollback
     * em qualquer erro, que é relançado).
     */
    protected static function transaction(Closure $work): mixed
    {
        TTransaction::open('permission');

        try
        {
            $result = $work(TTransaction::get());
            TTransaction::close();

            return $result;
        }
        catch (Throwable $e)
        {
            TTransaction::rollback();
            throw $e;
        }
    }

    /**
     * Contexto de tenant da sessão autenticada, com o mesmo fallback de
     * CommunicationMessageList::resolveTenantContext() para sessões legadas.
     */
    protected static function resolveTenantContext(): \CentralVet\Tenancy\TenantContext
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

            $tenant_id = static::transaction(static function (PDO $connection) use ($userid)
            {
                $stmt = $connection->prepare('SELECT tenant_id FROM tenant_user WHERE system_user_id = :userid ORDER BY id ASC LIMIT 1');
                $stmt->execute(['userid' => (int) $userid]);

                return $stmt->fetchColumn();
            });

            if (empty($tenant_id))
            {
                throw $e;
            }

            $unit_id = TSession::getValue('userunitid');

            return \CentralVet\Tenancy\TenantContext::authenticated((int) $tenant_id, (int) $userid, $unit_id ? (int) $unit_id : null);
        }
    }
}

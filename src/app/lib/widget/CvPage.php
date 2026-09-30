<?php
/**
 * CvPage — estrutura de página do kit Cv*: cabeçalho, colunas 8/4, abas e barra de filtros.
 */
class CvPage
{
    /**
     * Cabeçalho com título grande, subtítulo, ações à direita e slot do seletor de unidade.
     *
     * Cada ação pode ser um widget pronto (TButton, TActionLink, TElement...) ou um array com as chaves:
     *  - 'label'  string       texto do botão (vazio: botão só com ícone);
     *  - 'action' TAction|null destino serializado; tem precedência sobre 'href';
     *  - 'href'   string|null  URL usada quando não há 'action' (padrão '#');
     *  - 'icon'   string|null  ícone do TImage (ex.: 'fa:plus');
     *  - 'class'  string|null  classes CSS (padrão 'btn btn-default');
     *  - 'title'  string|null  dica e, sem label, aria-label;
     *  - 'target' string|null  só '_blank' é aceito (ex.: downloads): o link sai com
     *    target="_blank" e rel="noopener", sem generator="adianti". Qualquer outro valor
     *    é ignorado e o link segue pelo roteador do Adianti, como sem a chave.
     * Voltar = ação com 'icon' => 'fa:arrow-left'.
     */
    public static function header(string $title, ?string $subtitle = null, array $actions = [], bool $unitSwitch = true): TElement
    {
        $header = new TElement('header');
        $header->{'class'} = 'cv-page-head';

        $text = new TElement('div');
        $text->{'class'} = 'cv-page-head__text';
        $text->add(TElement::tag('h1', CvFormat::e($title), ['class' => 'cv-page-head__title']));
        if ($subtitle !== null && $subtitle !== '')
        {
            $text->add(TElement::tag('p', CvFormat::e($subtitle), ['class' => 'cv-page-head__subtitle']));
        }
        $header->add($text);

        $side = new TElement('div');
        $side->{'class'} = 'cv-page-head__actions';

        foreach ($actions as $action)
        {
            $side->add(is_array($action) ? self::actionButton($action) : $action);
        }

        if ($unitSwitch)
        {
            $slot = new TElement('div');
            $slot->{'class'} = 'cv-unit-switch';
            $slot->{'data-cv-unit-switch'} = '';
            $slot->{'data-cv-label'} = CvFormat::e(_t('Unit'));
            $side->add($slot);
        }

        $header->add($side);

        return $header;
    }

    /**
     * Grid 8/4 (principal + lateral); empilha abaixo de 992px.
     */
    public static function columns(mixed $main, mixed $side): TElement
    {
        $grid = new TElement('div');
        $grid->{'class'} = 'cv-columns';

        $mainBox = new TElement('div');
        $mainBox->{'class'} = 'cv-columns__main';
        $mainBox->add($main);

        $sideBox = new TElement('aside');
        $sideBox->{'class'} = 'cv-columns__side';
        $sideBox->add($side);

        $grid->add($mainBox);
        $grid->add($sideBox);

        return $grid;
    }

    /**
     * Abas de navegação. $tabs = ['chave' => ['label' => string, 'href' => ?string]].
     * href null → aba desabilitada (classe cv-tab--disabled, título "Em breve").
     */
    public static function tabs(array $tabs, string $active): TElement
    {
        $nav = new TElement('nav');
        $nav->{'class'} = 'cv-tabs';

        $list = new TElement('ul');
        $list->{'class'} = 'cv-tabs__list';

        foreach ($tabs as $key => $tab)
        {
            $label = CvFormat::e((string) ($tab['label'] ?? $key));
            $href  = $tab['href'] ?? null;

            $item = new TElement('li');
            $item->{'class'} = 'cv-tabs__item';

            if ($href === null)
            {
                $item->add(TElement::tag('span', $label, [
                    'class'         => 'cv-tab cv-tab--disabled',
                    'title'         => CvFormat::e(_t('Coming soon')),
                    'aria-disabled' => 'true',
                ]));
            }
            else
            {
                $attributes = ['class' => 'cv-tab', 'href' => CvFormat::e($href), 'generator' => 'adianti'];
                if ((string) $key === $active)
                {
                    $attributes['class'] .= ' cv-tab--active';
                    $attributes['aria-current'] = 'page';
                }
                $item->add(TElement::tag('a', $label, $attributes));
            }

            $list->add($item);
        }

        $nav->add($list);

        return $nav;
    }

    /**
     * Barra de filtros em linha (busca + selects), no lugar da cortina do right panel.
     */
    public static function filterBar(array $elements): TElement
    {
        $bar = new TElement('div');
        $bar->{'class'} = 'cv-filter-bar';

        foreach ($elements as $element)
        {
            $cell = new TElement('div');
            $cell->{'class'} = 'cv-filter-bar__item';
            $cell->add($element);
            $bar->add($cell);
        }

        return $bar;
    }

    private static function actionButton(array $spec): TElement
    {
        $label = (string) ($spec['label'] ?? '');
        $icon  = $spec['icon'] ?? null;
        $href  = $spec['href'] ?? null;

        if (($spec['action'] ?? null) instanceof TAction)
        {
            $href = $spec['action']->serialize(TRUE, TRUE);
        }

        $link = new TElement('a');
        $link->{'class'} = CvFormat::e($spec['class'] ?? 'btn btn-default');
        $link->{'href'} = CvFormat::e($href ?? '#');
        if (($spec['target'] ?? null) === '_blank')
        {
            $link->{'target'} = '_blank';
            $link->{'rel'} = 'noopener';
        }
        else
        {
            $link->{'generator'} = 'adianti';
        }

        // nome acessível de ação só com ícone: title informado ou, no voltar, "Voltar"
        $title = !empty($spec['title']) ? (string) $spec['title'] : ($label === '' && $icon === 'fa:arrow-left' ? _t('Back') : '');
        if ($title !== '')
        {
            $link->{'title'} = CvFormat::e($title);
            if ($label === '')
            {
                $link->{'aria-label'} = CvFormat::e($title);
            }
        }

        if ($icon)
        {
            $link->add(new TImage($icon));
        }
        if ($label !== '')
        {
            $link->add(TElement::tag('span', CvFormat::e($label), []));
        }

        return $link;
    }
}

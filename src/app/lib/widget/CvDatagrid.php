<?php
/**
 * CvDatagrid — aparência de tabela do kit Cv*: cabeçalho claro, checkbox, menu "…" e rodapé paginado.
 */
class CvDatagrid
{
    /**
     * Aplica a classe cv-table e, com $checkbox, adiciona a coluna de seleção.
     * Chame logo após criar o wrapper e antes dos demais addColumn (a coluna entra na ordem de chamada).
     */
    public static function decorate(BootstrapDatagridWrapper $datagrid, bool $checkbox = true): void
    {
        $datagrid->{'class'} = 'table cv-table';
        $datagrid->{'style'} = 'width: 100%';

        if (!$checkbox)
        {
            return;
        }

        $selectAll = '<input type="checkbox" class="form-check-input cv-check-all" aria-label="' . CvFormat::e(_t('Select all')) . '"'
                   . ' onclick="var t=this.closest(\'table\');if(t){t.querySelectorAll(\'.cv-row-check\').forEach(function(c){c.checked=this.checked;},this);}">';

        $column = new TDataGridColumn('cv_row_check', $selectAll, 'center', '44px');
        $column->setProperty('class', 'cv-table__check');
        $column->setDataProperty('class', 'cv-table__check');
        $column->setTransformer(function ($value, $object) {
            $id = is_object($object) && isset($object->id) ? (string) $object->id : '';
            return '<input type="checkbox" class="form-check-input cv-row-check" value="' . CvFormat::e($id) . '"'
                 . ' aria-label="' . CvFormat::e(_t('Select row')) . '">';
        });

        $datagrid->addColumn($column);
    }

    /**
     * Agrupa ações de linha num botão "…" com dropdown.
     *
     * @param array $actions TDataGridAction já com label/imagem, ou
     *                       ['label' => string, 'action' => TDataGridAction, 'icon' => ?string]
     */
    public static function actionMenu(array $actions): TDataGridActionGroup
    {
        $group = new TDataGridActionGroup('', 'fa:ellipsis-h');

        foreach ($actions as $item)
        {
            if (is_array($item))
            {
                $action = $item['action'];
                if (isset($item['label']))
                {
                    $action->setLabel($item['label']);
                }
                if (isset($item['icon']))
                {
                    $action->setImage($item['icon']);
                }
                $group->addAction($action);
            }
            elseif ($item instanceof TAction)
            {
                $group->addAction($item);
            }
        }

        return $group;
    }

    /**
     * Rodapé "Mostrando X–Y de N <noun>" + paginação cv-pager.
     */
    public static function footer(TPageNavigation $pageNavigation, int $from, int $to, int $total, string $noun): TElement
    {
        $footer = new TElement('div');
        $footer->{'class'} = 'cv-table-footer';

        if ($total === 0)
        {
            $from = 0;
            $to   = 0;
        }

        $summary = str_replace(
            ['%1', '%2', '%3', '^1', '^2', '^3'],
            [$from, $to, $total, $from, $to, $total],
            _t('Showing %1–%2 of %3')
        );

        $footer->add(TElement::tag('div', CvFormat::e(trim($summary . ' ' . $noun)), ['class' => 'cv-table-footer__summary']));

        $pager = new TElement('div');
        $pager->{'class'} = 'cv-pager';
        $pager->add($pageNavigation);
        $footer->add($pager);

        return $footer;
    }
}

<?php
/**
 * CvForm — formulário em página cheia: rótulo acima do campo, grid de colunas e ações no rodapé do card.
 *
 * Linhas no formato addFields([label], [campo], [label], [campo]) viram pares rótulo/campo
 * distribuídos em $columns colunas (CSS grid em cv-components.css). Linhas com layout próprio
 * (setLayout) mantêm as classes informadas.
 */
class CvForm
{
    public static function decorate(BootstrapFormBuilder $form, int $columns = 2): void
    {
        $columns = max(1, min(4, $columns));

        $form->setProperty('class', 'card panel cv-form cv-form--cols-' . $columns);
        $form->setProperty('style', 'width: 100%; --cv-form-columns: ' . $columns);
        $form->setFieldSizes('100%');

        for ($slots = 1; $slots <= 12; $slots++)
        {
            $form->setColumnClasses($slots, array_fill(0, $slots, 'cv-form__slot'));
        }
    }
}

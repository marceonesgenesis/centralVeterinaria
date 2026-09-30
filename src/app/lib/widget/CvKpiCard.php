<?php
/**
 * CvKpiCard — indicador com ícone, valor, rótulo e variação (padrão: vs. mês anterior).
 */
class CvKpiCard
{
    /**
     * @param string     $icon         ícone Adianti (ex.: fa:box)
     * @param string     $tone         success|warning|danger|info|neutral
     * @param string     $value        valor já formatado
     * @param string     $label        rótulo já traduzido
     * @param float|null  $deltaPercent variação em %; null omite a linha de variação
     * @param string|null $deltaLabel   texto já traduzido após a variação; null = _t('vs. previous month')
     */
    public static function create(string $icon, string $tone, string $value, string $label, ?float $deltaPercent = null, ?string $deltaLabel = null): TElement
    {
        $tone = in_array($tone, CvBadge::TONES, true) ? $tone : 'neutral';

        $card = new TElement('div');
        $card->{'class'} = 'cv-kpi cv-kpi--' . $tone;

        $iconBox = new TElement('span');
        $iconBox->{'class'} = 'cv-kpi__icon';
        $iconBox->{'aria-hidden'} = 'true';
        $iconBox->add(new TImage($icon));

        $body = new TElement('div');
        $body->{'class'} = 'cv-kpi__body';
        $body->add(TElement::tag('div', CvFormat::e($label), ['class' => 'cv-kpi__label']));
        $body->add(TElement::tag('div', CvFormat::e($value), ['class' => 'cv-kpi__value']));

        if ($deltaPercent !== null)
        {
            $direction = $deltaPercent > 0 ? 'up' : ($deltaPercent < 0 ? 'down' : 'flat');
            $arrow     = ['up' => 'fa:arrow-up', 'down' => 'fa:arrow-down', 'flat' => 'fa:minus'][$direction];

            $delta = new TElement('div');
            $delta->{'class'} = 'cv-kpi__delta cv-kpi__delta--' . $direction;
            $deltaValue = new TElement('span');
            $deltaValue->{'class'} = 'cv-kpi__delta-value';
            $deltaValue->add(new TImage($arrow));
            $deltaValue->add(CvFormat::e(CvFormat::percent($deltaPercent)));
            $delta->add($deltaValue);
            $delta->add(TElement::tag('span', CvFormat::e($deltaLabel ?? _t('vs. previous month')), ['class' => 'cv-kpi__delta-label']));
            $body->add($delta);
        }

        $card->add($iconBox);
        $card->add($body);

        return $card;
    }
}

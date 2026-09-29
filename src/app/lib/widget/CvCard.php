<?php
/**
 * CvCard — card de seção com título e link opcional ("Ver tudo") no cabeçalho.
 */
class CvCard
{
    /**
     * @param mixed $content widget, TElement ou HTML já escapado
     */
    public static function create(string $title, mixed $content, ?string $linkLabel = null, ?string $linkHref = null): TElement
    {
        $card = new TElement('section');
        $card->{'class'} = 'cv-card';

        $header = new TElement('div');
        $header->{'class'} = 'cv-card__header';
        $header->add(TElement::tag('h2', CvFormat::e($title), ['class' => 'cv-card__title']));

        if ($linkLabel !== null && $linkHref !== null)
        {
            $header->add(TElement::tag('a', CvFormat::e($linkLabel), [
                'class'     => 'cv-card__link',
                'href'      => $linkHref,
                'generator' => 'adianti',
            ]));
        }

        $body = new TElement('div');
        $body->{'class'} = 'cv-card__body';
        if ($content !== null)
        {
            $body->add($content);
        }

        $card->add($header);
        $card->add($body);

        return $card;
    }
}

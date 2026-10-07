<?php
/**
 * CvBadge — selo de status com tom semântico.
 */
class CvBadge
{
    public const TONES = ['success', 'warning', 'danger', 'info', 'neutral'];

    /**
     * @param string $label rótulo já traduzido
     * @param string $tone  success|warning|danger|info|neutral (desconhecido → neutral)
     */
    public static function create(string $label, string $tone): TElement
    {
        $tone = in_array($tone, self::TONES, true) ? $tone : 'neutral';

        return TElement::tag('span', CvFormat::e($label), ['class' => 'cv-badge cv-badge--' . $tone]);
    }
}

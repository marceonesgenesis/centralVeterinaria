<?php
/**
 * CvSafeLabelTrait
 *
 * T-65: o select2 do framework (TDBUniqueSearch via AdiantiMultiSearchService
 * e TCombo com enableSearch) renderiza como HTML todo rótulo que contém tag.
 * O model expõe um atributo virtual <attr>_safe (get_<attr>_safe) com o texto
 * já escapado, e a máscara de busca o envolve em <span> (safeSearchMask): o
 * select2 interpreta o markup, mas o nome chega como texto literal, sem
 * &amp; visível. A coluna de busca e de ordem continua a coluna real.
 *
 * @package    lib
 * @subpackage widget
 */
trait CvSafeLabelTrait
{
    /**
     * Texto do atributo escapado para HTML (nulo vira '').
     */
    protected function safeLabel(string $attribute): string
    {
        return CvFormat::e((string) ($this->$attribute ?? ''));
    }

    /**
     * Máscara do TDBUniqueSearch / TDBCombo com enableSearch: o <span> faz o
     * select2 tratar o rótulo como markup, e o atributo _safe traz o texto
     * escapado, que o navegador mostra literal.
     */
    public static function safeSearchMask(string $safeAttribute): string
    {
        return '<span>{' . $safeAttribute . '}</span>';
    }
}

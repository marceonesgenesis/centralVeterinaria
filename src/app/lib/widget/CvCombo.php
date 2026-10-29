<?php

use Adianti\Widget\Base\TScript;

/**
 * CvCombo — recarga segura das opções de um TCombo (T-26).
 *
 * TCombo::reload do framework aplica só htmlspecialchars e põe o rótulo num
 * literal JS entre aspas simples, sem escapar `\`; tcombo_add_option concatena
 * o rótulo em HTML e passa a `$()`. Um rótulo digitado como
 * `\x3cimg src=x onerror=...\x3e` vira tag no navegador.
 *
 * Aqui form, campo e itens vão como literal JSON (JSON_HEX_TAG|AMP|APOS|QUOT)
 * e cada opção nasce com `new Option(texto, valor)` (grupo: `label` do
 * optgroup), então o rótulo é sempre texto. Mesma semântica de
 * TCombo::reload: limpa via tcombo_clear (com ou sem eventos), item vazio
 * opcional, chave `>>>...` abre um optgroup. Use sempre que o rótulo vier de
 * dado digitado por usuário. Não serve para combo com enableSearch (select2
 * do framework interpreta o texto como markup; ver CvSafeLabelTrait).
 *
 * @package    lib
 * @subpackage widget
 */
class CvCombo
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Substitui as opções do combo no navegador.
     *
     * @param array<int|string, mixed> $items valor => rótulo (na ordem)
     */
    public static function reload(string $formName, string $field, array $items, bool $startEmpty = false, bool $fireEvents = true): void
    {
        TScript::create(self::reloadScript($formName, $field, $items, $startEmpty, $fireEvents));
    }

    /**
     * Script de recarga (sem `<`, `>` nem quebra de linha vindos dos dados).
     * Os itens vão como lista de [valor, rótulo, é_grupo] para preservar a
     * ordem (objeto JS reordena chaves numéricas).
     *
     * @param array<int|string, mixed> $items
     */
    public static function reloadScript(string $formName, string $field, array $items, bool $startEmpty = false, bool $fireEvents = true): string
    {
        $list = [];

        foreach ($items as $key => $label)
        {
            $key = (string) $key;
            $list[] = [$key, (string) $label, substr($key, 0, 3) === '>>>'];
        }

        $form = self::literal($formName);
        $name = self::literal($field);
        $data = self::literal($list);
        $empty = $startEmpty ? 'true' : 'false';
        $fire = $fireEvents ? 'true' : 'false';

        return '(function (f, n, items, empty, fire) {'
            . ' tcombo_clear(f, n, fire);'
            . ' var combo = $(tfield_get_selector(f, n));'
            . ' var target = combo;'
            . " if (empty) { combo.append(new Option('', '')); }"
            . ' items.forEach(function (item) {'
            . " if (item[2]) { var group = document.createElement('optgroup'); group.label = item[1]; combo.append(group); target = $(group); }"
            . ' else { target.append(new Option(item[1], item[0])); }'
            . ' });'
            . " })({$form}, {$name}, {$data}, {$empty}, {$fire});";
    }

    private static function literal(mixed $value): string
    {
        return (string) json_encode($value, self::JSON_FLAGS);
    }
}

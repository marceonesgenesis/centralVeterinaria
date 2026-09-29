<?php
/**
 * CvWizard — etapas numeradas só no cliente: clicar na etapa n mostra #<prefixo><n> e oculta as demais.
 * Nada é enviado ao servidor; os painéis continuam no mesmo formulário.
 */
class CvWizard
{
    /**
     * @param array  $labels       rótulos das etapas, na ordem (numeradas a partir de 1)
     * @param int    $active       etapa ativa (1-based)
     * @param string $targetPrefix prefixo dos ids dos painéis (#<prefixo>1, #<prefixo>2...)
     */
    public static function steps(array $labels, int $active, string $targetPrefix): TElement
    {
        $total  = count($labels);
        $active = max(1, min($total > 0 ? $total : 1, $active));
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $targetPrefix);

        $wizard = new TElement('ol');
        $wizard->{'class'} = 'cv-wizard';
        $wizard->{'data-cv-wizard'} = $prefix;
        $wizard->{'data-cv-wizard-total'} = (string) $total;

        $n = 0;
        foreach ($labels as $label)
        {
            $n++;
            $classes = 'cv-wizard__step';
            if ($n < $active)
            {
                $classes .= ' cv-wizard__step--done';
            }
            if ($n === $active)
            {
                $classes .= ' cv-wizard__step--active';
            }

            $button = new TElement('button');
            $button->{'type'} = 'button';
            $button->{'class'} = 'cv-wizard__button';
            $button->{'data-cv-step'} = (string) $n;
            $button->{'aria-controls'} = $prefix . $n;
            if ($n === $active)
            {
                $button->{'aria-current'} = 'step';
            }
            $button->{'onclick'} = 'cvWizardGo(this.closest(\'.cv-wizard\'),' . $n . ')';
            $button->add(TElement::tag('span', (string) $n, ['class' => 'cv-wizard__number']));
            $button->add(TElement::tag('span', CvFormat::e((string) $label), ['class' => 'cv-wizard__label']));

            $item = new TElement('li');
            $item->{'class'} = $classes;
            $item->add($button);
            $wizard->add($item);
        }

        $wrapper = new TElement('div');
        $wrapper->{'class'} = 'cv-wizard-wrap';
        $wrapper->add($wizard);
        $wrapper->add(self::script($prefix, $active));

        return $wrapper;
    }

    private static function script(string $prefix, int $active): TElement
    {
        $code = "window.cvWizardGo = window.cvWizardGo || function (wizard, n) {"
              . " if (!wizard) { return; }"
              . " var prefix = wizard.getAttribute('data-cv-wizard');"
              . " var total = parseInt(wizard.getAttribute('data-cv-wizard-total'), 10) || 0;"
              . " for (var i = 1; i <= total; i++) {"
              . "  var panel = document.getElementById(prefix + i);"
              . "  if (panel) { panel.style.display = (i === n) ? '' : 'none'; }"
              . " }"
              . " wizard.querySelectorAll('.cv-wizard__step').forEach(function (step, index) {"
              . "  var k = index + 1;"
              . "  step.classList.toggle('cv-wizard__step--active', k === n);"
              . "  step.classList.toggle('cv-wizard__step--done', k < n);"
              . "  var b = step.querySelector('.cv-wizard__button');"
              . "  if (b) { if (k === n) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); } }"
              . " });"
              . "};"
              . " setTimeout(function () { var w = document.querySelector('.cv-wizard[data-cv-wizard=\"" . $prefix . "\"]'); window.cvWizardGo(w, " . $active . "); }, 0);";

        $script = new TElement('script');
        $script->{'type'} = 'text/javascript';
        $script->add($code);

        return $script;
    }
}

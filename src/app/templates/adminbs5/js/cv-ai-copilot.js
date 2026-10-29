/*
 * Copiloto (Fase 8B, T-14): estado "pensando…" da cortina AiCopilotPanel.
 *
 * O botão de envio chama CvAiCopilot.begin(this) antes do POST do Adianti.
 * No primeiro envio válido: desabilita o botão (disabled + aria-disabled),
 * marca o formulário com aria-busy="true" e anuncia o rótulo de
 * data-thinking-label na live region #cv-ai-copilot-status. Envio repetido
 * enquanto ocupado devolve false (o POST não sai). A resposta substitui a
 * cortina; se nada chegar em 60 s (orçamento do serviço: 45 s), o botão
 * volta a ficar ativo.
 *
 * Foco (T-22): a resposta substitui a cortina e o botão focado some. O
 * painel marca o shell com data-cv-copilot-focus="latest" quando a cortina
 * veio de um envio; ao carregar, este script foca a última resposta
 * ([data-cv-copilot-latest="1"], tabindex="-1") ou, sem resposta, o campo
 * question, e remove o atributo (o foco não volta a ser movido).
 */
(function () {
    'use strict';

    var RELEASE_MS = 60000;

    function statusRegion() {
        return document.getElementById('cv-ai-copilot-status');
    }

    function release(button, form) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        if (form) {
            form.setAttribute('aria-busy', 'false');
        }
        var status = statusRegion();
        if (status) {
            status.textContent = '';
        }
    }

    function focusLatest() {
        var shells = document.querySelectorAll('[data-cv-copilot-focus="latest"]');

        for (var i = 0; i < shells.length; i++) {
            var shell = shells[i];
            shell.removeAttribute('data-cv-copilot-focus');

            var target = shell.querySelector('[data-cv-copilot-latest="1"]')
                || shell.querySelector('textarea[name="question"]');

            if (target && typeof target.focus === 'function') {
                target.focus();
            }
        }
    }

    window.CvAiCopilot = {
        begin: function (button) {
            if (!button) {
                return true;
            }

            var form = button.form || (button.closest ? button.closest('form') : null);

            if (button.disabled || (form && form.getAttribute('aria-busy') === 'true')) {
                return false;
            }

            // formulário inválido: o Adianti mostra a validação e não envia
            if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) {
                return true;
            }

            if (form) {
                form.setAttribute('aria-busy', 'true');
            }
            button.setAttribute('aria-disabled', 'true');

            var status = statusRegion();
            if (status) {
                status.textContent = button.getAttribute('data-thinking-label') || '';
            }

            // desabilita depois que o onclick termina de montar o POST
            window.setTimeout(function () {
                button.disabled = true;
            }, 0);

            window.setTimeout(function () {
                if (document.body.contains(button)) {
                    release(button, form);
                }
            }, RELEASE_MS);

            return true;
        },
        focusLatest: focusLatest
    };

    // o script vem no fim da cortina: o shell já está no DOM; o setTimeout
    // espera o Adianti terminar de inserir o HTML do painel
    window.setTimeout(focusLatest, 0);
})();

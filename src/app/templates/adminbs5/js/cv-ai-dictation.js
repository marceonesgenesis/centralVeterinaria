/**
 * Central Vet Pro: ditado da anamnese pela Web Speech API (Fase 8A, T-10).
 *
 * window.CvDictation.attach(buttonId, textareaId, lang, unsupportedMessage)
 *   Liga o botão ao SpeechRecognition (ou webkitSpeechRecognition) do
 *   navegador com continuous e interimResults; só os resultados finais são
 *   acrescentados ao fim do textarea. Devolve true com suporte e false sem.
 *
 * Atributos do botão (textos traduzidos vêm do servidor; nada fixo aqui):
 *   data-label-start   rótulo para iniciar o ditado
 *   data-label-stop    rótulo para parar (também anunciado no status)
 *   data-label-denied  mensagem quando o navegador nega o microfone
 *   data-label-notice  aviso de privacidade exibido junto ao botão
 *
 * O estado é anunciado num elemento role="status" ao lado do botão (o
 * existente com .cv-ai-status logo depois dele, ou um criado aqui).
 *
 * Privacidade (ruling do usuário, Correção 1): a Web Speech API envia o
 * áudio ao serviço de reconhecimento de voz do fabricante do navegador
 * (ex.: Google, Apple, Microsoft). Por isso, com suporte, o texto de
 * data-label-notice aparece visível junto ao botão (.cv-ai-dictation-notice,
 * id "<buttonId>-notice", ligado por aria-describedby). Este arquivo não faz
 * requisição própria nem grava áudio; o envio é do navegador.
 */
(function (window, document) {
    'use strict';

    function recognitionConstructor() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function statusFor(button) {
        var next = button.nextElementSibling;
        if (next && next.getAttribute('role') === 'status') {
            return next;
        }
        var status = document.createElement('span');
        status.className = 'cv-ai-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        button.parentNode.insertBefore(status, button.nextSibling);
        return status;
    }

    function showNotice(button, status, text) {
        if (!text) {
            return;
        }
        var id = button.id + '-notice';
        var notice = document.getElementById(id);
        if (!notice) {
            notice = document.createElement('small');
            notice.id = id;
            notice.className = 'cv-ai-dictation-notice';
            status.parentNode.insertBefore(notice, status.nextSibling);
        }
        notice.textContent = text;
        var describedBy = button.getAttribute('aria-describedby') || '';
        if ((' ' + describedBy + ' ').indexOf(' ' + id + ' ') === -1) {
            button.setAttribute('aria-describedby', (describedBy + ' ' + id).trim());
        }
    }

    function setLabel(button, label) {
        if (!label) {
            return;
        }
        var target = button.querySelector('.cv-dictation-label');
        if (target) {
            target.textContent = label;
        } else {
            button.textContent = label;
        }
    }

    function appendFinal(textarea, text) {
        var chunk = String(text || '').trim();
        if (chunk === '') {
            return;
        }
        var current = textarea.value;
        var separator = current === '' || /\s$/.test(current) ? '' : ' ';
        textarea.value = current + separator + chunk;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function attach(buttonId, textareaId, lang, unsupportedMessage) {
        var button = document.getElementById(buttonId);
        var textarea = document.getElementById(textareaId);
        if (!button || !textarea) {
            return false;
        }

        var status = statusFor(button);
        var Recognition = recognitionConstructor();

        if (!Recognition) {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('title', unsupportedMessage || '');
            status.textContent = unsupportedMessage || '';
            return false;
        }

        var labelStart = button.getAttribute('data-label-start') || '';
        var labelStop = button.getAttribute('data-label-stop') || '';
        var labelDenied = button.getAttribute('data-label-denied') || '';
        showNotice(button, status, button.getAttribute('data-label-notice') || '');
        var listening = false;
        var denied = false;

        var recognition = new Recognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = lang === 'en-US' ? 'en-US' : 'pt-BR';

        function showIdle() {
            listening = false;
            button.setAttribute('aria-pressed', 'false');
            setLabel(button, labelStart);
            if (!denied) {
                status.textContent = '';
            }
        }

        recognition.onstart = function () {
            listening = true;
            denied = false;
            button.setAttribute('aria-pressed', 'true');
            setLabel(button, labelStop);
            status.textContent = labelStop;
        };

        recognition.onresult = function (event) {
            for (var i = event.resultIndex; i < event.results.length; i++) {
                if (event.results[i].isFinal) {
                    appendFinal(textarea, event.results[i][0].transcript);
                }
            }
        };

        recognition.onerror = function (event) {
            if (event && (event.error === 'not-allowed' || event.error === 'service-not-allowed')) {
                denied = true;
                status.textContent = labelDenied;
            }
        };

        recognition.onend = showIdle;

        button.setAttribute('aria-pressed', 'false');
        setLabel(button, labelStart);

        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (listening) {
                recognition.stop();
                return;
            }
            try {
                recognition.start();
            } catch (e) {
                // start() repetido antes do onend: o estado atual já vale.
            }
        });

        return true;
    }

    window.CvDictation = { attach: attach };
})(window, document);

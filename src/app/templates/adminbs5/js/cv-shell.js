/**
 * Central Vet Pro: comportamento da casca (T-08).
 *
 * - Preenche #cv-user-role com o papel (primeiro grupo) do usuário.
 * - Monta um <select> de unidade em cada [data-cv-unit-switch] (slots do
 *   CvPage::header, que chegam via __adianti_load_page depois do load).
 * - Envia a busca global #cv-global-search para GlobalSearchController::onSearch
 *   pelo carregador do Adianti, sem recarregar a página.
 *
 * - Desabilita os itens de menu sem tela (T-19): links para
 *   CvShellController::onComingSoon ganham .cv-menu-disabled, o selo
 *   labels.coming_soon e não navegam (hint=encounter acrescenta
 *   labels.open_from_encounter como dica).
 *
 * Dados: engine.php?class=CvShellController&method=onContext&static=1
 *   (inclui csrf_token e labels traduzidos; nenhum texto fixo aqui — T-26).
 * Troca de unidade: POST em CvShellController::onSwitchUnit com unit_id e
 *   csrf_token; resposta JSON {switched:true} ou {error}.
 */
var CvShell = (function () {
    'use strict';

    var CONTEXT_URL = 'engine.php?class=CvShellController&method=onContext&static=1';
    var SWITCH_URL = 'engine.php?class=CvShellController&method=onSwitchUnit';
    var contextPromise = null;
    var observer = null;
    var initialized = false;

    var MENU_DISABLED_MARK = 'method=onComingSoon';
    // Chave do parâmetro hint do menu → chave de labels do onContext.
    var MENU_HINT_LABELS = {
        encounter: 'open_from_encounter'
    };

    // [title] vira tooltip tippy com allowHTML (__adianti_process_tooltips).
    // No DOM o atributo não é decodificado: o texto escapado uma vez chega ao
    // tippy como texto, não marcação (equivale ao CvFormat::forHtmlSink).
    function cvEscapeTitle(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseJson(text) {
        var start = text.indexOf('{');
        var end = text.lastIndexOf('}');
        if (start === -1 || end < start) {
            throw new Error('CvShell: invalid context response');
        }
        return JSON.parse(text.slice(start, end + 1));
    }

    function loadContext(force) {
        if (!contextPromise || force) {
            contextPromise = fetch(CONTEXT_URL, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('CvShell: context HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(parseJson)
                .then(function (data) {
                    if (!data || !data.user || !Array.isArray(data.units)) {
                        throw new Error('CvShell: unexpected context payload');
                    }
                    return data;
                });
            contextPromise.catch(function () {
                contextPromise = null;
            });
        }
        return contextPromise;
    }

    function fillUserRole(context) {
        var role = document.getElementById('cv-user-role');
        if (role) {
            role.textContent = context.user.role || '';
        }
    }

    function currentUnitId(context) {
        for (var i = 0; i < context.units.length; i++) {
            if (context.units[i].current) {
                return String(context.units[i].id);
            }
        }
        return '';
    }

    function labelsOf(context) {
        return (context && context.labels) || {};
    }

    function showError(labels, message) {
        if (typeof __adianti_error === 'function') {
            __adianti_error(labels.error || '', message);
        } else {
            window.alert(message);
        }
    }

    function switchUnit(select, previous, context) {
        var unitId = select.value;
        if (!unitId || unitId === previous) {
            return;
        }
        var labels = labelsOf(context);
        // O select volta para a unidade atual até o servidor confirmar.
        select.value = previous;

        var body = new FormData();
        body.append('unit_id', unitId);
        body.append('csrf_token', context.csrf_token || '');

        fetch(SWITCH_URL, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body })
            .then(function (response) {
                return response.text();
            })
            .then(parseJson)
            .then(function (data) {
                if (data && data.switched) {
                    window.location.reload();
                    return;
                }
                showError(labels, (data && data.error) || labels.error || '');
            })
            .catch(function () {
                showError(labels, labels.error || '');
            });
    }

    function buildUnitSelect(slot, context) {
        if (slot.getAttribute('data-cv-unit-ready') === '1') {
            return;
        }
        slot.setAttribute('data-cv-unit-ready', '1');

        if (!context.units.length) {
            return;
        }

        var current = currentUnitId(context);
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm cv-unit-switch__select';
        var unitLabel = labelsOf(context).unit || slot.getAttribute('data-cv-label') || '';
        select.setAttribute('aria-label', unitLabel);
        select.title = cvEscapeTitle(unitLabel);

        // unidade única sem current: ela fica selecionada (o select segue disabled), sem placeholder
        var single = context.units.length === 1;

        if (!current && !single) {
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '—';
            placeholder.selected = true;
            placeholder.disabled = true;
            select.appendChild(placeholder);
        }

        context.units.forEach(function (unit) {
            var option = document.createElement('option');
            option.value = String(unit.id);
            option.textContent = unit.name;
            if (unit.current || single) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        if (context.units.length < 2) {
            select.disabled = true;
        }

        select.addEventListener('change', function () {
            switchUnit(select, current, context);
        });

        slot.appendChild(select);
    }

    function fillUnitSlots(context) {
        var slots = document.querySelectorAll('[data-cv-unit-switch]');
        for (var i = 0; i < slots.length; i++) {
            buildUnitSelect(slots[i], context);
        }
    }

    function hasPendingSlots() {
        return document.querySelector('[data-cv-unit-switch]:not([data-cv-unit-ready])') !== null;
    }

    function refresh() {
        loadContext(false)
            .then(function (context) {
                fillUserRole(context);
                decorateMenu(labelsOf(context));
                fillUnitSlots(context);
            })
            .catch(function () {
                // Sem permissão ou sessão expirada: a casca segue sem papel/seletor.
            });
    }

    function bindSearch() {
        var form = document.getElementById('cv-global-search');
        if (!form || form.getAttribute('data-cv-bound') === '1') {
            return;
        }
        form.setAttribute('data-cv-bound', '1');

        form.addEventListener('submit', function (event) {
            if (typeof __adianti_load_page !== 'function') {
                return; // fallback: GET normal do formulário
            }
            event.preventDefault();
            var input = form.querySelector('input[name="query"]');
            var term = input ? input.value.trim() : '';
            __adianti_load_page('index.php?class=GlobalSearchController&method=onSearch&query=' + encodeURIComponent(term));
        });
    }

    function queryParam(href, name) {
        var match = new RegExp('[?&]' + name + '=([^&#]*)').exec(href || '');
        return match ? decodeURIComponent(match[1]) : '';
    }

    // Neutraliza o link já no init (sem esperar o contexto); os textos
    // (selo e dica) entram quando labels chega do onContext.
    function disableMenuLink(link, labels) {
        if (!link.hasAttribute('data-cv-disabled-href')) {
            var item = link.closest('li');
            link.setAttribute('data-cv-disabled-href', link.getAttribute('href') || '');
            link.removeAttribute('href');
            link.removeAttribute('generator');
            link.classList.add('cv-menu-disabled');
            link.setAttribute('role', 'link');
            link.setAttribute('aria-disabled', 'true');
            if (item) {
                item.classList.add('cv-menu-disabled');
            }
        }

        var soon = labels.coming_soon || '';
        if (!soon) {
            return;
        }

        var label = link.querySelector('span:not(.cv-menu-soon)');
        var text = label ? label.textContent.trim() : link.textContent.trim();
        var hintKey = MENU_HINT_LABELS[queryParam(link.getAttribute('data-cv-disabled-href'), 'hint')];
        var hint = hintKey ? (labels[hintKey] || '') : '';
        link.setAttribute('title', cvEscapeTitle(text + ' — ' + (hint || soon)));

        var badge = link.querySelector('.cv-menu-soon');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'cv-menu-soon';
            link.appendChild(badge);
        }
        badge.textContent = soon;
    }

    function decorateMenu(labels) {
        var links = document.querySelectorAll(
            '#side-menu a[href*="' + MENU_DISABLED_MARK + '"], #side-menu a[data-cv-disabled-href]'
        );
        for (var i = 0; i < links.length; i++) {
            disableMenuLink(links[i], labels || {});
        }
    }

    function blockDisabledMenuClick(event) {
        var target = event.target && event.target.closest ? event.target.closest('a.cv-menu-disabled') : null;
        if (!target) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (event.stopImmediatePropagation) {
            event.stopImmediatePropagation();
        }
    }

    function watchSlots() {
        if (observer || typeof MutationObserver === 'undefined' || !document.body) {
            return;
        }
        var scheduled = false;
        observer = new MutationObserver(function () {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.setTimeout(function () {
                scheduled = false;
                if (hasPendingSlots()) {
                    refresh();
                }
            }, 50);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function init() {
        decorateMenu({});
        bindSearch();
        refresh();
        if (!initialized) {
            initialized = true;
            document.addEventListener('click', blockDisabledMenuClick, true);
            watchSlots();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        init: init,
        reload: function () {
            return loadContext(true).then(function (context) {
                fillUserRole(context);
                return context;
            });
        }
    };
})();

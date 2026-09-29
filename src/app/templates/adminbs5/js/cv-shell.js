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
 *   "Em breve" e não navegam (hint=encounter acrescenta "Abra pelo
 *   atendimento" como dica).
 *
 * Dados: engine.php?class=CvShellController&method=onContext&static=1
 * Troca de unidade: CvShellController::onSwitchUnit (unit_id).
 */
var CvShell = (function () {
    'use strict';

    var CONTEXT_URL = 'engine.php?class=CvShellController&method=onContext&static=1';
    var contextPromise = null;
    var observer = null;
    var initialized = false;

    var MENU_DISABLED_MARK = 'method=onComingSoon';
    var MENU_TEXT = {
        soon: 'Em breve',
        hints: {
            encounter: 'Abra pelo atendimento'
        }
    };

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

    function switchUnit(select, previous) {
        var unitId = select.value;
        if (!unitId || unitId === previous) {
            return;
        }
        var action = 'class=CvShellController&method=onSwitchUnit&unit_id=' + encodeURIComponent(unitId);
        if (typeof __adianti_ajax_exec === 'function') {
            // Sucesso: o servidor devolve um script que recarrega a página.
            // Erro: TMessage; o select volta para a unidade atual.
            select.value = previous;
            __adianti_ajax_exec(action);
        } else {
            window.location.href = 'engine.php?' + action;
        }
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
        select.setAttribute('aria-label', 'Unidade');
        select.title = 'Unidade';

        if (!current) {
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '—';
            select.appendChild(placeholder);
        }

        context.units.forEach(function (unit) {
            var option = document.createElement('option');
            option.value = String(unit.id);
            option.textContent = unit.name;
            if (unit.current) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        if (context.units.length < 2 && current) {
            select.disabled = true;
        }

        select.addEventListener('change', function () {
            switchUnit(select, current);
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

    function disableMenuLink(link) {
        var href = link.getAttribute('href') || '';
        var item = link.closest('li');
        var hint = MENU_TEXT.hints[queryParam(href, 'hint')] || '';
        var label = link.querySelector('span');
        var text = label ? label.textContent.trim() : link.textContent.trim();

        link.setAttribute('data-cv-disabled-href', href);
        link.removeAttribute('href');
        link.removeAttribute('generator');
        link.classList.add('cv-menu-disabled');
        link.setAttribute('role', 'link');
        link.setAttribute('aria-disabled', 'true');
        link.setAttribute('title', hint ? text + ' — ' + hint : text + ' — ' + MENU_TEXT.soon);
        if (item) {
            item.classList.add('cv-menu-disabled');
        }

        if (!link.querySelector('.cv-menu-soon')) {
            var badge = document.createElement('span');
            badge.className = 'cv-menu-soon';
            badge.textContent = MENU_TEXT.soon;
            link.appendChild(badge);
        }
    }

    function decorateMenu() {
        var links = document.querySelectorAll('#side-menu a[href*="' + MENU_DISABLED_MARK + '"]');
        for (var i = 0; i < links.length; i++) {
            disableMenuLink(links[i]);
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
        decorateMenu();
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

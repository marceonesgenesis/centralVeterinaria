/*
 * Landing pública do Central Vet Pro: planos, comparativo, carrinho de um
 * plano (localStorage "cvp-cart-plan") e envio do lead para POST /lead.php.
 *
 * Planos, preços (centavos), comparativo, opções de veterinários, UFs e o
 * texto do consentimento vêm de <script type="application/json"
 * id="cv-landing-data"> (LandingCatalog::publicJson). O token do formulário
 * vem de <meta name="cv-lead-token">. Nenhum preço é fixado aqui.
 */
(function () {
  "use strict";

  var LEAD_PATH = "/lead.php";
  var TOKEN_HEADER = "X-CV-Lead-Token";
  var HONEYPOT_FIELD = "website";
  var STORE_KEY = "cvp-cart-plan";
  var PLAN_COLUMNS = ["starter", "pro", "business", "enterprise"];
  var MSG_GENERIC = "Não conseguimos enviar agora. Verifique a conexão e tente de novo.";
  var MSG_RATE_LIMITED = "Muitos envios a partir desta rede. Tente de novo em alguns minutos.";
  var MSG_EXPIRED = "Sua sessão do formulário expirou. Recarregue a página e envie de novo.";
  var MSG_FIX_FIELDS = "Revise os campos destacados e envie de novo.";

  var $ = function (id) { return document.getElementById(id); };

  function readData() {
    var el = $("cv-landing-data");
    try { return el ? JSON.parse(el.textContent || "{}") : {}; } catch (e) { return {}; }
  }
  function readToken() {
    var meta = document.querySelector('meta[name="cv-lead-token"]');
    return meta ? meta.getAttribute("content") || "" : "";
  }

  var DATA = readData();
  var PLANS = Array.isArray(DATA.plans) ? DATA.plans : [];
  var COMPARE = Array.isArray(DATA.compare) ? DATA.compare : [];
  var VETS = DATA.vets && typeof DATA.vets === "object" ? DATA.vets : {};
  var UFS = Array.isArray(DATA.ufs) ? DATA.ufs : [];
  var CONSENT_TEXT = DATA.consent && DATA.consent.text ? String(DATA.consent.text) : "";
  var TOKEN = readToken();

  var brl = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
  var amount = new Intl.NumberFormat("pt-BR", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  var state = { planId: null, sent: null };
  try { var saved = localStorage.getItem(STORE_KEY); if (saved && planById(saved)) state.planId = saved; } catch (e) {}

  var checkIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function planById(id) { for (var i = 0; i < PLANS.length; i++) if (PLANS[i].id === id) return PLANS[i]; return null; }
  function reais(plan) { return Number(plan.price_cents) / 100; }
  function money(plan) { return brl.format(reais(plan)); }
  function persist() { try { if (state.planId) localStorage.setItem(STORE_KEY, state.planId); else localStorage.removeItem(STORE_KEY); } catch (e) {} }

  function renderPlans() {
    $("plans").innerHTML = PLANS.map(function (p) {
      var inCart = state.planId === p.id;
      var lis = (p.items || []).map(function (it) {
        return "<li>" + checkIcon + "<span>" + esc(it.label) + (it.soon ? ' <span class="tag tag-soon">Em breve</span>' : "") + "</span></li>";
      }).join("");
      return '<article class="plan' + (p.featured ? " featured" : "") + (inCart ? " in-cart" : "") + '">' +
        (p.featured ? '<span class="ribbon">Mais escolhido</span>' : "") +
        "<div><h3>" + esc(p.name) + '</h3><p class="for">' + esc(p.for_who) + "</p></div>" +
        '<div class="price"><span class="cur">R$</span><span class="val mono">' + esc(amount.format(reais(p))) + '</span><span class="per">/mês</span></div>' +
        '<p class="includes">Inclui</p><ul>' + lis + "</ul>" +
        '<button type="button" class="btn ' + (p.featured || inCart ? "btn-primary" : "btn-ghost") + '" data-add="' + esc(p.id) + '">' +
        (inCart ? "No carrinho ✓" : "Adicionar ao carrinho") + "</button></article>";
    }).join("");
  }

  function renderCompare() {
    $("compare-body").innerHTML = COMPARE.map(function (r) {
      var cells = PLAN_COLUMNS.map(function (id) {
        return r.plans && r.plans[id] ? '<td class="yes" aria-label="Incluído">✓</td>' : '<td class="no" aria-label="Não incluído">–</td>';
      }).join("");
      return "<tr><td>" + esc(r.label) + (r.soon ? ' <span class="tag tag-soon">Em breve</span>' : "") + "</td>" + cells + "</tr>";
    }).join("");
  }

  function renderCount() { $("cart-count").textContent = state.planId ? "1" : "0"; }

  function renderCart() {
    var body = $("cart-body"), foot = $("cart-foot"), plan = planById(state.planId);
    if (state.sent) {
      body.innerHTML = '<div class="done-box"><div class="seal">' + checkIcon.replace('aria-hidden="true"', 'width="34" height="34" aria-hidden="true"') + "</div>" +
        "<h3>Pedido recebido</h3><p>Obrigado, " + esc(state.sent.name) + ". Registramos o plano <b>" + esc(state.sent.plan) +
        "</b> para " + esc(state.sent.clinic) + ". Nossa equipe vai entrar em contato pelo WhatsApp ou e-mail informado para ativar a sua conta.</p></div>";
      foot.innerHTML = '<button type="button" class="btn btn-ghost" id="new-order">Fechar</button>';
      return;
    }
    if (!plan) {
      body.innerHTML = '<div class="empty"><p>Seu carrinho está vazio.</p><p>Escolha um plano para continuar.</p><a class="btn btn-primary" href="#planos" id="go-plans">Ver planos</a></div>';
      foot.innerHTML = "";
      return;
    }
    body.innerHTML =
      '<div class="line-item"><b>Plano ' + esc(plan.name) + '</b><span class="amt">' + money(plan) + "</span>" +
      "<small>" + esc(plan.for_who) + " · cobrança mensal</small>" + '<button type="button" id="remove-plan">Remover</button></div>' +
      '<div class="totals"><div><span>Mensalidade</span><span class="mono">' + money(plan) + "</span></div>" +
      '<div class="grand"><span>Total por mês</span><span>' + money(plan) + "</span></div></div>" +
      '<form class="form" id="lead-form" novalidate>' +
      "<h3>Seus dados para ativação</h3>" +
      field("lead-name", "Seu nome", "text", "name", "Ex.: Dra. Ana Ribeiro") +
      field("lead-clinic", "Nome da clínica", "text", "organization", "Ex.: Clínica Patas & Cia") +
      field("lead-email", "E-mail", "email", "email", "voce@clinica.com.br") +
      '<div class="row2">' + field("lead-phone", "WhatsApp", "tel", "tel", "(11) 91234-5678") +
      selectField("lead-vets", "Veterinários", vetsOptions()) + "</div>" +
      '<div class="row2">' + field("lead-city", "Cidade", "text", "address-level2", "Ex.: Campinas") +
      selectField("lead-uf", "UF", ufOptions()) + "</div>" +
      '<div class="hp" aria-hidden="true"><label for="lead-' + HONEYPOT_FIELD + '">Site</label>' +
      '<input id="lead-' + HONEYPOT_FIELD + '" name="' + HONEYPOT_FIELD + '" type="text" tabindex="-1" autocomplete="off" aria-hidden="true"></div>' +
      '<label class="check"><input type="checkbox" id="lead-consent"><span>' + esc(CONSENT_TEXT) + "</span></label>" +
      '<p class="field"><span class="err" id="consent-err"></span></p>' +
      "</form>";
    foot.innerHTML = '<button type="submit" form="lead-form" class="btn btn-primary" id="submit-lead">' + submitLabel(plan) + "</button>" +
      '<p class="status" id="lead-status" role="status">Nenhum pagamento é cobrado aqui. Confirmamos a assinatura com você.</p>';
  }

  function submitLabel(plan) { return "Enviar pedido · " + money(plan) + "/mês"; }

  function field(id, label, type, ac, ph) {
    return '<div class="field"><label for="' + id + '">' + label + '</label><input id="' + id + '" type="' + type + '" autocomplete="' + ac +
      '" placeholder="' + esc(ph) + '"><span class="err" id="' + id + '-err"></span></div>';
  }
  function selectField(id, label, options) {
    return '<div class="field"><label for="' + id + '">' + label + '</label><select id="' + id + '">' + options +
      '</select><span class="err" id="' + id + '-err"></span></div>';
  }
  function vetsOptions() {
    return Object.keys(VETS).map(function (k) { return '<option value="' + esc(k) + '">' + esc(VETS[k]) + "</option>"; }).join("");
  }
  function ufOptions() {
    return [""].concat(UFS).map(function (u) { return '<option value="' + esc(u) + '">' + (u ? esc(u) : "Selecione") + "</option>"; }).join("");
  }

  var lastFocus = null;
  function openCart() {
    lastFocus = document.activeElement;
    $("scrim").hidden = false;
    var d = $("cart"); d.classList.add("open"); d.setAttribute("aria-hidden", "false");
    renderCart();
    setTimeout(function () { $("close-cart").focus(); }, 30);
  }
  function closeCart() {
    $("scrim").hidden = true;
    var d = $("cart"); d.classList.remove("open"); d.setAttribute("aria-hidden", "true");
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function setErr(id, msg) { var el = $(id + "-err"); if (el) el.textContent = msg || ""; }
  function val(id) { var el = $(id); return el ? el.value.trim() : ""; }
  function errIdFor(key) { return key === "consent" ? "consent" : "lead-" + key; }
  function focusFirstError() {
    var first = document.querySelector("#lead-form .err:not(:empty)");
    if (first) { var f = first.parentElement.querySelector("input,select"); if (f) f.focus(); }
  }

  function showServerErrors(fields) {
    var unknown = [];
    Object.keys(fields || {}).forEach(function (key) {
      var el = $(errIdFor(key) + "-err");
      if (el) el.textContent = String(fields[key]); else unknown.push(String(fields[key]));
    });
    focusFirstError();
    return unknown.length ? unknown.join(" ") : MSG_FIX_FIELDS;
  }

  function submitLead(ev) {
    ev.preventDefault();
    var plan = planById(state.planId); if (!plan) return;
    var ok = true;
    var name = val("lead-name"), clinic = val("lead-clinic"), email = val("lead-email"), phone = val("lead-phone");
    var digits = phone.replace(/\D/g, "");
    setErr("lead-name", name.length >= 3 ? "" : "Informe seu nome."); ok = ok && name.length >= 3;
    setErr("lead-clinic", clinic.length >= 2 ? "" : "Informe o nome da clínica."); ok = ok && clinic.length >= 2;
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    setErr("lead-email", emailOk ? "" : "Informe um e-mail válido."); ok = ok && emailOk;
    var phoneOk = digits.length >= 10 && digits.length <= 13;
    setErr("lead-phone", phoneOk ? "" : "Informe o WhatsApp com DDD."); ok = ok && phoneOk;
    setErr("lead-vets", ""); setErr("lead-city", ""); setErr("lead-uf", "");
    var consent = $("lead-consent").checked;
    setErr("consent", consent ? "" : "Marque a autorização de contato para enviar.");
    ok = ok && consent;
    if (!ok) { focusFirstError(); return; }

    var btn = $("submit-lead"), status = $("lead-status");
    btn.disabled = true; btn.textContent = "Enviando…"; status.classList.remove("bad"); status.textContent = "Enviando seu pedido.";
    var honeypot = $("lead-" + HONEYPOT_FIELD);
    var payload = {
      name: name.slice(0, 120), clinic: clinic.slice(0, 160), email: email.slice(0, 160), phone: digits,
      vets: val("lead-vets"), city: val("lead-city").slice(0, 80), uf: val("lead-uf"),
      plan: plan.id, consent: true, website: honeypot ? honeypot.value : ""
    };
    var headers = { "Content-Type": "application/json" };
    headers[TOKEN_HEADER] = TOKEN;

    fetch(LEAD_PATH, { method: "POST", credentials: "same-origin", headers: headers, body: JSON.stringify(payload) })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (json) { return { status: res.status, json: json || {} }; });
      })
      .then(function (r) {
        if (r.status === 201 || r.status === 200) {
          state.sent = { name: name.split(" ")[0], plan: plan.name, clinic: clinic };
          state.planId = null; persist(); renderCount(); renderPlans(); renderCart();
          return;
        }
        var message = MSG_GENERIC;
        if (r.status === 422) message = showServerErrors(r.json.fields);
        else if (r.status === 429) message = MSG_RATE_LIMITED;
        else if (r.status === 403) message = MSG_EXPIRED;
        fail(message);
      })
      .catch(function () { fail(MSG_GENERIC); });

    function fail(message) {
      btn.disabled = false; btn.textContent = submitLabel(plan);
      status.classList.add("bad");
      status.textContent = message;
    }
  }

  document.addEventListener("click", function (ev) {
    var t = ev.target.closest("[data-add],#open-cart,#close-cart,#scrim,#remove-plan,#go-plans,#new-order");
    if (!t) return;
    if (t.hasAttribute("data-add")) { state.planId = t.getAttribute("data-add"); state.sent = null; persist(); renderCount(); renderPlans(); openCart(); return; }
    if (t.id === "open-cart") { openCart(); return; }
    if (t.id === "close-cart" || t.id === "scrim" || t.id === "go-plans") { closeCart(); return; }
    if (t.id === "remove-plan") { state.planId = null; persist(); renderCount(); renderPlans(); renderCart(); return; }
    if (t.id === "new-order") { state.sent = null; renderCart(); closeCart(); return; }
  });
  document.addEventListener("submit", function (ev) { if (ev.target && ev.target.id === "lead-form") submitLead(ev); });
  document.addEventListener("keydown", function (ev) { if (ev.key === "Escape" && $("cart").classList.contains("open")) closeCart(); });

  renderPlans(); renderCompare(); renderCount(); renderCart();
})();

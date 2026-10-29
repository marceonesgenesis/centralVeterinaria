/*
 * Landing pública do Central Vet Pro: planos, comparativo, carrinho de um
 * plano (localStorage "cvp-cart-plan") e envio do lead para POST /lead.php,
 * e a compra (seção #assinar) por POST /checkout.php, que devolve a URL do
 * Stripe Checkout; a volta (landing.php?checkout=success|cancelled) mostra o
 * resultado em #checkout-result.
 *
 * Planos, preços (centavos), comparativo, opções de veterinários, UFs e o
 * textos do consentimento e dos termos e as formas de pagamento vêm de <script type="application/json"
 * id="cv-landing-data"> (LandingCatalog::publicJson). O token do formulário
 * vem de <meta name="cv-lead-token">. Nenhum preço é fixado aqui.
 */
(function () {
  "use strict";

  var LEAD_PATH = "lead.php";
  var TOKEN_HEADER = "X-CV-Lead-Token";
  var HONEYPOT_FIELD = "website";
  var STORE_KEY = "cvp-cart-plan";
  var MSG_GENERIC = "Não conseguimos enviar agora. Verifique a conexão e tente de novo.";
  var MSG_RATE_LIMITED = "Muitos envios a partir desta rede. Tente de novo em alguns minutos.";
  var MSG_EXPIRED = "Sua sessão do formulário expirou. Recarregue a página e envie de novo.";
  var MSG_FIX_FIELDS = "Revise os campos destacados e envie de novo.";
  var CHECKOUT_PATH = "checkout.php";
  var MSG_GATEWAY = "Não foi possível iniciar o pagamento. Tente de novo em instantes.";
  var MSG_PAID = "Pagamento recebido. Você receberá por e-mail o convite para definir sua senha.";
  var MSG_CANCELLED = "Pagamento não concluído. Nenhum valor foi cobrado.";
  /* Chave do 422 de checkout.php => sufixo do campo na seção #assinar. */
  var CHECKOUT_FIELDS = {
    plan_id: "plan", payment_method: "method", responsible_name: "name", responsible_email: "email",
    responsible_phone: "phone", clinic_name: "clinic", terms_accepted: "terms", terms_version: "terms", contact_consent: "consent"
  };

  var $ = function (id) { return document.getElementById(id); };
  var I18N = window.CvLandingI18n;

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
  /* Colunas do comparativo = planos ofertados (LandingCatalog::publicJson filtra por OfferScope). */
  var PLAN_COLUMNS = PLANS.map(function (p) { return String(p.id); });
  var VETS = DATA.vets && typeof DATA.vets === "object" ? DATA.vets : {};
  var UFS = Array.isArray(DATA.ufs) ? DATA.ufs : [];
  var CONSENT_TEXT = DATA.consent && DATA.consent.text ? String(DATA.consent.text) : "";
  var TERMS = DATA.terms && typeof DATA.terms === "object" ? DATA.terms : {};
  var METHODS = Array.isArray(DATA.payment_methods) ? DATA.payment_methods : [];
  var TOKEN = readToken();

  var brl = new Intl.NumberFormat(I18N.locale(), { style: "currency", currency: "BRL" });
  var amount = new Intl.NumberFormat(I18N.locale(), { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  var state = { planId: null, sent: null, subscribePlanId: null };
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
        '<div class="plan-actions"><button type="button" class="btn btn-primary" data-plan="' + esc(p.id) + '">Assinar</button>' +
        '<button type="button" class="btn btn-ghost" data-add="' + esc(p.id) + '">' +
        (inCart ? "No carrinho ✓" : "Adicionar ao carrinho") + "</button></div></article>";
    }).join("");
    I18N.translate($("plans"));
  }

  function renderCompare() {
    $("compare-head").innerHTML = '<th scope="col">Recurso</th>' + PLANS.map(function (p) {
      return '<th scope="col">' + esc(p.name) + "</th>";
    }).join("");
    I18N.translate($("compare-head"));
    $("compare-body").innerHTML = COMPARE.map(function (r) {
      var cells = PLAN_COLUMNS.map(function (id) {
        return r.plans && r.plans[id] ? '<td class="yes" aria-label="Incluído">✓</td>' : '<td class="no" aria-label="Não incluído">–</td>';
      }).join("");
      return "<tr><td>" + esc(r.label) + (r.soon ? ' <span class="tag tag-soon">Em breve</span>' : "") + "</td>" + cells + "</tr>";
    }).join("");
    I18N.translate($("compare-body"));
  }

  function renderCount() { $("cart-count").textContent = state.planId ? "1" : "0"; }

  function renderCart() {
    renderCartContent();
    I18N.translate($("cart-body"));
    I18N.translate($("cart-foot"));
  }

  function renderCartContent() {
    var body = $("cart-body"), foot = $("cart-foot"), plan = planById(state.planId);
    if (state.sent) {
      body.innerHTML = '<div class="done-box"><div class="seal">' + checkIcon.replace('aria-hidden="true"', 'width="34" height="34" aria-hidden="true"') + "</div>" +
        '<h3 id="lead-done-title" tabindex="-1">Pedido recebido</h3><p><span>Obrigado,</span> <span data-no-i18n>' + esc(state.sent.name) + '</span>. <span>Registramos o plano</span> <b>' + esc(state.sent.plan) +
        '</b> <span>para</span> <span data-no-i18n>' + esc(state.sent.clinic) + '</span>. <span>Nossa equipe vai entrar em contato pelo WhatsApp ou e-mail informado para ativar a sua conta.</span></p></div>';
      foot.innerHTML = '<button type="button" class="btn btn-ghost" id="new-order">Fechar</button>';
      return;
    }
    if (!plan) {
      body.innerHTML = '<div class="empty"><p>Seu carrinho está vazio.</p><p>Escolha um plano para continuar.</p><a class="btn btn-primary" href="#planos" id="go-plans">Ver planos</a></div>';
      foot.innerHTML = "";
      return;
    }
    body.innerHTML =
      '<div class="line-item"><b><span>Plano</span> ' + esc(plan.name) + '</b><span class="amt" data-money-cents="' + Number(plan.price_cents) + '">' + money(plan) + "</span>" +
      "<small><span>" + esc(plan.for_who) + "</span> · <span>cobrança mensal</span></small>" + '<button type="button" id="remove-plan">Remover</button></div>' +
      '<div class="totals"><div><span>Mensalidade</span><span class="mono" data-money-cents="' + Number(plan.price_cents) + '">' + money(plan) + "</span></div>" +
      '<div class="grand"><span>Total por mês</span><span data-money-cents="' + Number(plan.price_cents) + '">' + money(plan) + "</span></div></div>" +
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
      '<label class="check"><input type="checkbox" id="lead-consent" aria-describedby="consent-err"><span>' + esc(CONSENT_TEXT) + "</span></label>" +
      '<p class="field"><span class="err" id="consent-err"></span></p>' +
      "</form>";
    foot.innerHTML = '<button type="submit" form="lead-form" class="btn btn-primary" id="submit-lead">' + submitLabel(plan) + "</button>" +
      '<p class="status" id="lead-status" role="status">Nenhum pagamento é cobrado aqui. Confirmamos a assinatura com você.</p>';
  }

  function submitLabel(plan) { return I18N.t("Enviar pedido") + " · " + money(plan) + I18N.t("/mês"); }

  function field(id, label, type, ac, ph) {
    return '<div class="field"><label for="' + id + '">' + label + '</label><input id="' + id + '" type="' + type + '" autocomplete="' + ac +
      '" placeholder="' + esc(ph) + '" aria-describedby="' + id + '-err"><span class="err" id="' + id + '-err"></span></div>';
  }
  function selectField(id, label, options) {
    return '<div class="field"><label for="' + id + '">' + label + '</label><select id="' + id + '" aria-describedby="' + id + '-err">' + options +
      '</select><span class="err" id="' + id + '-err"></span></div>';
  }
  function vetsOptions() {
    return Object.keys(VETS).map(function (k) { return '<option value="' + esc(k) + '">' + esc(VETS[k]) + "</option>"; }).join("");
  }
  function ufOptions() {
    return [""].concat(UFS).map(function (u) { return '<option value="' + esc(u) + '">' + (u ? esc(u) : "Selecione") + "</option>"; }).join("");
  }

  var lastFocus = null;
  function pageSiblings() {
    return [document.querySelector("header.top"), $("inicio"), document.querySelector("body > footer")].filter(Boolean);
  }
  function openCart() {
    lastFocus = document.activeElement;
    $("cart").inert = false;
    pageSiblings().forEach(function (el) { el.inert = true; });
    $("scrim").hidden = false;
    $("cart").classList.add("open");
    renderCart();
    setTimeout(function () { $("close-cart").focus(); }, 30);
  }
  function closeCart() {
    $("cart").inert = true;
    pageSiblings().forEach(function (el) { el.inert = false; });
    $("scrim").hidden = true;
    $("cart").classList.remove("open");
    var target = lastFocus && lastFocus.isConnected && lastFocus !== document.body ? lastFocus : $("open-cart");
    lastFocus = null;
    if (target && target.focus) target.focus();
  }

  function setErr(id, msg) {
    var el = $(id + "-err"); if (el) { el.textContent = msg || ""; I18N.translate(el); }
    var control = $(id === "consent" ? "lead-consent" : id);
    if (control) { if (msg) control.setAttribute("aria-invalid", "true"); else control.removeAttribute("aria-invalid"); }
  }
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
      if (el) setErr(errIdFor(key), String(fields[key])); else unknown.push(String(fields[key]));
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
    I18N.translate(btn); I18N.translate(status);
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
          $("lead-done-title").focus();
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
      I18N.translate(status);
    }
  }

  /* Compra pela landing: seção #assinar e POST /checkout.php (T-16). */
  function renderMethods() {
    var box = $("sub-methods");
    if (!box || box.childElementCount) return;
    box.innerHTML = METHODS.map(function (m, i) {
      var id = "sub-method-" + esc(m.id);
      return '<label class="method" for="' + id + '"><input type="radio" name="payment_method" id="' + id + '" value="' + esc(m.id) + '"' +
        (i === 0 ? " checked" : "") + "><span>" + esc(m.label) + "</span></label>";
    }).join("");
    I18N.translate(box);
  }

  function renderSubscribePlan() {
    var plan = planById(state.subscribePlanId); if (!plan) return;
    $("sub-plan-name").textContent = plan.name;
    var price = $("sub-plan-price");
    price.setAttribute("data-money-cents", String(Number(plan.price_cents)));
    price.textContent = money(plan);
  }

  function openSubscribe(planId) {
    if (!planById(planId)) return;
    state.subscribePlanId = planId;
    var section = $("assinar");
    $("sub-terms-text").textContent = TERMS.text ? String(TERMS.text) : "";
    $("sub-consent-text").textContent = CONSENT_TEXT;
    renderMethods(); renderSubscribePlan(); subErr("plan", "");
    section.hidden = false;
    I18N.translate(section);
    section.scrollIntoView({ block: "start" });
    $("subscribe-title").focus({ preventScroll: true });
  }

  function subErr(suffix, msg) {
    var el = $("sub-" + suffix + "-err"); if (el) { el.textContent = msg || ""; I18N.translate(el); }
    var controls = suffix === "method" ? document.querySelectorAll('#sub-methods input') : [$("sub-" + suffix)];
    Array.prototype.forEach.call(controls, function (control) {
      if (!control || control.tagName !== "INPUT") return;
      if (msg) control.setAttribute("aria-invalid", "true"); else control.removeAttribute("aria-invalid");
    });
  }
  function focusFirstSubscribeError() {
    var first = document.querySelector("#subscribe-form .err:not(:empty)");
    if (!first) return;
    var f = first.parentElement.querySelector("input,a[href]"); if (f) f.focus();
  }
  function chosenMethod() {
    var checked = document.querySelector('#sub-methods input[name="payment_method"]:checked');
    return checked ? checked.value : "";
  }

  function submitSubscribe(ev) {
    ev.preventDefault();
    var plan = planById(state.subscribePlanId);
    var name = val("sub-name"), email = val("sub-email"), phone = val("sub-phone"), clinic = val("sub-clinic"), method = chosenMethod();
    var digits = phone.replace(/\D/g, ""), ok = true;
    function check(suffix, valid, msg) { subErr(suffix, valid ? "" : msg); ok = ok && valid; }
    check("plan", !!plan, "Escolha um plano.");
    check("name", name.length >= 3, "Informe o nome do responsável.");
    check("email", /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email), "Informe um e-mail válido.");
    check("phone", digits.length >= 10 && digits.length <= 13, "Informe o telefone com DDD.");
    check("clinic", clinic.length >= 2, "Informe o nome da clínica.");
    check("method", method !== "", "Escolha a forma de pagamento: cartão ou Pix.");
    var terms = $("sub-terms").checked;
    check("terms", terms, "Aceite os Termos de Uso e a Política de Privacidade para continuar.");
    subErr("consent", "");
    if (!ok) { focusFirstSubscribeError(); return; }

    var btn = $("submit-subscribe"), status = $("subscribe-status");
    btn.disabled = true; btn.textContent = "Abrindo o pagamento…"; status.classList.remove("bad"); status.textContent = "";
    I18N.translate(btn);
    var honeypot = $("sub-website");
    var payload = {
      plan_id: plan.id, payment_method: method,
      responsible_name: name.slice(0, 160), responsible_email: email.slice(0, 190), responsible_phone: phone.slice(0, 30), clinic_name: clinic.slice(0, 190),
      terms_accepted: terms === true, terms_version: String(TERMS.version || ""), contact_consent: $("sub-consent").checked === true,
      form_token: TOKEN, website: honeypot ? honeypot.value : ""
    };

    fetch(CHECKOUT_PATH, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (json) { return { status: res.status, json: json || {} }; });
      })
      .then(function (r) {
        var url = r.status === 201 && typeof r.json.checkout_url === "string" ? r.json.checkout_url : "";
        if (/^https:\/\//.test(url)) { window.location.assign(url); return; }
        var message = MSG_GENERIC;
        if (r.status === 422) message = showCheckoutErrors(r.json.errors);
        else if (r.status === 502) message = MSG_GATEWAY;
        else if (r.status === 429) message = MSG_RATE_LIMITED;
        else if (r.status === 403) message = MSG_EXPIRED;
        fail(message);
      })
      .catch(function () { fail(MSG_GENERIC); });

    function fail(message) {
      btn.disabled = false; btn.textContent = "Ir para o pagamento"; I18N.translate(btn);
      status.classList.add("bad");
      status.textContent = message;
      I18N.translate(status);
    }
  }

  function showCheckoutErrors(errors) {
    var unknown = [];
    Object.keys(errors || {}).forEach(function (key) {
      var suffix = CHECKOUT_FIELDS[key];
      if (suffix && $("sub-" + suffix + "-err")) subErr(suffix, String(errors[key])); else unknown.push(String(errors[key]));
    });
    focusFirstSubscribeError();
    return unknown.length ? unknown.join(" ") : MSG_FIX_FIELDS;
  }

  function showCheckoutReturn() {
    var result = "";
    try { result = new URLSearchParams(window.location.search).get("checkout") || ""; } catch (e) { return; }
    var message = result === "success" ? MSG_PAID : result === "cancelled" ? MSG_CANCELLED : "";
    var box = $("checkout-result");
    if (!message || !box) return;
    box.textContent = message;
    box.classList.toggle("ok", result === "success");
    box.hidden = false;
    I18N.translate(box);
    box.focus();
  }

  document.addEventListener("click", function (ev) {
    var plan = ev.target.closest("[data-plan]");
    if (plan) { openSubscribe(plan.getAttribute("data-plan")); return; }
    var t = ev.target.closest("[data-add],#open-cart,#close-cart,#scrim,#remove-plan,#go-plans,#new-order");
    if (!t) return;
    if (t.hasAttribute("data-add")) { state.planId = t.getAttribute("data-add"); state.sent = null; persist(); renderCount(); renderPlans(); openCart(); return; }
    if (t.id === "open-cart") { openCart(); return; }
    if (t.id === "close-cart" || t.id === "scrim" || t.id === "go-plans") { closeCart(); return; }
    if (t.id === "remove-plan") { state.planId = null; persist(); renderCount(); renderPlans(); renderCart(); $("go-plans").focus(); return; }
    if (t.id === "new-order") { state.sent = null; renderCart(); closeCart(); return; }
  });
  document.addEventListener("submit", function (ev) {
    if (ev.target && ev.target.id === "lead-form") submitLead(ev);
    else if (ev.target && ev.target.id === "subscribe-form") submitSubscribe(ev);
  });
  function cartFocusables() {
    var selector = "a[href],button,input,select,textarea,[tabindex]";
    return Array.prototype.filter.call($("cart").querySelectorAll(selector), function (el) {
      if (el.disabled || el.hidden || el.getAttribute("tabindex") === "-1") return false;
      if (el.closest("[hidden],[inert],[aria-hidden='true']")) return false;
      return el.getClientRects().length > 0 && getComputedStyle(el).visibility !== "hidden";
    });
  }
  function wrapTab(ev) {
    var cart = $("cart"), items = cartFocusables();
    if (!items.length) { ev.preventDefault(); return; }
    var first = items[0], last = items[items.length - 1], active = document.activeElement;
    var outside = !cart.contains(active);
    if (ev.shiftKey && (outside || active === first)) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && (outside || active === last)) { ev.preventDefault(); first.focus(); }
  }
  document.addEventListener("keydown", function (ev) {
    if (!$("cart").classList.contains("open")) return;
    if (ev.key === "Escape") { closeCart(); return; }
    if (ev.key === "Tab") wrapTab(ev);
  });
  document.addEventListener("cv:languagechange", function () {
    brl = new Intl.NumberFormat(I18N.locale(), { style: "currency", currency: "BRL" });
    amount = new Intl.NumberFormat(I18N.locale(), { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    renderPlans(); renderCompare(); renderSubscribePlan();
    var button = $("submit-lead"), plan = planById(state.planId);
    if (button && plan && !button.disabled) button.textContent = submitLabel(plan);
  });

  /* Volta do Stripe pelo botão Voltar (bfcache): destrava o envio. */
  window.addEventListener("pageshow", function (ev) {
    var btn = $("submit-subscribe");
    if (!ev.persisted || !btn || !btn.disabled) return;
    btn.disabled = false; btn.textContent = "Ir para o pagamento"; I18N.translate(btn);
  });

  renderPlans(); renderCompare(); renderCount(); renderCart(); showCheckoutReturn();
  if (window.location.hash === "#cart") openCart();
})();

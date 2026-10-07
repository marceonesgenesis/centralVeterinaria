/* Language selection for the public landing; no server or database writes. */
(function () {
  "use strict";
  var STORAGE_KEY = "cvp-language";
  var locales = { pt: "pt-BR", en: "en-US", es: "es" };
  var language = "pt";
  var originals = new WeakMap();
  var attributes = new WeakMap();
  var dictionaries = window.CvLandingTranslations || {};
  try {
    var saved = localStorage.getItem(STORAGE_KEY);
    if (Object.prototype.hasOwnProperty.call(locales, saved)) language = saved;
  } catch (error) {}

  function t(source) {
    var dictionary = dictionaries[language];
    return dictionary && Object.prototype.hasOwnProperty.call(dictionary, source) ? dictionary[source] : source;
  }

  function translate(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var node;
    while ((node = walker.nextNode())) {
      if (!node.parentElement || node.parentElement.closest("script,style,[data-no-i18n]")) continue;
      if (!originals.has(node)) originals.set(node, node.nodeValue);
      var source = originals.get(node);
      var trimmed = source.trim();
      if (trimmed) node.nodeValue = source.replace(trimmed, t(trimmed));
    }
    var elements = Array.from(root.querySelectorAll("[aria-label],[placeholder],[title],meta[name='description']"));
    if (root.nodeType === Node.ELEMENT_NODE) elements.unshift(root);
    elements.forEach(function (element) {
      if (element.closest("[data-no-i18n]")) return;
      var savedAttributes = attributes.get(element) || {};
      ["aria-label", "placeholder", "title"].concat(element.matches("meta[name='description']") ? ["content"] : []).forEach(function (name) {
        if (!element.hasAttribute(name)) return;
        if (!Object.prototype.hasOwnProperty.call(savedAttributes, name)) savedAttributes[name] = element.getAttribute(name);
        element.setAttribute(name, t(savedAttributes[name]));
      });
      attributes.set(element, savedAttributes);
    });
    root.querySelectorAll("[data-money-cents]").forEach(function (element) {
      element.textContent = new Intl.NumberFormat(locales[language], { style: "currency", currency: "BRL" }).format(Number(element.getAttribute("data-money-cents")) / 100);
    });
  }

  function applyLanguage() {
    document.documentElement.lang = locales[language];
    document.querySelectorAll("[data-language-picker]").forEach(function (picker) { picker.value = language; });
    translate(document);
  }

  window.CvLandingI18n = {
    t: t,
    translate: translate,
    locale: function () { return locales[language]; },
    language: function () { return language; }
  };
  function selectLanguage(event) {
    var requested = event.target.value;
    if (!Object.prototype.hasOwnProperty.call(locales, requested)) return;
    language = requested;
    try { localStorage.setItem(STORAGE_KEY, language); } catch (error) {}
    applyLanguage();
    document.dispatchEvent(new CustomEvent("cv:languagechange"));
  }
  document.querySelectorAll("[data-language-picker]").forEach(function (picker) { picker.addEventListener("change", selectLanguage); });
  applyLanguage();
})();

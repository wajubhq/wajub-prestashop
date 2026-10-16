/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The payment form's scripts in plain Node, no dependency: fields-core.js
 * (the site's look handed to Wajub.js, the form mounted behind its
 * skeleton, prepared with the shop, submitted and followed), then
 * checkout-fields.js on PrestaShop's payment step as the Classic theme and
 * Hummingbird draw it (the form mounted when its option is selected,
 * "Place order" taken over, the order placed, the form submitted, the payer
 * sent on, or the order given back as a cart), then order-status.js on the
 * order confirmation page, and recount.js, which asks the shop to read a
 * payment again. Against tests/fakes/fields-dom.js.
 *
 *     node tests/run-fields-js-tests.js
 */
"use strict";

const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const F = require("./fakes/fields-dom.js");

let run = 0;
let failed = 0;
function check(condition, message) {
  run++;
  if (!condition) {
    failed++;
    process.stdout.write("FAIL: " + message + "\n");
  }
}

const source = (file) => fs.readFileSync(path.join(__dirname, "..", "views", "js", file), "utf8");
const settle = () => new Promise((resolve) => setImmediate(resolve));
async function flush() {
  for (let i = 0; i < 8; i++) {
    await settle();
  }
}

function computedStyle(node, pseudo) {
  if (pseudo) {
    return (node.pseudo && node.pseudo[pseudo]) || {};
  }
  return node.computed || {};
}

function canon(value) {
  if (Array.isArray(value)) {
    return "[" + value.map(canon).join(",") + "]";
  }
  if (value && typeof value === "object") {
    return "{" + Object.keys(value).sort().map((k) => JSON.stringify(k) + ":" + canon(value[k])).join(",") + "}";
  }
  return JSON.stringify(value);
}

const URLS = {
  prepare: "https://shop.test/module/wajub/prepare",
  validation: "https://shop.test/module/wajub/validation",
  release: "https://shop.test/module/wajub/release",
  status: "https://shop.test/module/wajub/status",
};
const CONFIRMATION = "https://shop.test/order-confirmation?id_order=9&key=k";
const HOSTED = "https://pay.wajub.com/trx_test_1?locale=fr";

/** The box's words (Checkout\CheckoutStrings::fields()). */
function words(kind) {
  const momo = kind === "mobile_money";
  return {
    legend: momo ? "Mobile Money payment details" : "Card payment details",
    frameTitle: momo ? "Formulaire de paiement Mobile Money sécurisé" : "Formulaire de paiement par carte sécurisé",
    incomplete: momo ? "Enter the Mobile Money number to pay with." : "Enter your card details to pay.",
    paying: momo ? "Approve the payment on your phone: your operator sends you a request." : "Complete the payment in the form above.",
    loading: "Loading the secure payment form…",
    placing: "Placing your order…",
    hostedHint: "You will pay on a secure Wajub page, then come back here.",
    fallback: "The payment form could not be loaded here. Place your order to pay on a secure Wajub page.",
    unavailable: "This payment method cannot be used for this order. Choose another payment method.",
    amountOutOfRange: "This amount cannot be paid with this payment method. Choose another payment method.",
    notConfigured: "Online payment is temporarily unavailable in this shop.",
    temporarilyUnavailable: "The payment service is temporarily unavailable. Please try again in a few moments.",
    refreshed: "Your payment details were refreshed. Check them, then place your order again.",
    checking: "Checking your payment…",
    paid: "Payment received. Taking you to your order…",
    confirming: "Your payment is still being confirmed. Taking you to your order…",
    declined: "The payment did not go through. Check the details and try again, or use another payment method.",
    nothingHappened: "Nothing happened? Check the details above, then Try again.",
    cancelled: "Payment not completed. You can try again or choose another payment method.",
    expired: "Your payment session expired. Place your order again to start a new payment.",
    failed: "The payment was declined or failed. Please try again or choose another payment method.",
    leaving: "Getting your cart back…",
    redirecting: "Taking you to the secure Wajub payment page…",
    tryAgain: "Try again",
    useAnother: "Use another payment method",
    continueHosted: "Continue on the secure Wajub page",
    startFailed: "Your payment could not be started. Please try again in a moment.",
  };
}

function config(kind) {
  return {
    kind,
    component: kind === "mobile_money" ? "mobileMoney" : "card",
    locale: "fr",
    appearance: { theme: "system" },
    componentOptions: { variant: "fields", fields: { customer: "external" } },
    i18n: words(kind),
  };
}

/**
 * A payment option as PrestaShop's payment step draws it: its radio, its
 * additional information (payment-info.tpl, the box in it) and its form
 * (payment-fields.tpl), with the cart's token and id, and the invoice
 * country, as the shop drew them (`drawn`: token, cart, country).
 */
function optionSpec(n, kind, chosen, drawn = {}) {
  const id = "payment-option-" + n;
  const radio = ["input", Object.assign({ id, type: "radio", name: "payment-option", "data-module-name": kind ? "wajub" : "ps_wirepayment", class: "ps-shown-by-js" }, chosen ? { checked: "checked" } : {})];
  const info = kind
    ? [
        "div",
        { id: id + "-additional-information", class: "js-additional-information" },
        [
          "div",
          { class: "wajub-payment-info" },
          [
            "div",
            Object.assign(
              { class: "wajub-payment-fields wajub-fields", "data-wajub-fields": kind, "data-wajub-component": kind === "mobile_money" ? "mobileMoney" : "card" },
              drawn.country ? { "data-wajub-country": drawn.country } : {}
            ),
            ["fieldset", { class: "wajub-fields__group" }, ["div", { class: "wajub-fields__mount", id: "wajub-fields-" + kind, "data-wajub-fields-mount": "", tabindex: "-1" }]],
            ["p", { class: "wajub-fields__error", role: "alert", "data-wajub-fields-error": "", hidden: "" }],
            ["p", { class: "wajub-fields__status", role: "status", "data-wajub-fields-status": "" }],
            ["div", { class: "wajub-fields__actions", "data-wajub-fields-actions": "", hidden: "" }],
          ],
        ],
      ]
    : ["div", { id: id + "-additional-information", class: "js-additional-information" }, ["p", {}, "Pay by bank wire."]];
  const form = kind
    ? [
        "form",
        { class: "wajub-payment-form", method: "post", action: URLS.validation, "data-wajub-form": kind },
        ["input", { type: "hidden", name: "kind", value: kind }],
        ["input", { type: "hidden", name: "token", value: drawn.token || "cart-token" }],
        ["input", { type: "hidden", name: "cart", value: String(drawn.cart || 501) }],
        ["input", { type: "hidden", name: "wajub_prepared", value: "", "data-wajub-prepared": "" }],
        ["button", { style: "display:none", id: "pay-with-" + id, type: "submit" }],
      ]
    : ["form", { id: "payment-" + id + "-form", method: "post", action: "https://shop.test/module/ps_wirepayment/validation" }];
  return [
    "div",
    {},
    ["div", { id: id + "-container", class: "payment-option" }, radio, ["label", { for: id }, kind || "Bank wire"]],
    info,
    ["div", { id: "pay-with-" + id + "-form", class: "js-payment-option-form" }, form],
  ];
}

/**
 * PrestaShop's checkout at its payment step: bank wire, Mobile Money and
 * Card, the option `chosen` (1 to 3) selected, the terms box, "Place
 * order", and the scripts as the module loads them.
 */
function checkout(options = {}) {
  const doc = F.makeDocument();
  const timers = F.makeTimers();
  const shop = F.makeShop(URLS);
  const sdk = F.makeSdk(doc, options.version === undefined ? "1.7.2" : options.version);
  const chosen = options.chosen === undefined ? 2 : options.chosen;
  const content = F.build(doc, [
    "section",
    { id: "content" },
    ["header", { class: "checkout-header" }, ["a", { href: "/" }, "Shop"]],
    [
      "section",
      { id: "checkout-payment-step", class: "checkout-step" },
      ["div", { class: "payment-options" }, optionSpec(1, "", chosen === 1), optionSpec(2, "mobile_money", chosen === 2), optionSpec(3, "card", chosen === 3)],
      ["form", { id: "conditions-to-approve" }, ["input", { type: "checkbox", id: "conditions_to_approve[terms-and-conditions]", name: "conditions_to_approve[terms-and-conditions]", checked: "checked" }]],
      ["div", { id: "payment-confirmation", class: "js-payment-confirmation" }, ["div", { class: "ps-shown-by-js" }, ["button", { type: "submit", class: "btn btn-primary" }, "Place order"]]],
    ],
    ["aside", { id: "js-checkout-summary" }, ["input", { class: "form-control", name: "discount_name" }]],
  ]);
  doc.body.appendChild(content);
  const nav = [];
  const ctx = {
    document: doc,
    location: {
      href: "https://shop.test/order",
      assign(url) {
        nav.push(url);
        this.href = url;
      },
    },
    wajubFields: options.settings || {
      methods: { mobile_money: config("mobile_money"), card: config("card") },
      prepareUrl: URLS.prepare,
      validationUrl: URLS.validation,
      cart: 501,
      token: "cart-token",
      country: "CM",
    },
    getComputedStyle: computedStyle,
    setTimeout: (fn, ms) => timers.setTimeout(fn, ms),
    clearTimeout: (id) => timers.clearTimeout(id),
    fetch: shop.fetch,
    Promise,
    MutationObserver: doc.MutationObserver,
    prestashop: options.prestashop,
  };
  if (options.sdk !== false) {
    ctx.wajub = sdk;
  }
  (options.prepare || []).forEach((answer) => shop.queues.prepare.push(answer));
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(source("fields-core.js"), ctx);
  vm.runInContext(source("checkout-fields.js"), ctx);

  const page = { doc, shop, sdk, timers, ctx, nav, posts: [] };
  page.box = (kind) => doc.querySelector('[data-wajub-fields="' + kind + '"]');
  page.part = (kind, attr) => page.box(kind).querySelector("[" + attr + "]");
  page.mount = (kind) => page.part(kind, "data-wajub-fields-mount");
  page.error = (kind) => page.part(kind, "data-wajub-fields-error");
  page.status = (kind) => page.part(kind, "data-wajub-fields-status");
  page.actions = (kind) => page.part(kind, "data-wajub-fields-actions");
  page.button = () => doc.querySelector("#payment-confirmation button");
  page.last = () => sdk.created[sdk.created.length - 1];
  /** The payer picks option n. */
  page.select = (n) => {
    doc.querySelectorAll('input[name="payment-option"]').forEach((r) => (r.checked = r.id === "payment-option-" + n));
    doc.dispatch("change", doc.getElementById("payment-option-" + n));
    timers.run((t) => t.ms === 0);
  };
  /**
   * "Place order": the click reaches the document's capturing listeners
   * first; unless one stopped it, PrestaShop's own handler (core.js
   * confirm()) marks the button and submits the selected option's form,
   * which the browser posts unless a handler prevents it.
   */
  page.placeOrder = () => {
    const button = page.button();
    const seen = { core: false, posted: null };
    doc.dispatch("click", button, () => {
      seen.core = true;
      button.classList.add("disabled");
      const radio = doc.querySelector('input[name="payment-option"]:checked');
      const form = doc.getElementById("pay-with-" + radio.id + "-form").querySelector("form");
      const submit = doc.dispatch("submit", form);
      if (!submit.defaultPrevented) {
        const prepared = form.querySelector("[data-wajub-prepared]");
        seen.posted = { action: form.getAttribute("action"), wajub_prepared: prepared ? prepared.value : null };
        page.posts.push(seen.posted);
      }
    });
    return seen;
  };
  page.locked = () => doc.body.querySelectorAll("[inert]").filter((node) => node.tagName !== "IFRAME");
  return page;
}

/** The checkout with the Mobile Money form mounted, up and complete. */
async function readyPage(options = {}) {
  const page = checkout(options);
  await flush();
  const component = page.last();
  component.ready();
  component.change({ complete: true, empty: false });
  return { page, component };
}

function placed(paymentId, extra) {
  return {
    success: true,
    data: Object.assign(
      {
        fields: "1",
        kind: "mobile_money",
        orderId: 9,
        paymentId,
        sessionId: "tok_of_" + paymentId,
        statusUrl: URLS.status + "?id_order=9&token=t",
        returnUrl: CONFIRMATION,
        hostedUrl: HOSTED,
        releaseUrl: URLS.release + "?id_order=9&token=t",
        orderToken: "t",
      },
      extra || {}
    ),
  };
}

/** The same, with the order placed and the form submitted. */
async function payingPage(options = {}) {
  const { page, component } = await readyPage(options);
  const paymentId = component.cfg.sessionId.replace("tok_", "trx_");
  page.shop.queues.validation.push(placed(paymentId));
  const seen = page.placeOrder();
  await flush();
  return { page, component, seen, paymentId };
}

// --- fields-core.js: the site's look --------------------------------------------------

function coreContext() {
  const doc = F.makeDocument();
  const ctx = { document: doc, getComputedStyle: computedStyle, setTimeout, clearTimeout, Promise };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(source("fields-core.js"), ctx);
  return { ctx, doc, Fields: ctx.WajubFields };
}

function lookTests() {
  const L = coreContext().Fields.look;
  for (const [input, expected] of [
    ["rgb(17, 34, 51)", { r: 17, g: 34, b: 51, a: 1 }],
    ["rgba(17, 34, 51, 0.5)", { r: 17, g: 34, b: 51, a: 0.5 }],
    ["rgb(17 34 51 / 25%)", { r: 17, g: 34, b: 51, a: 0.25 }],
    ["  #ABC ", { r: 170, g: 187, b: 204, a: 1 }],
    ["transparent", { r: 0, g: 0, b: 0, a: 0 }],
    ["color(srgb 1 0.5 0)", { r: 255, g: 127.5, b: 0, a: 1 }],
  ]) {
    check(canon(L.parseColor(input)) === canon(expected), "the colour " + input + " is read as " + canon(expected));
  }
  for (const unread of ["red", "oklch(0.7 0.1 200)", "rgb(1, 2)", "#12", "", null, "url(x)"]) {
    check(L.parseColor(unread) === null, "the colour " + String(unread) + " is not guessed");
  }
  const white = L.parseColor("#fff");
  const black = L.parseColor("#000");
  check(Math.abs(L.contrast(white, black) - 21) < 1e-9 && L.schemeOf(L.parseColor("#111")) === "dark" && L.schemeOf(white) === "light", "WCAG's contrast, and a dark background is dark");
  check(L.cssPx("15px", 10, 32) === "15px" && L.cssPx("1em", 10, 32) === "" && L.radiusPx("999px") === "22px", "sizes in px, the rounding at most half an input");
  check(L.fontStack('"Open Sans", sans-serif') === '"Open Sans", sans-serif' && L.fontStack("Arial; color: red") === "" && L.fontStack("url(https://evil.test/f.woff)") === "", "a font stack goes whole, unless it reaches outside a font name");
  check(L.schemeSetting({ theme: "dark" }) === "dark" && L.schemeSetting({ theme: "night" }) === "system", "the merchant's Payment form colours");

  // PrestaShop's Classic theme: white page, light grey inputs, its blue button.
  const classic = {
    background: "rgb(255, 255, 255)",
    fontFamily: "Noto Sans, sans-serif",
    fontSize: "16px",
    text: "rgb(35, 35, 35)",
    placeholder: "rgb(124, 124, 124)",
    inputBackground: "rgb(245, 245, 245)",
    inputBorder: "rgba(0, 0, 0, 0.25)",
    radius: "0px",
    label: "rgb(35, 35, 35)",
    button: "rgb(36, 185, 215)",
    link: "rgb(36, 185, 215)",
  };
  const look = L.buildAppearance(classic, "system");
  check(look.colorScheme === "light" && look.variables.fontFamily === "Noto Sans, sans-serif" && look.variables.fontSizeBase === "16px" && look.variables.borderRadius === "0px", "the Classic theme's form: light, its font, size and square inputs");
  check(look.inputBackgroundColor === "rgb(245, 245, 245)" && look.variables.colorText === "rgb(35, 35, 35)" && look.variables.colorBackground === "rgb(255, 255, 255)", "its inputs' colours on its own background");
  check(!("colorPrimary" in look.variables), "a light blue that would not show a focus ring on white (under 3:1) is left to Wajub.js");
  check(canon(L.buildAppearance(classic, "dark")) === canon({ colorScheme: "dark", variables: { borderRadius: "0px", fontFamily: "Noto Sans, sans-serif", fontSizeBase: "16px" } }), "forced dark on a light shop: only its font, size and rounding");
  const dark = { background: "rgb(17, 17, 17)", text: "rgb(240, 240, 240)", inputBackground: "rgb(34, 34, 34)", button: "rgb(255, 204, 0)" };
  check(L.buildAppearance(dark, "system").colorScheme === "dark" && L.buildAppearance(dark, "system").variables.colorPrimary === "rgb(255, 204, 0)", "a dark shop's form is dark, with its button's colour");
}

// --- fields-core.js: prepare, mount, pay ---------------------------------------------------

async function coreTests() {
  const { ctx, doc, Fields } = coreContext();
  const shop = F.makeShop(URLS);
  ctx.fetch = shop.fetch;
  let answer = await Fields.prepare({ prepareUrl: URLS.prepare, kind: "card", token: "tk" }, { renew: true });
  check(answer.paymentId === "trx_card_1" && answer.sessionId === "tok_card_1" && answer.currency === "XAF", "prepare(): the shop's payment for the form");
  check(shop.requests[0].body === "kind=card&token=tk&renew=1" && shop.requests[0].method === "POST", "asked with the option's kind, the cart's token, and renew");
  shop.queues.prepare.push({ success: false, data: { code: "method_unavailable", message: "No" } });
  answer = await Fields.prepare({ prepareUrl: URLS.prepare, kind: "card", token: "tk" });
  check(answer.error && answer.error.code === "method_unavailable", "a refusal comes back as its code");
  shop.queues.prepare.push("reject");
  answer = await Fields.prepare({ prepareUrl: URLS.prepare, kind: "card", token: "tk" });
  check(answer.error && answer.error.code === "unavailable", "the shop out of reach: unavailable, never a rejection");

  const sdk = F.makeSdk(doc, "1.7.2");
  ctx.wajub = sdk;
  check(Fields.available(), "Wajub.js 1.7.2 can mount the form");
  const container = doc.createElement("div");
  doc.body.appendChild(container);
  const handle = Fields.mount(container, { sessionId: "tok_1", component: "mobileMoney", locale: "fr", componentOptions: { variant: "fields", fields: { customer: "external" } }, country: "cm", frameTitle: "Formulaire" });
  const component = sdk.created[0];
  check(handle && component.type === "mobileMoney" && component.cfg.variant === "fields" && component.cfg.fields.customer === "external" && component.cfg.fields.country === "CM", "mounted: the fields variant, the customer the shop's, the country given");
  check(component.cfg.locale === "fr" && component.iframe.getAttribute("title") === "Formulaire", "in the payer's language, its frame named for screen readers");
  check(container.classList.contains("is-loading") && container.getAttribute("aria-busy") === "true" && component.iframe.hasAttribute("inert"), "loading unseen, out of reach, behind its skeleton");
  component.ready();
  check(!container.classList.contains("is-loading") && !component.iframe.hasAttribute("inert"), "up: shown, within reach");
  check(component.cfg.onBeforeSubmit() === false, "the form may not charge before the order is placed");
  handle.setActivated(true);
  check(component.cfg.onBeforeSubmit() === true, "and may once the payment is activated");
  const outcome = Fields.pay(handle);
  component.succeed({ id: "trx_1" });
  check((await outcome).status === "success" && component.submits === 1, "pay(): submits the form and resolves with its success");
  const declined = Fields.pay(handle, { submit: false });
  component.decline();
  check((await declined).status === "declined", "a declined attempt is reported as such");

  ctx.wajub = F.makeSdk(doc, "1.4.0");
  check(!Fields.available(), "a Wajub.js older than 1.5.0 cannot mount it");
}

// --- checkout-fields.js on PrestaShop's payment step --------------------------------------

async function checkoutTests() {
  // Mounted when its option is selected.
  let page = checkout();
  await flush();
  check(page.shop.of("prepare").length === 1 && page.shop.fields(page.shop.of("prepare")[0]).kind === "mobile_money", "the selected option's form asks the shop for its payment");
  check(page.shop.fields(page.shop.of("prepare")[0]).token === "cart-token", "with the cart's token");
  let component = page.last();
  check(component.type === "mobileMoney" && component.cfg.sessionId === "tok_mobile_money_1" && component.cfg.fields.country === "CM", "the Mobile Money form, on the cart's payment, the invoice country given");
  check(page.mount("mobile_money").classList.contains("is-loading"), "behind its skeleton until it is up");
  check(page.status("mobile_money").textContent === words("mobile_money").loading, "saying it loads, to screen readers");
  check(page.error("mobile_money").textContent === "" && !page.error("mobile_money").hidden, "its alert is on the page, empty, so that an error is read out when it comes");
  page.select(3);
  await flush();
  check(page.last().type === "card" && page.shop.of("prepare").length === 2, "selecting Card mounts the Card form, on a payment of its own");
  page.select(2);
  await flush();
  check(page.shop.of("prepare").length === 2, "back to Mobile Money: its form, its payment kept");

  page = checkout({ chosen: 1 });
  await flush();
  check(page.shop.of("prepare").length === 0 && page.sdk.created.length === 0, "another method selected: nothing asked, nothing mounted");

  // "Place order" with the form incomplete.
  page = checkout();
  await flush();
  page.last().ready();
  let seen = page.placeOrder();
  check(!seen.core && page.posts.length === 0 && page.shop.of("validation").length === 0, "the form incomplete: PrestaShop does not submit, no order is placed");
  check(page.error("mobile_money").textContent === words("mobile_money").incomplete && !page.error("mobile_money").hidden, "the payer is told what to fill in");
  check(!page.button().classList.contains("disabled"), "and Place order works again");

  // Terms not accepted: PrestaShop's own handling.
  page = checkout();
  await flush();
  page.doc.querySelector('input[name="conditions_to_approve[terms-and-conditions]"]').checked = false;
  seen = page.placeOrder();
  check(seen.core, "the terms not accepted: PrestaShop says what is missing, as for any method");

  // Placing the order, then paying.
  let paying = await payingPage();
  page = paying.page;
  const request = page.shop.of("validation")[0];
  const fields = page.shop.fields(request);
  check(!paying.seen.core && page.posts.length === 0, "Place order is taken over: PrestaShop submits nothing itself");
  check(request.method === "POST" && fields.ajax === "1" && fields.kind === "mobile_money" && fields.cart === "501" && fields.token === "cart-token" && fields.wajub_prepared === paying.paymentId, "the shop places the order with the form's payment, for the cart");
  check(paying.component.submits === 1, "then the form is submitted");
  check(page.status("mobile_money").textContent === words("mobile_money").paying, "the payer reads what to do on their phone");
  check(page.locked().some((n) => n.id === "js-checkout-summary") && page.locked().some((n) => n.getAttribute("id") === "pay-with-payment-option-1-form" || n.querySelector('[id="payment-option-1"]')), "the rest of the checkout is locked while the payer pays");
  check(page.button().disabled === true, "Place order with it");
  const actions = page.actions("mobile_money");
  check(!actions.hidden && actions.querySelector("[data-wajub-fields-hosted]").getAttribute("href") === HOSTED, "the same payment on Wajub's page is at hand");
  page.shop.queues.status.push({ success: true, data: { status: "paid", redirect: CONFIRMATION } });
  paying.component.succeed({ id: paying.paymentId });
  await flush();
  check(page.nav[page.nav.length - 1] === CONFIRMATION, "paid: the payer goes to the order's confirmation page");
  check(page.shop.of("status").length === 1, "once the shop settled it");

  // A declined attempt, then Try again.
  paying = await payingPage();
  page = paying.page;
  paying.component.decline();
  await flush();
  check(page.error("mobile_money").textContent === words("mobile_money").declined, "a declined attempt: said so, the form may try again");
  page.actions("mobile_money").querySelector("[data-wajub-fields-retry]").click();
  await flush();
  check(paying.component.mounts === 2, "Try again loads the form again, on the same payment");
  check(page.shop.of("validation").length === 1 && page.shop.of("release").length === 0, "no new order, the order kept");

  // Use another payment method: the order given back as a cart.
  paying = await payingPage();
  page = paying.page;
  page.shop.queues.status.push({ success: true, data: { status: "pending", processing: false } });
  page.actions("mobile_money").querySelector("[data-wajub-fields-another]").click();
  await flush();
  check(page.shop.of("status").length === 1, "the shop is asked first whether the payment went on");
  const release = page.shop.of("release")[0];
  check(release && page.shop.fields(release).reason === "switch" && page.shop.fields(release).id_order === "9" && page.shop.fields(release).token === "t", "then asked to give the order back, for this order");
  check(page.nav[page.nav.length - 1] === "https://shop.test/order", "and the checkout reloads with the cart");

  // Leaving while Wajub is still taking the payment.
  paying = await payingPage();
  page = paying.page;
  page.shop.queues.status.push({ success: true, data: { status: "pending", processing: true, redirect: CONFIRMATION } });
  page.actions("mobile_money").querySelector("[data-wajub-fields-another]").click();
  await flush();
  check(page.nav[page.nav.length - 1] === CONFIRMATION && page.shop.of("release").length === 0, "an approval on its way: never a second payment, the confirmation page follows it");

  // The payment cancelled in the form; the shop cannot be asked.
  paying = await payingPage();
  page = paying.page;
  page.shop.queues.status.push({ success: true, data: { status: "pending", processing: false } });
  page.shop.queues.release.push({ success: true, data: { to: "stay", url: "", message: "We could not check your payment with Wajub. Please try again in a moment." } });
  paying.component.cancel();
  await flush();
  check(page.shop.fields(page.shop.of("release")[0]).reason === "cancelled", "cancelled in the form: the order to be given back");
  check(page.error("mobile_money").textContent === "We could not check your payment with Wajub. Please try again in a moment." && page.nav.length === 0, "Wajub out of reach: the payer reads it, nothing changes");
  page.shop.queues.release.push({ success: true, data: { to: "checkout", url: "https://shop.test/order", message: "" } });
  page.actions("mobile_money").querySelector("[data-wajub-fields-another]").click();
  await flush();
  check(page.nav[page.nav.length - 1] === "https://shop.test/order", "asked again, the cart comes back");

  // The shop refuses to place the order.
  let ready = await readyPage();
  page = ready.page;
  page.shop.queues.validation.push({ success: false, data: { code: "start_failed", message: "Your payment could not be started. Please try again in a moment.", redirect: "" } });
  page.placeOrder();
  await flush();
  check(page.error("mobile_money").textContent === "Your payment could not be started. Please try again in a moment." && !page.button().classList.contains("disabled"), "the payment could not start: said so, Place order works again");
  check(ready.component.submits === 0, "the form was not submitted");
  page.shop.queues.validation.push(placed(ready.component.cfg.sessionId.replace("tok_", "trx_")));
  page.placeOrder();
  await flush();
  check(ready.component.submits === 1 && page.shop.of("validation").length === 2, "placed again: the same cart and payment, and the form submitted");

  ready = await readyPage();
  page = ready.page;
  page.shop.queues.validation.push({ success: false, data: { code: "refreshed", message: words("mobile_money").refreshed, redirect: "" } });
  page.placeOrder();
  await flush();
  check(page.error("mobile_money").textContent === words("mobile_money").refreshed && page.shop.fields(page.shop.of("prepare")[1]).renew === "1", "the payment could no longer pay the order: a new one, the payer asked to check and place it again");

  ready = await readyPage();
  page = ready.page;
  page.shop.queues.validation.push({ success: false, data: { code: "method_unavailable", message: words("mobile_money").unavailable, redirect: "https://shop.test/order" } });
  page.placeOrder();
  await flush();
  check(page.nav[0] === "https://shop.test/order", "the order given back by the shop: the checkout reloads, with its message");

  ready = await readyPage();
  page = ready.page;
  page.shop.queues.validation.push("reject");
  page.placeOrder();
  await flush();
  check(page.error("mobile_money").textContent === words("mobile_money").temporarilyUnavailable, "the shop out of reach: said so");

  // The form cannot run: Wajub's page.
  page = checkout({ sdk: false });
  await flush();
  check(page.status("mobile_money").textContent === words("mobile_money").fallback && page.mount("mobile_money").hidden, "without Wajub.js: the payer will pay on Wajub's page, said so");
  seen = page.placeOrder();
  check(seen.core && seen.posted && seen.posted.action === URLS.validation && seen.posted.wajub_prepared === "", "Place order posts the form, with no payment: the shop starts one on Wajub's page");

  page = checkout();
  await flush();
  page.last().loadError({ code: "embed_blocked" });
  check(page.status("mobile_money").textContent === words("mobile_money").fallback, "a form that cannot load falls back too");
  page = checkout();
  await flush();
  page.timers.run((t) => t.ms === 15000);
  check(page.status("mobile_money").textContent === words("mobile_money").fallback, "so does one that says nothing for 15 seconds");

  page = checkout({ prepare: [{ success: false, data: { code: "method_unavailable", message: "x" } }] });
  await flush();
  check(page.error("mobile_money").textContent === words("mobile_money").unavailable && page.mount("mobile_money").hidden, "an option Wajub cannot take for the cart: the payer is told to choose another");
  seen = page.placeOrder();
  check(!seen.core && page.posts.length === 0, "and Place order with it places nothing");

  // Enter pressed in the form before the order exists: Place order, as PrestaShop does it.
  ready = await readyPage();
  page = ready.page;
  page.button().click = function () {
    this.clicked = (this.clicked || 0) + 1;
  };
  const allowed = await ready.component.askToSubmit();
  page.timers.run((t) => t.ms === 0);
  check(allowed === false && page.button().clicked === 1, "Enter in the form before the order is placed: the form may not charge, Place order is pressed");

  // A session that ended: one new payment.
  page = checkout();
  await flush();
  page.last().loadError({ code: "session_expired" });
  await flush();
  check(page.shop.fields(page.shop.of("prepare")[1]).renew === "1" && page.sdk.created.length === 2, "the form's session ended: one new payment, the form mounted on it");

  await redrawTests();
}

/**
 * The payment step drawn again while the payer is on it, as PrestaShop or a
 * one-page checkout module does after a change of address or carrier: the
 * options replaced by new nodes, drawn the same way or another.
 */
async function redrawTests() {
  /** Replaces the whole list of options, as a module that reloads it does, `chosen` selected. */
  const redraw = async (page, chosen, spec) => {
    const old = page.doc.querySelector(".payment-options");
    const fresh = F.build(page.doc, spec || ["div", { class: "payment-options" }, optionSpec(1, "", chosen === 1), optionSpec(2, "mobile_money", chosen === 2), optionSpec(3, "card", chosen === 3)]);
    old.replaceWith(fresh);
    await settle();
    page.timers.run((t) => t.ms === 0);
    await flush();
  };

  let page = checkout();
  await flush();
  let first = page.last();
  first.ready();
  const box = page.box("mobile_money");
  await redraw(page, 2);
  check(page.box("mobile_money") !== box, "(the box is a new node)");
  check(first.destroyed && page.sdk.created.length === 2 && page.last().container === page.mount("mobile_money"), "the options drawn again: the form is mounted again, in the new box");
  check(page.shop.of("prepare").length === 1 && page.last().cfg.sessionId === first.cfg.sessionId, "on the same payment: the shop is not asked again");
  page.last().ready();
  page.last().change({ complete: true, empty: false });
  page.shop.queues.validation.push(placed(page.last().cfg.sessionId.replace("tok_", "trx_")));
  let seen = page.placeOrder();
  await flush();
  check(!seen.core && page.shop.of("validation").length === 1 && page.last().submits === 1, "Place order then pays with the form of the new box");

  page = checkout({ chosen: 1 });
  await flush();
  await redraw(page, 3);
  check(page.last() && page.last().type === "card" && page.last().container === page.mount("card"), "options drawn again with Card selected: its form is mounted, with no change event");

  // An option drawn another way: no #pay-with-… nor #…-additional-information, one block per option.
  const own = (n, kind, chosen) => [
    "li",
    { class: "opc-payment" },
    ["input", Object.assign({ type: "radio", name: "payment-option", id: "opc-" + n, "data-module-name": kind ? "wajub" : "other" }, chosen ? { checked: "checked" } : {})],
    kind
      ? ["div", { class: "opc-details" }, ["div", { class: "wajub-payment-fields", "data-wajub-fields": kind, "data-wajub-component": "mobileMoney" }, ["div", { "data-wajub-fields-mount": "" }], ["p", { "data-wajub-fields-error": "" }], ["p", { "data-wajub-fields-status": "" }], ["div", { "data-wajub-fields-actions": "", hidden: "" }]], ["form", { "data-wajub-form": kind, action: URLS.validation }, ["input", { type: "hidden", "data-wajub-prepared": "", name: "wajub_prepared" }]]]
      : ["div", { class: "opc-details" }, "Bank wire"],
  ];
  page = checkout({ chosen: 1 });
  await flush();
  await redraw(page, 0, ["ul", { class: "payment-options" }, own(1, "", false), own(2, "mobile_money", true)]);
  check(page.last() && page.last().type === "mobileMoney" && page.last().container === page.mount("mobile_money"), "an option a module draws its own way: the radio's own block holds its box, and the form is mounted there");

  // Whatever submits the form goes through the same gate.
  ({ page } = await readyPage());
  const form = page.doc.querySelector('form[data-wajub-form="mobile_money"]');
  const natives = [];
  page.ctx.HTMLFormElement = { prototype: { submit() { natives.push(this); } } };
  page.shop.queues.validation.push(placed(page.last().cfg.sessionId.replace("tok_", "trx_")));
  check(form.hasAttribute("data-wajub-guarded") && typeof form.submit === "function", "the option's form is guarded");
  form.submit();
  await flush();
  check(natives.length === 0 && page.shop.of("validation").length === 1 && page.last().submits === 1, "a script that calls the form's submit(): the order is placed and paid as with Place order, the form never posted");
  page = checkout();
  await flush();
  page.last().loadError({ code: "embed_blocked" });
  page.ctx.HTMLFormElement = { prototype: { submit() { natives.push(this); } } };
  page.doc.querySelector('form[data-wajub-form="mobile_money"]').submit();
  check(natives.length === 1 && page.shop.of("validation").length === 0, "a box whose form could not run: submit() posts it, and the shop starts the payment on Wajub's page");

  // The options drawn again while the shop places the order: the form the
  // payer filled in is gone; the payment goes on, on Wajub's page.
  ({ page } = await readyPage());
  const filled = page.last();
  const paymentId = filled.cfg.sessionId.replace("tok_", "trx_");
  let answerPlace = null;
  const fetchFirst = page.ctx.fetch;
  page.ctx.fetch = (url, init) => {
    if (url.split("?")[0] === URLS.validation) {
      page.shop.requests.push({ url, kind: "validation", method: "POST", body: init.body });
      return new Promise((resolve) => {
        answerPlace = () => resolve({ json: () => Promise.resolve(placed(paymentId)) });
      });
    }
    return fetchFirst(url, init);
  };
  seen = page.placeOrder();
  await flush();
  check(!seen.core && page.shop.of("validation").length === 1, "(Place order taken over, the order being placed)");
  await redraw(page, 2);
  const remounted = page.last();
  answerPlace();
  await flush();
  page.timers.run((t) => t.ms === 0);
  await flush();
  check(filled.submits === 0 && remounted.submits === 0, "options drawn again while the order is placed: no form is submitted, neither the one gone nor a new, empty one");
  check(page.nav[page.nav.length - 1] === HOSTED, "the payment goes on, on Wajub's page: " + JSON.stringify(page.nav));
  check(page.status("mobile_money").textContent !== words("mobile_money").paying, "and the payer is not told to approve a payment nothing was sent for");

  // The options drawn again for a guest's account created since, at another
  // invoice address: the cart's new token and the new country are used.
  page = checkout();
  await flush();
  page.last().ready();
  await redraw(page, 2, ["div", { class: "payment-options" }, optionSpec(1, "", false), optionSpec(2, "mobile_money", true, { token: "guest-token", country: "SN" }), optionSpec(3, "card", false, { token: "guest-token", country: "SN" })]);
  check(page.last().cfg.fields.country === "SN", "the form mounted again asks no country, the new invoice address's: " + JSON.stringify(page.last().cfg.fields));
  page.last().ready();
  page.last().change({ complete: true, empty: false });
  page.shop.queues.validation.push(placed(page.last().cfg.sessionId.replace("tok_", "trx_")));
  page.placeOrder();
  await flush();
  check(page.shop.fields(page.shop.of("validation")[0]).token === "guest-token", "Place order sends the cart's token as drawn last, not the page's: " + JSON.stringify(page.shop.fields(page.shop.of("validation")[0])));
  page = checkout({ prepare: [{ success: false, data: { code: "invalid_token", message: "x" } }] });
  await flush();
  check(page.status("mobile_money").textContent === words("mobile_money").fallback, "(a payment the shop would not prepare: Wajub's page)");
  await redraw(page, 2, ["div", { class: "payment-options" }, optionSpec(1, "", false), optionSpec(2, "mobile_money", true, { token: "guest-token" }), optionSpec(3, "card", false, { token: "guest-token" })]);
  check(page.shop.of("prepare").length === 2 && page.shop.fields(page.shop.of("prepare")[1]).token === "guest-token" && page.last() && page.last().container === page.mount("mobile_money"), "drawn again with another token (the guest's account created): asked again, with it, and the form mounted");

  // A one-page checkout module that names its radios its own way and
  // submits the option's form itself: the box never starts, and the payer
  // pays on Wajub's page rather than being stuck.
  const opc = (n, kind, chosen) => [
    "li",
    { class: "opc-payment" },
    ["input", Object.assign({ type: "radio", name: "payment_option", id: "opc-" + n }, chosen ? { checked: "checked" } : {})],
    kind
      ? ["div", { class: "opc-details" }, ["div", { class: "wajub-payment-fields", "data-wajub-fields": kind, "data-wajub-component": "mobileMoney" }, ["div", { "data-wajub-fields-mount": "" }], ["p", { "data-wajub-fields-error": "" }], ["p", { "data-wajub-fields-status": "" }], ["div", { "data-wajub-fields-actions": "", hidden: "" }]], ["form", { "data-wajub-form": kind, action: URLS.validation }, ["input", { type: "hidden", "data-wajub-prepared": "", name: "wajub_prepared", value: "trx_stale" }]]]
      : ["div", { class: "opc-details" }, "Bank wire"],
  ];
  page = checkout({ chosen: 1 });
  await flush();
  await redraw(page, 0, ["ul", { class: "payment-options" }, opc(1, "", false), opc(2, "mobile_money", true)]);
  const opcNatives = [];
  page.ctx.HTMLFormElement = { prototype: { submit() { opcNatives.push(this); } } };
  const opcForm = page.doc.querySelector('form[data-wajub-form="mobile_money"]');
  check(page.sdk.created.length === 0, "(radios the script cannot read: the form is never mounted)");
  opcForm.submit();
  await flush();
  check(opcNatives.length === 1 && opcForm.querySelector("[data-wajub-prepared]").value === "", "the module's submit(): the form is posted, with no payment, and the shop starts one on Wajub's page");
  const viaEvent = page.doc.dispatch("submit", opcForm);
  check(!viaEvent.defaultPrevented, "a submit event is let through too");
  check(page.error("mobile_money").textContent === "" && page.shop.of("validation").length === 0, "the payer is never asked to fill in a form that is not there");

  // PrestaShop's own checkout events look again too.
  const events = {};
  page = checkout({ chosen: 1, prestashop: { on(name, fn) { events[name] = fn; } } });
  await flush();
  check(typeof events.updatedDeliveryForm === "function" && typeof events.changedCheckoutStep === "function", "PrestaShop's checkout events are listened to");
  page.doc.querySelectorAll('input[name="payment-option"]').forEach((r) => (r.checked = r.id === "payment-option-2"));
  events.updatedDeliveryForm();
  page.timers.run((t) => t.ms === 0);
  await flush();
  check(page.last() && page.last().type === "mobileMoney", "after one, the selected option's form is mounted");
  check(typeof page.ctx.WajubCheckout.sync === "function", "and a module may ask for the same: WajubCheckout.sync()");
}

// --- checkout-options.js: the options' logos in words -------------------------------------------

async function optionsTests() {
  const doc = F.makeDocument();
  const option = (n, kind, alt) => [
    "div",
    {},
    ["div", { id: "payment-option-" + n + "-container" }, ["input", { type: "radio", name: "payment-option", id: "payment-option-" + n }], ["label", { for: "payment-option-" + n }, ["span", {}, kind], ["img", { src: "data:image/svg+xml;base64,AAAA" }]]],
    ["div", { id: "payment-option-" + n + "-additional-information" }, ["div", Object.assign({ class: "wajub-payment-info", "data-wajub-option": kind }, alt ? { "data-wajub-logo-alt": alt } : {})]],
  ];
  // Hummingbird: the label holds the radio, and names no `for`.
  const held = (n, kind, alt) => [
    "div",
    {},
    ["div", { id: "payment-option-" + n + "-container" }, ["label", { class: "form-check-label" }, ["input", { type: "radio", name: "payment-option", id: "payment-option-" + n }], ["span", {}, ["img", { src: "data:image/svg+xml;base64,AAAA" }], kind]]],
    ["div", { id: "payment-option-" + n + "-additional-information" }, ["div", { class: "wajub-payment-info", "data-wajub-option": kind, "data-wajub-logo-alt": alt }]],
  ];
  const list = () => F.build(doc, ["div", { class: "payment-options" }, option(1, "mobile_money", "MTN, Orange"), option(2, "other", "PayPal"), held(3, "card", "Visa, Mastercard")]);
  doc.body.appendChild(list());
  const ctx = { document: doc, setTimeout: (fn) => setImmediate(fn), MutationObserver: doc.MutationObserver };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(source("checkout-options.js"), ctx);
  const alts = () => doc.querySelectorAll("label img").map((img) => img.getAttribute("alt"));
  check(canon(alts()) === canon(["MTN, Orange", "PayPal", "Visa, Mastercard"]), "each Wajub option's logo says the brands it shows, its label naming the radio or holding it: " + canon(alts()));
  doc.querySelector(".payment-options").replaceWith(list());
  await flush();
  check(canon(alts()) === canon(["MTN, Orange", "PayPal", "Visa, Mastercard"]), "and again once the options are drawn again");
}

// --- order-status.js on the order confirmation page -------------------------------------------

function confirmationPage() {
  const doc = F.makeDocument();
  const timers = F.makeTimers();
  const shop = F.makeShop(URLS);
  const panel = F.build(doc, [
    "section",
    { class: "wajub-order-status wajub-order-status--waiting", "data-wajub-order-status": "", "data-status-url": URLS.status + "?id_order=9&token=t", "data-release-url": URLS.release + "?id_order=9&token=t", "data-token": "t", "data-timeout": "300" },
    ["h2", { "data-wajub-title": "" }, "Confirm the payment on your phone"],
    ["p", { "data-wajub-lead": "" }, "Your order is saved."],
    ["ol", { "data-wajub-steps": "" }],
    ["p", { "data-wajub-live": "" }, "Waiting…"],
    ["p", { "data-wajub-actions": "", hidden: "" }],
  ]);
  doc.body.appendChild(panel);
  doc.body.appendChild(F.build(doc, ["p", {}, ["code", {}, "trx_test_1"], ["button", { type: "button", "data-wajub-copy": "trx_test_1", hidden: "" }, "Copy"], ["span", { class: "wajub-copy-feedback" }]]));
  doc.visibilityState = "visible";
  const reloads = [];
  const ctx = {
    document: doc,
    location: { reload: () => reloads.push(1) },
    navigator: {},
    wajubOrderStatus: {
      i18n: { waiting: "Waiting…", paidTitle: "Payment received", paidText: "Thank you!", failedTitle: "The payment did not go through", cancelledTitle: "Payment cancelled", failedText: "Your order is saved, but it is not paid.", partialTitle: "Part of your order is paid", retry: "Try again", timeoutTitle: "Still waiting", timeoutText: "Not yet.", checkAgain: "Check again" },
    },
    setTimeout: (fn, ms) => timers.setTimeout(fn, ms),
    clearTimeout: (id) => timers.clearTimeout(id),
    fetch: shop.fetch,
    Date,
    Promise,
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(source("order-status.js"), ctx);
  return { doc, timers, shop, panel, reloads };
}

async function orderStatusTests() {
  let page = confirmationPage();
  page.timers.run((t) => t.ms === 5000);
  await flush();
  check(page.shop.of("status").length === 1 && page.shop.of("status")[0].url.includes("id_order=9"), "the confirmation page asks the shop how the payment stands, after five seconds");
  page.shop.queues.status.push({ success: true, data: { status: "paid" } });
  page.timers.run(() => true);
  await flush();
  check(page.panel.classList.contains("wajub-order-status--paid") && page.panel.querySelector("[data-wajub-title]").textContent === "Payment received", "paid: says so");
  page.timers.run((t) => t.ms === 1500);
  check(page.reloads.length === 1, "and reloads, for PrestaShop to show the paid order");

  page = confirmationPage();
  page.shop.queues.status.push({ success: true, data: { status: "failed", reason: "cancelled", message: "The payment was cancelled.", retry: true } });
  page.timers.run((t) => t.ms === 5000);
  await flush();
  const form = page.panel.querySelector("[data-wajub-actions]").querySelector("form");
  check(page.panel.querySelector("[data-wajub-title]").textContent === "Payment cancelled", "cancelled: says so");
  check(form && form.getAttribute("action") === URLS.release + "?id_order=9&token=t" && form.querySelector('input[name="reason"]').getAttribute("value") === "failed", "Try again gives the order back as a cart, by a form posted to the shop");

  page = confirmationPage();
  page.shop.queues.status.push({ success: true, data: { status: "failed", reason: "order_cancelled", message: "Cancelled." } });
  page.timers.run((t) => t.ms === 5000);
  await flush();
  check(page.panel.querySelector("[data-wajub-actions]").hidden, "an order the shop cannot give back: no Try again");

  page = confirmationPage();
  page.shop.queues.status.push({ success: true, data: { status: "pending", processing: true, partial: true, message: "4 000 FCFA already received; 6 000 FCFA left to pay." } });
  page.timers.run((t) => t.ms === 5000);
  await flush();
  check(page.panel.classList.contains("wajub-order-status--partial") && page.panel.querySelector("[data-wajub-lead]").textContent === "4 000 FCFA already received; 6 000 FCFA left to pay.", "part of the order paid: what was received and what is left");
}

// --- recount.js: a page that asks the shop to read a payment again -----------------------------

function recountPage(config, ready, beacon) {
  const sent = [];
  const listeners = {};
  const ctx = {
    document: { readyState: ready },
    navigator: beacon === undefined ? { sendBeacon: (url, body) => sent.push(["beacon", url, body]) && true } : beacon,
    fetch: (url, options) => {
      sent.push(["fetch", url, options && options.method, options && options.keepalive]);
      return Promise.resolve({ ok: true });
    },
    addEventListener: (type, fn) => {
      listeners[type] = fn;
    },
  };
  if (config !== null) {
    ctx.wajubRecount = config;
  }
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(source("recount.js"), ctx);
  return { sent, listeners };
}

async function recountTests() {
  const url = "https://shop.test/module/wajub/recount";
  let page = recountPage({ url }, "loading");
  check(page.sent.length === 0 && typeof page.listeners.load === "function", "recount: nothing while the page loads: it waits for the page to be on screen");
  page.listeners.load();
  check(page.sent.length === 1 && page.sent[0][0] === "beacon" && page.sent[0][1] === url, "recount: once loaded, one beacon to the shop's recount address");
  page = recountPage({ url }, "complete");
  check(page.sent.length === 1 && page.sent[0][1] === url, "recount: a page loaded already asks at once");
  page = recountPage({ url }, "complete", { sendBeacon: () => false });
  await flush();
  check(page.sent.length === 1 && page.sent[0][0] === "fetch" && page.sent[0][2] === "POST" && page.sent[0][3] === true, "recount: a browser that will not send the beacon: a POST kept alive");
  page = recountPage({ url }, "complete", {});
  check(page.sent.length === 1 && page.sent[0][0] === "fetch", "recount: a browser without beacons: the same POST");
  page = recountPage(null, "complete");
  check(page.sent.length === 0, "recount: nothing without the shop's address");
  page = recountPage({ url: "" }, "complete");
  check(page.sent.length === 0, "recount: nor with an empty one");
}

(async () => {
  lookTests();
  await coreTests();
  await checkoutTests();
  await optionsTests();
  await orderStatusTests();
  await recountTests();
  process.stdout.write("fields-js: " + (run - failed) + " passed, " + failed + " failed\n");
  process.exit(failed === 0 ? 0 : 1);
})().catch((e) => {
  process.stdout.write("ERROR: " + (e && e.stack ? e.stack : e) + "\n");
  process.exit(1);
});

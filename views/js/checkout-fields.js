/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The Mobile Money and Card options at PrestaShop's checkout (the Classic
 * theme on PrestaShop 8, Hummingbird on 9): Wajub's payment form inside
 * each option's own payment box (views/templates/front/payment-info.tpl),
 * run through window.WajubFields (fields-core.js). Every word comes from
 * PHP (`wajubFields`, Checkout\CheckoutStrings). A port of the WordPress
 * plugin's checkout-fields.js.
 *
 * The form is mounted when its option is selected, on a payment the shop
 * prepares for the payer's cart, and holds its room with a skeleton in the
 * checkout's own look until it is up.
 *
 * "Place order": PrestaShop checks the terms and the cart, then submits the
 * selected option's form, which this script takes over. It asks the shop to
 * place the order (controllers/front/validation.php), which creates it and
 * activates the form's payment for it; then the form is submitted, and the
 * rest of the checkout is locked while the payer pays (an approval on their
 * phone, a code, 3-D Secure). Paid, or still being confirmed, the payer
 * goes to the order's confirmation page. Ended unpaid, or left with "Use
 * another payment method", the order goes back to being a cart
 * (controllers/front/release.php), and the checkout reloads with it.
 *
 * Without Wajub.js (blocked, too old), when the shop cannot prepare a
 * payment, or when the form does not load in time, the box says the payer
 * will pay on Wajub's page, and the form posts no payment: the shop then
 * starts one on Wajub's hosted page, restricted to the option's kind. So
 * does an option's form submitted while its box never started: a checkout
 * module that draws its options in a way this script cannot read (no
 * `payment-option` radio, a box apart from its radio) still places the
 * order, and the payer pays on Wajub's page.
 *
 * The payment step may be drawn again while the payer is on it: PrestaShop
 * redraws parts of the checkout, and one-page checkout modules reload the
 * whole list of payment options after each change of address or carrier.
 * Nothing here holds on to a node: each part is found again when needed,
 * the page is watched for options drawn again (a MutationObserver, and
 * PrestaShop's own checkout events), and the form is mounted again in the
 * box drawn anew. The cart's token, its id and the invoice country are read
 * from the options as drawn last (the option's form, its box), not from
 * the page as it loaded: a module that creates the guest's account, or
 * changes the address, draws them anew. The options drawn again while the
 * order is being placed take the form the payer filled in away: the same
 * payment then goes on Wajub's page. Whatever submits an option's form
 * (PrestaShop's "Place order", a module's own button, a script calling
 * submit()) goes through the same gate. A module may also call
 * window.WajubCheckout.sync() once it has drawn the options.
 */
(function () {
  "use strict";

  if (!window.WajubFields) {
    return;
  }

  var Fields = window.WajubFields;
  var settings = window.wajubFields || {};
  var methods = settings.methods && typeof settings.methods === "object" ? settings.methods : {};
  var kinds = Object.keys(methods).filter(function (kind) {
    return /^[a-z_]+$/.test(kind) && methods[kind] && typeof methods[kind] === "object";
  });
  if (!kinds.length) {
    return;
  }

  /**
   * Each option's box: its configuration, the payment its form runs on, its
   * form, and what it says. phase: idle | loading | ready | fallback | unavailable.
   */
  var boxes = {};
  kinds.forEach(function (kind) {
    var cfg = {};
    var given = methods[kind];
    for (var key in given) {
      if (Object.prototype.hasOwnProperty.call(given, key)) {
        cfg[key] = given[key];
      }
    }
    // What the prepare controller takes besides the kind.
    cfg.prepareUrl = settings.prepareUrl || "";
    cfg.token = settings.token || "";
    boxes[kind] = {
      kind: kind,
      cfg: cfg,
      phase: "idle",
      session: null,
      handle: null,
      loading: false,
      renewed: false,
      error: "",
      tone: "error",
      // The cart's token a payment could not be prepared with: the box
      // tries again once the options are drawn with another one.
      failedToken: "",
    };
  });

  /**
   * Where the checkout's own look is (fields-core.js readSite()): a text
   * field of the checkout as the theme draws it, "Place order" and links.
   */
  var SITE_STYLES = {
    inputs: ["#checkout input.form-control", "#content input.form-control", "input.form-control", "input.js-search-input"],
    buttons: ["#payment-confirmation button", ".js-payment-confirmation button"],
    links: ["#checkout a", "#content a", "main a"],
  };

  /** Between "Place order" and the shop's answer: `{kind, paymentId, handle}`, the form the payer filled in. */
  var placing = null;

  /** The payment under way: `{box, locked, controller, ended, view, data, actions}`. */
  var paying = null;

  /** Leaving a payment, the shop asked whether the order goes back to a cart: `{box, data, reason}`. */
  var leaving = null;

  function t(box, key) {
    var words = box.cfg.i18n || {};
    return typeof words[key] === "string" ? words[key] : "";
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text) {
      node.textContent = text;
    }
    return node;
  }

  function setText(node, text) {
    if (node && node.textContent !== text) {
      node.textContent = text;
    }
  }

  function attached(node) {
    return !!node && !!document.body && typeof document.body.contains === "function" && document.body.contains(node);
  }

  /** The box's parts, found anew each time. */
  function parts(box) {
    var root = document.querySelector('[data-wajub-fields="' + box.kind + '"]');
    if (!root) {
      return null;
    }
    // The option's form PrestaShop submits, beside the box.
    var form = document.querySelector('form[data-wajub-form="' + box.kind + '"]');
    return {
      root: root,
      form: form,
      mount: root.querySelector("[data-wajub-fields-mount]"),
      error: root.querySelector("[data-wajub-fields-error]"),
      status: root.querySelector("[data-wajub-fields-status]"),
      actions: root.querySelector("[data-wajub-fields-actions]"),
      input: form ? form.querySelector("[data-wajub-prepared]") : null,
    };
  }

  /** A value of the option's form as drawn last (payment-fields.tpl), else the page's. */
  function formValue(box, name, fallback) {
    var p = parts(box);
    var input = p && p.form ? p.form.querySelector('input[name="' + name + '"]') : null;
    var value = input ? input.value || input.getAttribute("value") || "" : "";
    return value !== "" ? String(value) : String(fallback || "");
  }

  /** The cart's token, bound to its customer: a guest's account created since gives another. */
  function cartToken(box) {
    return formValue(box, "token", settings.token);
  }

  function cartId(box) {
    return formValue(box, "cart", settings.cart);
  }

  /** The kind of a Wajub form or box found in `node`, or ''. */
  function kindIn(node) {
    if (!node || typeof node.querySelector !== "function") {
      return "";
    }
    var found = node.querySelector("[data-wajub-form], [data-wajub-fields]");
    return found ? found.getAttribute("data-wajub-form") || found.getAttribute("data-wajub-fields") || "" : "";
  }

  /**
   * The kind of the payment option a radio selects, or ''. PrestaShop's
   * themes (Classic, Hummingbird, and most others) draw an option's form in
   * `#pay-with-<radio id>-form` and its box in `#<radio id>-additional-information`;
   * a checkout module that draws them its own way keeps the radio and its
   * option in one block: the largest one around the radio that holds no
   * other option's radio.
   */
  function kindOf(radio) {
    if (!radio) {
      return "";
    }
    var kind = "";
    if (radio.id) {
      kind = kindIn(document.getElementById("pay-with-" + radio.id + "-form")) || kindIn(document.getElementById(radio.id + "-additional-information"));
    }
    for (var node = radio.parentNode; !kind && node && node !== document.body && node.nodeType === 1; node = node.parentNode) {
      if (node.querySelectorAll('input[name="payment-option"]').length > 1) {
        break;
      }
      kind = kindIn(node);
    }
    return boxes[kind] ? kind : "";
  }

  /** The kind of the selected payment option, when it is one of these boxes. */
  function selected() {
    return kindOf(document.querySelector('input[name="payment-option"]:checked'));
  }

  /** The invoice address's country, as the box was drawn last: the form asks no country it already has. */
  function country(box) {
    var p = parts(box);
    var drawn = p ? p.root.getAttribute("data-wajub-country") : null;
    if (typeof drawn === "string" && drawn !== "") {
      return drawn;
    }
    return typeof settings.country === "string" ? settings.country : "";
  }

  /** Whether PrestaShop would place the order: an option chosen, every condition accepted. */
  function conditionsAccepted() {
    var boxesToTick = document.querySelectorAll('#conditions-to-approve input[type="checkbox"], .js-conditions-to-approve input[type="checkbox"]');
    for (var i = 0; i < boxesToTick.length; i++) {
      if (!boxesToTick[i].checked) {
        return false;
      }
    }
    return !!document.querySelector('input[name="payment-option"]:checked');
  }

  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  /** Brings a message into view and gives it the keyboard and screen reader focus. */
  function reveal(node) {
    if (!node) {
      return;
    }
    if (!node.hasAttribute("tabindex")) {
      node.setAttribute("tabindex", "-1");
    }
    if (typeof node.scrollIntoView === "function") {
      node.scrollIntoView({ behavior: reducedMotion() ? "auto" : "smooth", block: "center" });
    }
    if (typeof node.focus === "function") {
      try {
        node.focus({ preventScroll: true });
      } catch (e) {
        node.focus();
      }
    }
  }

  function go(url) {
    window.location.assign(url || window.location.href);
  }

  /** PrestaShop's "Place order", which it marks disabled as it submits the option's form. */
  function placeButtons() {
    return document.querySelectorAll("#payment-confirmation button, .js-payment-confirmation button");
  }

  /** "Place order" usable again: the order was not placed, the payer may try again. */
  function releaseButton() {
    var buttons = placeButtons();
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].classList.remove("disabled");
    }
  }

  function post(url, fields) {
    var body = Object.keys(fields)
      .map(function (key) {
        return encodeURIComponent(key) + "=" + encodeURIComponent(fields[key]);
      })
      .join("&");
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", Accept: "application/json" },
      body: body,
    }).then(function (response) {
      return response.json();
    });
  }

  // --- What the box shows ----------------------------------------------------

  function statusOf(box) {
    if (box.phase === "loading") {
      return t(box, "loading");
    }
    if (box.phase === "fallback") {
      return t(box, "fallback") || t(box, "hostedHint");
    }
    return "";
  }

  /** Writes the box's state into its parts. */
  function paint(box) {
    var p = parts(box);
    if (!p) {
      return;
    }
    var mine = paying !== null && paying.box === box;
    var error = mine ? paying.view.error : box.error;
    var tone = mine ? "error" : box.tone;

    if (p.mount) {
      p.mount.hidden = !mine && (box.phase === "fallback" || box.phase === "unavailable");
      if (box.phase === "loading" && !p.mount.hidden) {
        // From the first render: the form's room and skeleton, while its
        // payment is on its way.
        Fields.reserve(p.mount, { component: box.cfg.component, appearance: box.cfg.appearance, siteStyles: SITE_STYLES });
      } else if (!box.handle) {
        Fields.unreserve(p.mount);
      }
    }
    if (p.error) {
      // Never hidden: an alert that is not on the page when its words
      // arrive may not be read out. Empty, it takes no room (checkout.css).
      setText(p.error, error);
      p.error.hidden = false;
      p.error.classList.toggle("is-info", tone === "info");
    }
    setText(p.status, mine ? paying.view.status : leaving && leaving.box === box ? t(box, "leaving") : statusOf(box));
    if (p.status) {
      // The skeleton shows the form is loading: the words are for screen
      // readers only (checkout.css).
      p.status.classList.toggle("is-sr-only", !mine && box.phase === "loading");
    }
    paintActions(box, p);
  }

  /**
   * While paying: "Try again", "Use another payment method", and the same
   * payment on Wajub's own page, for a form that cannot finish it. Built
   * once per box node, so a button keeps the focus.
   */
  function paintActions(box, p) {
    var node = p.actions;
    if (!node) {
      return;
    }
    if (!paying || paying.box !== box) {
      if (node.children.length) {
        node.textContent = "";
      }
      node.hidden = true;
      return;
    }

    if (!paying.actions || paying.actions.node !== node) {
      node.textContent = "";
      var retry = el("button", "btn btn-primary wajub-fields__retry", t(box, "tryAgain"));
      retry.setAttribute("type", "button");
      retry.setAttribute("data-wajub-fields-retry", "");
      retry.addEventListener("click", function () {
        if (paying && paying.box === box && paying.controller) {
          paying.controller.retry();
        }
      });
      node.appendChild(retry);

      var another = el("button", "btn btn-link wajub-fields__another", t(box, "useAnother"));
      another.setAttribute("type", "button");
      another.setAttribute("data-wajub-fields-another", "");
      another.addEventListener("click", function () {
        if (paying && paying.box === box) {
          if (paying.controller) {
            paying.controller.leave("switch");
          } else {
            leave(paying, "switch");
          }
        }
      });
      node.appendChild(another);

      var hosted = null;
      if (paying.data.hostedUrl) {
        hosted = el("a", "wajub-fields__hosted", t(box, "continueHosted"));
        hosted.setAttribute("href", paying.data.hostedUrl);
        hosted.setAttribute("data-wajub-fields-hosted", "");
        node.appendChild(hosted);
      }
      paying.actions = { node: node, retry: retry, another: another, hosted: hosted };
    }

    node.hidden = false;
    // Once the payment ended unpaid, the form tries nothing more: the payer leaves.
    paying.actions.retry.hidden = !!paying.ended;
    paying.actions.retry.disabled = !!paying.view.busy;
    paying.actions.another.disabled = !!paying.view.checking || !!leaving;
  }

  // --- Mounting the form ---------------------------------------------------------

  function drop(box) {
    if (box.handle) {
      var handle = box.handle;
      box.handle = null;
      handle.destroy();
    }
  }

  /** The form cannot run here: the option's form posts no payment, and the shop starts one on Wajub's page. */
  function fallBack(box) {
    drop(box);
    box.phase = "fallback";
    box.error = "";
    paint(box);
  }

  /** Wajub cannot take this order with this option: the payer is told to choose another. */
  function unavailable(box, message) {
    drop(box);
    box.phase = "unavailable";
    box.error = message;
    box.tone = "error";
    paint(box);
  }

  /** Whether the shop's answer says this option cannot take the order, rather than that it could not answer. */
  function refusesMethod(error) {
    return error.code === "method_unavailable" || error.code === "amount_out_of_range";
  }

  function failed(box, error) {
    box.failedToken = box.cfg.token;
    if (error.code === "method_unavailable") {
      unavailable(box, t(box, "unavailable") || error.message);
      return;
    }
    if (error.code === "amount_out_of_range") {
      unavailable(box, t(box, "amountOutOfRange") || error.message);
      return;
    }
    fallBack(box);
  }

  function loadError(box, error) {
    var code = typeof error.code === "string" ? error.code : "";
    if (paying && paying.box === box) {
      // The form of a payment under way did not load again. A session that
      // ended ends the payment; anything else leaves it to the payer, with
      // the same payment on Wajub's page at hand.
      if (code.indexOf("session_") === 0 && paying.controller) {
        paying.controller.leave("expired");
      }
      var p = parts(box);
      if (p && p.mount) {
        Fields.unreserve(p.mount);
      }
      return;
    }
    // The session the form was given ended (it expired, the shop's keys
    // changed): one new payment, once.
    if (code.indexOf("session_") === 0 && !box.renewed) {
      box.renewed = true;
      drop(box);
      box.session = null;
      load(box, true);
      return;
    }
    if (code === "method_unavailable") {
      unavailable(box, t(box, "unavailable"));
      return;
    }
    fallBack(box);
  }

  /**
   * Enter pressed in the form. Before the payment is activated, the form may
   * not charge: the order is placed instead, as "Place order" would place it
   * (PrestaShop checks the terms first). Once it is, the form charges by
   * itself, and the payment listens to it.
   */
  function beforeSubmit(box, activated) {
    if (activated) {
      if (paying && paying.box === box && paying.controller) {
        paying.controller.submitted();
      }
      return;
    }
    if (paying || placing) {
      return;
    }
    setTimeout(function () {
      var buttons = placeButtons();
      if (!paying && !placing && buttons.length && typeof buttons[0].click === "function") {
        buttons[0].click();
      }
    }, 0);
  }

  function mountOptions(box) {
    var cfg = box.cfg;
    return {
      sessionId: box.session.sessionId,
      component: cfg.component,
      locale: cfg.locale,
      appearance: cfg.appearance,
      siteStyles: SITE_STYLES,
      country: country(box),
      componentOptions: cfg.componentOptions,
      frameTitle: t(box, "frameTitle"),
      onReady: function (handle) {
        if (box.handle !== handle) {
          return;
        }
        box.renewed = false;
        if (box.phase === "loading") {
          box.phase = "ready";
        }
        paint(box);
      },
      onChange: function (change, handle) {
        if (box.handle !== handle || (paying && paying.box === box)) {
          return;
        }
        // What the payer was told before (the form is incomplete) goes once
        // they complete the form.
        if (box.error && (change.complete || change.empty === false)) {
          box.error = "";
          paint(box);
        }
      },
      onLoadError: function (error, handle) {
        if (box.handle === handle) {
          loadError(box, error);
        }
      },
      onBeforeSubmit: function (handle, activated) {
        if (box.handle === handle) {
          beforeSubmit(box, activated);
        }
      },
    };
  }

  function mountBox(box) {
    var p = parts(box);
    if (!p || !p.mount || !box.session) {
      return;
    }
    var handle = Fields.mount(p.mount, mountOptions(box));
    if (!handle) {
      fallBack(box);
      return;
    }
    box.handle = handle;
    box.phase = "loading";
    paint(box);
  }

  /** A payment for the box: mounted unless it is the one the form already runs on. */
  function adopt(box, answer) {
    var same = box.session !== null && box.session.paymentId === answer.paymentId && box.session.sessionId === answer.sessionId;
    box.session = answer;
    var p = parts(box);
    if (same && box.handle && p && box.handle.container === p.mount && attached(p.mount)) {
      return;
    }
    drop(box);
    mountBox(box);
  }

  /**
   * Asks the shop for the box's payment, and mounts the form on it. With
   * `recheck`, the box already holds one, and asks only whether the shop
   * still takes it.
   */
  function load(box, renew, recheck) {
    if (box.loading) {
      return;
    }
    box.loading = true;
    box.cfg.token = cartToken(box);
    if (!box.handle) {
      box.phase = "loading";
      paint(box);
    }
    Fields.prepare(box.cfg, { renew: renew }).then(function (answer) {
      box.loading = false;
      if (paying && paying.box === box) {
        return;
      }
      if (answer.error) {
        if (recheck && box.session && !refusesMethod(answer.error)) {
          // The shop could not answer: nothing said the payment the form
          // holds was refused, so the form keeps it.
          if (selected() === box.kind) {
            ensure(box);
          }
          return;
        }
        failed(box, answer.error);
        return;
      }
      adopt(box, answer);
    });
  }

  /** The selected option's form, mounted in its box. */
  function ensure(box) {
    if (box.phase === "fallback" || box.phase === "unavailable") {
      return;
    }
    var p = parts(box);
    if (!p || !p.mount) {
      return;
    }
    if (box.handle && box.handle.container === p.mount && attached(p.mount)) {
      return;
    }
    drop(box);
    if (!Fields.available()) {
      fallBack(box);
      return;
    }
    if (box.loading) {
      return; // The payment on its way mounts it.
    }
    if (box.session) {
      mountBox(box);
      return;
    }
    load(box, false);
  }

  function sync() {
    if (paying) {
      return;
    }
    var chosen = selected();
    kinds.forEach(function (kind) {
      var box = boxes[kind];
      if (box.handle && !attached(box.handle.container)) {
        drop(box);
      }
      if (box.failedToken && !box.loading && (box.phase === "fallback" || box.phase === "unavailable") && cartToken(box) !== box.failedToken) {
        // Drawn again for another cart or customer (a guest's account
        // created since): what the shop refused may be taken now.
        box.failedToken = "";
        box.phase = "idle";
        box.error = "";
        box.session = null;
      }
      paint(box);
    });
    if (boxes[chosen]) {
      ensure(boxes[chosen]);
    }
  }

  // --- Placing the order --------------------------------------------------------

  /**
   * The option's form submitted (PrestaShop's "Place order", once the terms
   * are accepted). Whether the native submission goes on: only for a box
   * whose form could not run, which posts no payment, so that the shop
   * starts one on Wajub's hosted page.
   */
  function gate(kind) {
    var box = boxes[kind];
    if (!box || paying || placing || leaving) {
      return false;
    }
    var p = parts(box);
    if (box.phase === "fallback" || neverStarted(box)) {
      if (p && p.input) {
        p.input.value = "";
      }
      return true;
    }
    if (box.phase === "unavailable") {
      box.error = box.error || t(box, "unavailable");
      box.tone = "error";
      paint(box);
      reveal(p && p.error);
      releaseButton();
      return false;
    }
    // Enter in the form: the frame asked only once its fields were valid,
    // though it reports itself incomplete until it has heard the answer
    // (submitAsked()).
    if (!box.handle || !box.session || !(box.handle.isComplete() || box.handle.submitAsked())) {
      box.error = t(box, "incomplete");
      box.tone = "error";
      paint(box);
      if (box.handle) {
        box.handle.focus();
      } else if (p && p.mount) {
        reveal(p.mount);
      }
      releaseButton();
      return false;
    }
    place(box);
    return false;
  }

  /**
   * A box whose form was never mounted, nor asked for: its option was never
   * seen selected (a checkout module that draws its options in a way this
   * script cannot read). Its form posts no payment, and the payer pays on
   * Wajub's page, rather than being asked to fill in a form that is not
   * there.
   */
  function neverStarted(box) {
    return box.phase === "idle" && !box.handle && !box.session && !box.loading;
  }

  /** Asks the shop to place the order, with the payment the form holds. */
  function place(box) {
    var p = parts(box);
    placing = { kind: box.kind, paymentId: box.session.paymentId, handle: box.handle };
    if (p && p.input) {
      p.input.value = box.session.paymentId;
    }
    box.error = "";
    paint(box);
    setText(p && p.status, t(box, "placing"));
    post(settings.validationUrl, {
      ajax: "1",
      kind: box.kind,
      token: cartToken(box),
      cart: cartId(box),
      wajub_prepared: box.session.paymentId,
    })
      .then(function (answer) {
        var claim = placing;
        placing = null;
        var data = answer && typeof answer.data === "object" && answer.data ? answer.data : {};
        if (answer && answer.success === true && data.fields === "1") {
          if (
            !claim ||
            claim.kind !== box.kind ||
            data.paymentId !== claim.paymentId ||
            !box.handle ||
            !box.session ||
            data.paymentId !== box.session.paymentId ||
            // The options drawn again meanwhile: the form the payer filled
            // in is gone, and a new one would be submitted empty.
            box.handle !== claim.handle ||
            !attached(box.handle.container)
          ) {
            // Not the payment this form holds: the same payment, on Wajub's page.
            go(data.hostedUrl || data.returnUrl);
            return;
          }
          start(box, data);
          return;
        }
        if (answer && answer.success === true && data.redirect) {
          go(data.redirect);
          return;
        }
        refusedPlace(box, data);
      })
      .catch(function () {
        placing = null;
        refusedPlace(box, { code: "unavailable", message: t(box, "temporarilyUnavailable") });
      });
  }

  /** The order was not placed, or was given back: the payer reads why, and may try again. */
  function refusedPlace(box, data) {
    var code = typeof data.code === "string" ? data.code : "unavailable";
    var message = typeof data.message === "string" && data.message ? data.message : t(box, "startFailed");
    if (typeof data.redirect === "string" && data.redirect) {
      // The shop gave the cart back, or the order is paid: the page there
      // says what happened.
      go(data.redirect);
      return;
    }
    releaseButton();
    if (code === "method_unavailable" || code === "amount_out_of_range") {
      unavailable(box, message);
      reveal(parts(box) && parts(box).error);
      return;
    }
    box.error = message;
    box.tone = "error";
    paint(box);
    if (code === "refreshed") {
      // The payment the form held cannot pay the order: a new one.
      drop(box);
      box.session = null;
      load(box, true);
    }
    reveal(parts(box) && parts(box).error);
  }

  /**
   * Locks everything on the page but the box: each element beside it, and
   * beside each of its ancestors up to the page's content, becomes inert
   * (out of reach of the mouse, the keyboard and screen readers). Returns
   * what it locked, for unlock().
   */
  function lock(keep) {
    var locked = [];
    var top = document.getElementById("content") || document.body;
    var node = keep;
    while (node && node !== top && node.parentNode) {
      var siblings = node.parentNode.children || [];
      for (var i = 0; i < siblings.length; i++) {
        var sibling = siblings[i];
        if (sibling !== node && sibling.nodeType === 1 && !sibling.hasAttribute("inert") && sibling.tagName !== "SCRIPT") {
          sibling.setAttribute("inert", "");
          sibling.classList.add("wajub-locked");
          locked.push(sibling);
        }
      }
      node = node.parentNode;
    }
    var buttons = placeButtons();
    for (var j = 0; j < buttons.length; j++) {
      if (!buttons[j].disabled) {
        buttons[j].disabled = true;
        buttons[j].setAttribute("data-wajub-fields-disabled", "");
      }
    }
    (document.documentElement || document.body).classList.add("wajub-fields-paying");
    return locked;
  }

  function start(box, data) {
    var p = parts(box);
    var current = {
      box: box,
      data: data,
      locked: [],
      controller: null,
      ended: false,
      view: { status: "", error: "", busy: true, checking: false },
      actions: null,
    };
    paying = current;
    box.error = "";
    // The option's own words (its test badge, its description) stay with the box.
    current.locked = lock(p ? (typeof p.root.closest === "function" && p.root.closest(".wajub-payment-info")) || p.root : null);

    current.controller = Fields.run(box.handle, {
      statusUrl: data.statusUrl || "",
      returnUrl: data.returnUrl || "",
      i18n: box.cfg.i18n || {},
      onUpdate: function (view) {
        if (paying === current) {
          current.view = view;
          paint(box);
        }
      },
      onEnd: function (end) {
        if (paying !== current) {
          return;
        }
        if (end.type === "paid" || end.type === "confirming") {
          // The checkout stays locked while the page leaves.
          go(end.url || data.returnUrl);
          return;
        }
        current.controller = null;
        current.ended = true;
        current.view = { status: "", error: end.message || "", busy: false, checking: false };
        paint(box);
        leave(current, end.reason || "switch");
      },
    });
  }

  /**
   * The payment ended unpaid, or the payer chose another way to pay: the
   * shop gives the order back as a cart, unless Wajub may still charge it,
   * and the page goes where the shop says. Wajub out of reach: the payer
   * reads it, and may ask again.
   */
  function leave(current, reason) {
    if (leaving) {
      return;
    }
    var box = current.box;
    leaving = { box: box, reason: reason };
    paint(box);
    post(current.data.releaseUrl, {
      ajax: "1",
      reason: reason,
      id_order: String(current.data.orderId || ""),
      token: current.data.orderToken || "",
    })
      .then(function (answer) {
        var data = answer && answer.success && answer.data ? answer.data : {};
        if ((data.to === "checkout" || data.to === "confirmation") && data.url) {
          go(data.url);
          return;
        }
        leaving = null;
        current.view = { status: "", error: data.message || t(box, "temporarilyUnavailable"), busy: false, checking: false };
        paint(box);
        reveal(parts(box) && parts(box).error);
      })
      .catch(function () {
        leaving = null;
        current.view = { status: "", error: t(box, "temporarilyUnavailable"), busy: false, checking: false };
        paint(box);
      });
  }

  /**
   * "Place order" with a box's option selected: taken over before
   * PrestaShop's own handler, which would submit the option's form, once
   * the conditions are accepted (PrestaShop says what is missing
   * otherwise). The cart is checked by the shop as the order is placed.
   * The options' forms are watched too, for anything else that submits one.
   */
  function bind() {
    document.addEventListener(
      "click",
      function (event) {
        var target = event.target;
        var button = target && typeof target.closest === "function" ? target.closest("#payment-confirmation button, .js-payment-confirmation button") : null;
        var kind = button ? selected() : "";
        if (!kind || !conditionsAccepted()) {
          return;
        }
        if (!gate(kind)) {
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }
        // A box whose form could not run: PrestaShop submits its form, which
        // posts no payment, as it would for any option.
      },
      true
    );

    var $ = window.jQuery;
    var onSubmit = function (event, form) {
      var kind = form && form.getAttribute ? form.getAttribute("data-wajub-form") : "";
      if (kind && boxes[kind] && boxes[kind].phase !== "fallback") {
        // Not through "Place order": the box decides.
        if (!gate(kind)) {
          event.preventDefault();
          if (typeof event.stopImmediatePropagation === "function") {
            event.stopImmediatePropagation();
          }
        }
      }
    };
    if (typeof $ === "function") {
      $(document).on("submit", "form[data-wajub-form]", function (event) {
        onSubmit(event, event.currentTarget);
      });
    }
    document.addEventListener(
      "submit",
      function (event) {
        onSubmit(event, event.target);
      },
      true
    );

    document.addEventListener("change", function (event) {
      var target = event.target;
      if (target && target.name === "payment-option") {
        // After PrestaShop shows the option's box.
        setTimeout(sync, 0);
      }
    });
  }

  /**
   * Each option's form, however it is submitted: a script that calls its
   * submit() skips the submit event, so the form's own submit() goes
   * through the gate too. Marked, so it is done once per form drawn.
   */
  function guardForms() {
    var forms = document.querySelectorAll("form[data-wajub-form]");
    for (var i = 0; i < forms.length; i++) {
      var form = forms[i];
      if (form.hasAttribute("data-wajub-guarded")) {
        continue;
      }
      form.setAttribute("data-wajub-guarded", "");
      form.submit = guardedSubmit(form, form.submit);
    }
  }

  function guardedSubmit(form, native) {
    return function () {
      var kind = form.getAttribute("data-wajub-form") || "";
      if (boxes[kind] && boxes[kind].phase !== "fallback" && !gate(kind)) {
        return;
      }
      var submit = window.HTMLFormElement && window.HTMLFormElement.prototype.submit;
      (typeof submit === "function" ? submit : native).call(form);
    };
  }

  var scheduled = false;

  /** The options as drawn now, looked at once the page stops changing. */
  function schedule() {
    if (scheduled) {
      return;
    }
    scheduled = true;
    setTimeout(function () {
      scheduled = false;
      guardForms();
      sync();
    }, 0);
  }

  /** Whether a change of the page drew, or took away, a payment option or a Wajub box. */
  function concernsOptions(records) {
    var wanted = '[data-wajub-fields], [data-wajub-form], input[name="payment-option"]';
    for (var i = 0; i < records.length; i++) {
      var lists = [records[i].addedNodes || [], records[i].removedNodes || []];
      for (var l = 0; l < lists.length; l++) {
        for (var n = 0; n < lists[l].length; n++) {
          var node = lists[l][n];
          if (node && node.nodeType === 1 && ((typeof node.matches === "function" && node.matches(wanted)) || (typeof node.querySelector === "function" && node.querySelector(wanted)))) {
            return true;
          }
        }
      }
    }
    return false;
  }

  /**
   * The payment step drawn again: by PrestaShop (its checkout events), or by
   * anything else that replaces the options (a mutation of the page that
   * adds or removes one).
   */
  function watch() {
    if (typeof window.MutationObserver === "function" && document.body) {
      new window.MutationObserver(function (records) {
        if (concernsOptions(records)) {
          schedule();
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
    var prestashop = window.prestashop;
    if (prestashop && typeof prestashop.on === "function") {
      ["updatedDeliveryForm", "updatedAddressForm", "changedCheckoutStep", "updatedCart"].forEach(function (name) {
        prestashop.on(name, schedule);
      });
    }
  }

  function init() {
    bind();
    guardForms();
    watch();
    sync();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  /** For a checkout module that draws the payment options itself: call sync() once they are drawn. */
  window.WajubCheckout = {
    sync: function () {
      guardForms();
      sync();
    },
  };
})();

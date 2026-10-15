/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The order confirmation page of a Wajub order (views/templates/hook/payment-return.tpl).
 * A port of the WordPress plugin's order-status.js.
 *
 * While the order waits for its payment (a Mobile Money approval on the
 * payer's phone), asks the shop how the payment stands (controllers/front/status.php)
 * — after 5 seconds at first, a little less often after, never more than
 * every 10 — for about five minutes, and only while the page is visible.
 * The shop shares Wajub's answer between every page that asks for a few
 * seconds: asking more often would learn nothing sooner. Paid: says so and
 * reloads, so PrestaShop shows the paid order. Refused, cancelled or
 * expired: says which and offers "Try again", which gives the order back as
 * a cart (controllers/front/release.php) to pay again, any way the shop
 * offers. Part of the order paid: says what was received and what is left,
 * with "Pay the rest" (controllers/front/pay.php) when the payer can pay it
 * now, and that instead of "Try again" for an order part of which is paid,
 * which is never given back as a cart. Still nothing after five minutes:
 * says so, with "Check again".
 *
 * Also gives the payment ID its "Copy" button.
 */
(function () {
  "use strict";

  var settings = window.wajubOrderStatus || {};
  var i18n = settings.i18n || {};
  var FIRST_DELAY = 5000;
  var STEP = 500;
  var MAX_DELAY = 10000;
  var ERROR_DELAY = 15000;

  function t(key) {
    return i18n[key] || "";
  }

  function find(panel, name) {
    return panel.querySelector("[data-wajub-" + name + "]");
  }

  function setText(node, text) {
    if (node) {
      node.textContent = text;
    }
  }

  function setState(panel, state) {
    var states = ["waiting", "paid", "failed", "timeout", "partial"];
    for (var i = 0; i < states.length; i++) {
      panel.classList.remove("wajub-order-status--" + states[i]);
    }
    panel.classList.add("wajub-order-status--" + state);
  }

  function clearActions(panel) {
    var actions = find(panel, "actions");
    if (actions) {
      actions.textContent = "";
      actions.hidden = true;
    }
    return actions;
  }

  /** The heading takes the focus when the panel changes for good, so it is read out. */
  function announce(panel, title, lead) {
    var heading = find(panel, "title");
    setText(heading, title);
    setText(find(panel, "lead"), lead);
    var steps = find(panel, "steps");
    if (steps) {
      steps.hidden = true;
    }
    if (heading && typeof heading.focus === "function") {
      heading.focus();
    }
  }

  function watch(panel) {
    var url = panel.getAttribute("data-status-url");
    if (!url) {
      return;
    }
    // Where "Try again" gives the order back as a cart, signed for it.
    var releaseUrl = panel.getAttribute("data-release-url") || "";
    // Where "Pay the rest" pays what is left of the order, signed for it.
    var payUrl = panel.getAttribute("data-pay-url") || "";
    var token = panel.getAttribute("data-token") || "";
    var limit = (parseInt(panel.getAttribute("data-timeout"), 10) || 300) * 1000;
    var live = find(panel, "live");
    var startedAt = 0;
    var attempt = 0;
    var timer = null;
    var inFlight = false;
    var done = false;
    // "Check again", once the first wait has run out.
    var again = null;
    // A check the payer asked for with it, not answered yet.
    var checking = false;

    function schedule(delay) {
      clearTimeout(timer);
      timer = setTimeout(poll, delay);
    }

    function nextDelay() {
      return Math.min(FIRST_DELAY + attempt * STEP, MAX_DELAY);
    }

    function paid() {
      done = true;
      setState(panel, "paid");
      // The heading takes the focus before "Check again", which may hold it, goes.
      announce(panel, t("paidTitle"), "");
      clearActions(panel);
      setText(live, t("paidText"));
      setTimeout(function () {
        window.location.reload();
      }, 1500);
    }

    /** The heading of a payment that did not go through, by the reason the store gives. */
    function failedTitle(data) {
      var reason = data && data.reason;
      if (reason === "cancelled" && t("cancelledTitle")) {
        return t("cancelledTitle");
      }
      if (reason === "expired" && t("expiredTitle")) {
        return t("expiredTitle");
      }
      return t("failedTitle");
    }

    /**
     * "Try again": a form that posts to the shop, which gives the order back
     * as a cart and opens the checkout with it; "Pay the rest": one that
     * starts the payment of what is left. Forms rather than links: they
     * change the order.
     */
    function actionForm(label, action, fields) {
      var form = document.createElement("form");
      form.setAttribute("method", "post");
      form.setAttribute("action", action);
      form.className = "wajub-order-status__retry";
      for (var name in fields) {
        if (Object.prototype.hasOwnProperty.call(fields, name)) {
          var input = document.createElement("input");
          input.setAttribute("type", "hidden");
          input.setAttribute("name", name);
          input.setAttribute("value", fields[name]);
          form.appendChild(input);
        }
      }
      var button = document.createElement("button");
      button.setAttribute("type", "submit");
      button.className = "btn btn-primary wajub-button";
      button.textContent = label;
      form.appendChild(button);
      return form;
    }

    function retryForm() {
      return actionForm(t("retry"), releaseUrl, { token: token, reason: "failed" });
    }

    function payForm() {
      return actionForm(t("payRest"), payUrl, { token: token });
    }

    function failed(data) {
      done = true;
      setState(panel, "failed");
      announce(panel, failedTitle(data), (data && data.message) || t("failedText"));
      setText(live, "");
      var actions = clearActions(panel);
      if (actions && releaseUrl && data && data.retry) {
        actions.appendChild(retryForm());
        actions.hidden = false;
      } else if (actions && payUrl && data && data.pay) {
        actions.appendChild(payForm());
        actions.hidden = false;
      }
    }

    /**
     * Part of the order is paid: nothing more to wait for here. What was
     * received and what is left. A cancelled order Wajub took part of says
     * so: its items back in a cart would be paid again.
     */
    function partial(data) {
      done = true;
      setState(panel, "partial");
      var cancelled = data && data.status === "failed";
      announce(panel, (cancelled && t("cancelledOrderTitle")) || t("partialTitle"), (data && data.message) || "");
      setText(live, "");
      var actions = clearActions(panel);
      if (actions && payUrl && data && data.pay && !cancelled) {
        actions.appendChild(payForm());
        actions.hidden = false;
      }
    }

    /**
     * A payment that never resolves (a session left open, a provider that
     * never answers): the payer reads what to do, with "Check again". Not
     * offered while waiting: a "pending" answer can be a few seconds old
     * right after a payment made in the checkout itself.
     */
    function timedOut() {
      done = true;
      setState(panel, "timeout");
      setText(live, "");
      endCheck();
      // A wait "Check again" started that runs out too leaves the panel, its
      // words and the focus as they are: they already say all of this.
      if (again) {
        return;
      }
      announce(panel, t("timeoutTitle"), t("timeoutText"));
      var actions = clearActions(panel);
      if (actions) {
        again = document.createElement("button");
        again.type = "button";
        again.className = "btn btn-primary wajub-button";
        again.textContent = t("checkAgain");
        again.addEventListener("click", checkAgain);
        actions.appendChild(again);
        actions.hidden = false;
      }
    }

    /**
     * "Check again": asks now, then keeps asking for another five minutes.
     * The button stays where it is, so the focus does, marked inactive (not
     * `disabled`, which would drop the focus) until the answer comes; the
     * other method stays offered, as the words above it say.
     */
    function checkAgain() {
      if (checking) {
        return;
      }
      checking = true;
      again.setAttribute("aria-disabled", "true");
      done = false;
      attempt = 0;
      startedAt = Date.now();
      setState(panel, "waiting");
      setText(live, t("checking") || t("waiting"));
      clearTimeout(timer);
      // A check already on its way answers this one too.
      if (!inFlight) {
        poll();
      }
    }

    /** The check the payer asked for has its answer: "Check again" works again. */
    function endCheck() {
      checking = false;
      if (again) {
        again.removeAttribute("aria-disabled");
      }
    }

    /** Pending again: ask later, or stop asking once the payer has waited long enough. */
    function later(delay) {
      if (Date.now() - startedAt >= limit) {
        timedOut();
        return;
      }
      schedule(delay);
    }

    function poll() {
      if (done || inFlight) {
        return;
      }
      // A hidden tab is not asked about: it is asked again once it is back,
      // before it can say "still waiting".
      if (document.visibilityState === "hidden") {
        return;
      }
      inFlight = true;
      fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } })
        .then(function (response) {
          return response.json();
        })
        .then(function (body) {
          inFlight = false;
          var data = body && body.success ? body.data || {} : null;
          if (data && data.partial) {
            endCheck();
            partial(data);
            return;
          }
          if (data && data.status === "paid") {
            paid();
            return;
          }
          if (data && data.status === "failed") {
            failed(data);
            return;
          }
          stillWaiting();
          later(data ? nextDelay() : ERROR_DELAY);
        })
        .catch(function () {
          inFlight = false;
          stillWaiting();
          later(ERROR_DELAY);
        });
    }

    /** No answer yet: the payer who asked hears that the page keeps waiting. */
    function stillWaiting() {
      attempt++;
      if (checking) {
        setText(live, t("waiting"));
        endCheck();
      }
    }

    document.addEventListener("visibilitychange", function () {
      if (!done && document.visibilityState === "visible") {
        schedule(0);
      }
    });

    startedAt = Date.now();
    schedule(FIRST_DELAY);
  }

  function copy(button) {
    var value = button.getAttribute("data-wajub-copy") || "";
    var feedback = button.parentNode ? button.parentNode.querySelector(".wajub-copy-feedback") : null;
    function say(text) {
      setText(feedback, text);
      setTimeout(function () {
        setText(feedback, "");
      }, 4000);
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      navigator.clipboard.writeText(value).then(
        function () {
          say(t("copied"));
        },
        function () {
          say(t("copyFailed"));
        }
      );
      return;
    }
    say(t("copyFailed"));
  }

  function init() {
    var panels = document.querySelectorAll("[data-wajub-order-status]");
    for (var i = 0; i < panels.length; i++) {
      watch(panels[i]);
    }

    // Shown only where copying works: without the clipboard the reference
    // stays selectable text.
    var buttons = document.querySelectorAll("[data-wajub-copy]");
    for (var j = 0; j < buttons.length; j++) {
      if (navigator.clipboard) {
        buttons[j].hidden = false;
      }
      buttons[j].addEventListener("click", function (event) {
        copy(event.currentTarget);
      });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.WajubOrderStatus = { watch: watch };
})();

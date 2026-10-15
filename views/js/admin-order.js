/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The "Wajub payment" panel of the back office's order page
 * (views/templates/hook/admin-order.tpl): "Check status" reads the order's
 * payment from Wajub and settles the order as its webhook would
 * (Admin\OrderPanel::check(), through the settings page's AJAX with its
 * token), says why the order is where it is, then reloads the page when the
 * order changed. Once the page has loaded, the payments of the order that
 * could not count yet are read again (OrderPanel::refresh()): the page
 * never waits on Wajub to open, and reloads when they changed the order.
 */
(function () {
  "use strict";

  function init() {
    var panel = document.querySelector("[data-wajub-order-panel]");
    if (!panel || panel.getAttribute("data-wajub-ready") === "1") {
      return;
    }
    panel.setAttribute("data-wajub-ready", "1");
    var button = panel.querySelector("[data-wajub-check-status]");
    var result = panel.querySelector("[data-wajub-check-result]");
    var url = panel.getAttribute("data-ajax-url") || "";
    if (!url || typeof fetch !== "function") {
      return;
    }

    function post(action) {
      var body =
        "ajax=1&action=" +
        action +
        "&id_order=" +
        encodeURIComponent(panel.getAttribute("data-order") || "") +
        "&wajub_token=" +
        encodeURIComponent(panel.getAttribute("data-token") || "");
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

    function say(text, tone) {
      if (!result) {
        return;
      }
      result.textContent = text || "";
      result.className = "wajub-order-panel__result" + (tone ? " text-" + (tone === "error" ? "danger" : tone) : "");
    }

    if (panel.getAttribute("data-refresh") === "1") {
      post("refresh_order")
        .then(function (answer) {
          var data = answer && answer.data ? answer.data : {};
          if (answer && answer.success && data.changed) {
            say(data.message, "info");
            setTimeout(function () {
              window.location.reload();
            }, 1500);
          }
        })
        .catch(function () {});
    }

    if (!button) {
      return;
    }
    button.addEventListener("click", function () {
      if (button.getAttribute("aria-disabled") === "true") {
        return;
      }
      button.setAttribute("aria-disabled", "true");
      say("", "");
      post("check_order")
        .then(function (answer) {
          var data = answer && answer.data ? answer.data : {};
          say(typeof data.message === "string" ? data.message : "", data.tone || "");
          if (answer && answer.success && data.reload) {
            setTimeout(function () {
              window.location.reload();
            }, 1500);
            return;
          }
          button.removeAttribute("aria-disabled");
        })
        .catch(function () {
          button.removeAttribute("aria-disabled");
        });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

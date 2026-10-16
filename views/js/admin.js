/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * The module's settings page: its tabs, "Test connection", "Register
 * automatically", the Copy buttons of the webhook's and the scheduled
 * task's addresses, and the confirmation before Live is turned on (which
 * says, too, when the shop cannot bring payers back over HTTPS). No dependency. Words, the page's token and the
 * AJAX address come from PHP in `wajubAdmin`. Without this script the page
 * still works: each tab is a link, and each form posts.
 */
(function (window, document) {
  "use strict";

  var config = window.wajubAdmin || {};
  var i18n = config.i18n || {};

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  /** POSTs one action to the module's page; resolves with `data`, rejects with the shop's words. */
  function post(action, fields) {
    function failed() {
      throw new Error(t("requestFailed", "The request did not go through."));
    }

    var body = new window.URLSearchParams();
    body.append("ajax", "1");
    body.append("action", action);
    body.append("wajub_token", config.token || "");
    Object.keys(fields || {}).forEach(function (name) {
      body.append(name, fields[name]);
    });
    return window
      .fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-Requested-With": "XMLHttpRequest" },
        body: body.toString(),
      })
      .then(function (response) {
        return response.json().then(function (json) {
          if (json && json.success) {
            return json.data || {};
          }
          throw new Error((json && json.data && json.data.message) || t("requestFailed", "The request did not go through."));
        }, failed);
      }, failed);
  }

  // --- Tabs ----------------------------------------------------------------

  /**
   * The tabs as WAI-ARIA tabs: the arrow keys, Home and End move between
   * them, and the address keeps the tab open, so a reload or a save comes
   * back to it.
   */
  function initTabs(root) {
    var list = root.querySelector("[data-wajub-tabs]");
    if (!list) {
      return;
    }
    var tabs = Array.prototype.slice.call(list.querySelectorAll("[data-wajub-tab]"));

    function select(tab, focus) {
      tabs.forEach(function (each) {
        var selected = each === tab;
        each.setAttribute("aria-selected", selected ? "true" : "false");
        each.setAttribute("tabindex", selected ? "0" : "-1");
        // Both: PrestaShop 8's back office marks the tab's item (Bootstrap
        // 3), PrestaShop 9's the link itself (Bootstrap 4).
        each.classList.toggle("active", selected);
        if (each.parentNode) {
          each.parentNode.classList.toggle("active", selected);
        }
        var panel = document.getElementById(each.getAttribute("aria-controls"));
        if (panel) {
          panel.hidden = !selected;
        }
      });
      if (focus) {
        tab.focus();
      }
      try {
        var url = new window.URL(window.location.href);
        url.searchParams.set("wajub_tab", tab.getAttribute("data-wajub-tab"));
        window.history.replaceState(null, "", url.toString());
      } catch (error) {
        // An old browser: the tab is still shown, and the address is left.
      }
    }

    tabs.forEach(function (tab, index) {
      tab.addEventListener("click", function (event) {
        event.preventDefault();
        select(tab, false);
      });
      tab.addEventListener("keydown", function (event) {
        var next = null;
        if (event.key === "ArrowRight") {
          next = tabs[(index + 1) % tabs.length];
        } else if (event.key === "ArrowLeft") {
          next = tabs[(index - 1 + tabs.length) % tabs.length];
        } else if (event.key === "Home") {
          next = tabs[0];
        } else if (event.key === "End") {
          next = tabs[tabs.length - 1];
        }
        if (next) {
          event.preventDefault();
          select(next, true);
        }
      });
    });
  }

  // --- Connection ----------------------------------------------------------

  function initConnection(root) {
    var box = root.querySelector("[data-wajub-connection]");
    var button = root.querySelector("[data-wajub-test-connection]");
    if (!box || !button) {
      return;
    }
    var text = box.querySelector("[data-wajub-connection-text]");
    var dot = box.querySelector("[data-wajub-connection-dot]");

    function show(label, detail, tone) {
      if (text) {
        text.textContent = "";
        var strong = document.createElement("strong");
        strong.className = "wajub-connection-label";
        strong.textContent = label;
        var span = document.createElement("span");
        span.className = "wajub-connection-detail";
        span.textContent = detail;
        text.appendChild(strong);
        text.appendChild(document.createTextNode(" "));
        text.appendChild(span);
      }
      if (dot) {
        dot.className = "wajub-dot wajub-dot--" + tone;
      }
    }

    function test() {
      var label = button.textContent;
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
      button.textContent = t("testing", "Checking…");
      post("test_connection", {}).then(
        function (data) {
          show(data.label || "", data.detail || "", data.tone || "neutral");
        },
        function (error) {
          show(error.message, "", "error");
        }
      ).then(function () {
        button.disabled = false;
        button.removeAttribute("aria-busy");
        button.textContent = label;
      });
    }

    button.addEventListener("click", test);

    // Nothing at all is known about the key in use: asked once, now that
    // the page has loaded.
    if (box.getAttribute("data-status") === "unknown") {
      test();
    }
  }

  /** Live takes real payments: the merchant confirms before it is saved. */
  function initLiveConfirmation(root) {
    var form = root.querySelector("[data-wajub-connection-form]");
    var radio = root.querySelector("[data-wajub-live-radio]");
    if (!form || !radio) {
      return;
    }
    form.addEventListener("submit", function (event) {
      if (radio.checked && radio.getAttribute("data-was-live") !== "1" && !window.confirm(t("confirmLive", "Switch to Live?"))) {
        event.preventDefault();
      }
    });
  }

  /** A button whose action cannot be undone from this page asks first (`data-wajub-confirm`). */
  function initConfirms(root) {
    Array.prototype.forEach.call(root.querySelectorAll("[data-wajub-confirm]"), function (button) {
      button.addEventListener("click", function (event) {
        if (!window.confirm(button.getAttribute("data-wajub-confirm") || "")) {
          event.preventDefault();
        }
      });
    });
  }

  // --- Webhooks ------------------------------------------------------------

  function initWebhooks(root) {
    var buttons = root.querySelectorAll("[data-wajub-register-webhooks]");
    var results = root.querySelector("[data-wajub-webhook-results]");
    if (!buttons.length || !results) {
      return;
    }

    function render(lines, ok) {
      results.textContent = "";
      lines.forEach(function (line) {
        var paragraph = document.createElement("p");
        paragraph.className = "alert alert-" + line.tone;
        paragraph.textContent = line.text;
        results.appendChild(paragraph);
      });
      if (ok) {
        var reload = document.createElement("button");
        reload.type = "button";
        reload.className = "btn btn-default";
        reload.textContent = (window.wajubPairing && window.wajubPairing.i18n && window.wajubPairing.i18n.reload) || "Reload this page";
        reload.addEventListener("click", function () {
          window.location.reload();
        });
        results.appendChild(reload);
      }
      results.focus();
    }

    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener("click", function () {
        var label = button.textContent;
        Array.prototype.forEach.call(buttons, function (each) {
          each.disabled = true;
        });
        button.setAttribute("aria-busy", "true");
        button.textContent = t("registering", "Registering…");
        post("register_webhooks", { rotate: button.getAttribute("data-rotate") === "1" ? "1" : "0" }).then(
          function (data) {
            var any = false;
            render(
              (data.results || []).map(function (result) {
                any = any || !!result.ok;
                return { tone: result.ok ? "success" : result.manual ? "warning" : "danger", text: result.label + ": " + result.message };
              }),
              any
            );
          },
          function (error) {
            render([{ tone: "danger", text: error.message }], false);
          }
        ).then(function () {
          Array.prototype.forEach.call(buttons, function (each) {
            each.disabled = false;
          });
          button.removeAttribute("aria-busy");
          button.textContent = label;
        });
      });
    });
  }

  function initCopy(root) {
    Array.prototype.forEach.call(root.querySelectorAll("[data-wajub-copy]"), function (button) {
      var field = root.querySelector(button.getAttribute("data-wajub-copy"));
      // The status line of the button's own field: the webhook's address,
      // the scheduled task's.
      var status = (button.closest(".form-group") || root).querySelector("[data-wajub-copy-status]");
      if (!field) {
        return;
      }
      button.addEventListener("click", function () {
        function said() {
          if (status) {
            status.textContent = t("copied", "Copied.");
          }
        }
        field.select();
        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
          window.navigator.clipboard.writeText(field.value).then(said, function () {});
        } else if (typeof document.execCommand === "function" && document.execCommand("copy")) {
          // A page served over plain http has no clipboard API.
          said();
        }
      });
    });
  }

  function init() {
    var root = document.querySelector("[data-wajub-admin]");
    if (!root) {
      return;
    }
    initTabs(root);
    initConnection(root);
    initLiveConfirmation(root);
    initConfirms(root);
    initWebhooks(root);
    initCopy(root);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window, document);

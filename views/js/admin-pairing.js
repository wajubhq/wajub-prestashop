/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * "Connect with Wajub" (Pairing\Pairing): opens the Wajub Dashboard on the
 * merchant's click, shows the pairing code, and asks the shop until the
 * merchant has approved or refused. No dependency. Words, the page's token
 * and the AJAX address come from PHP in `wajubPairing`; the key and the
 * polling token stay on the server and never reach this page.
 */
(function (window, document) {
  "use strict";

  var config = window.wajubPairing || {};
  var i18n = config.i18n || {};

  /**
   * Answers that end a session: polling stops, and the message takes the
   * focus. "replaced" ends this page's only: another tab started a new one.
   */
  var TERMINAL = ["authorized", "denied", "expired", "consumed", "replaced", "error"];

  /** Requests to the shop that fail in a row (offline, a PHP error) before the page gives up. */
  var MAX_FAILURES = 10;

  /** The shop asks the Dashboard every 3 seconds at most: the page asks the shop no faster. */
  var MIN_INTERVAL = 3;

  /** And at least every 30 seconds, as the shop does: a request lasts 15 minutes. */
  var MAX_INTERVAL = 30;

  /** The countdown changes once a minute, so its live region is not read every second. */
  var COUNTDOWN_STEP = 60000;

  /** The name of the window the Dashboard opens in, reused by a second click. */
  var WINDOW_NAME = "wajub-connect";

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  function format(text, name, value) {
    return String(text).split("%" + name + "%").join(String(value));
  }

  /** Only an https:// address is ever opened or linked: the shop checked it, the page checks again. */
  function isHttps(url) {
    return /^https:\/\//i.test(String(url || ""));
  }

  function isHidden() {
    return document.visibilityState === "hidden" || document.hidden === true;
  }

  function setHidden(element, hidden) {
    if (element) {
      element.hidden = hidden;
    }
  }

  /**
   * POSTs one action to the module's page; resolves with `data` of a
   * success, rejects with the shop's message, whatever the HTTP status, or
   * `requestFailed` when no JSON came back (offline, a PHP error, an expired
   * session, an error page a proxy put in place of the answer).
   */
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

  /**
   * Sends the window opened on the click to the Dashboard. It first loses
   * its link back to this page (`opener`), so the Dashboard, or anything it
   * navigates to, cannot reach into the back office. False when the browser
   * blocked the window or it was closed: the link on the page remains.
   */
  function navigate(win, url) {
    if (!win || win.closed || !isHttps(url)) {
      return false;
    }
    try {
      win.opener = null;
    } catch (error) {
      // A second Connect gets back the tab of the first (same name), which
      // is on the Dashboard by now: the browser refuses to change `opener`
      // there. One that still leads here is never sent.
      if (linksBack(win)) {
        return false;
      }
    }
    try {
      win.location.replace(url);
      return true;
    } catch (error) {
      return false;
    }
  }

  /** Whether the window can still reach this page through `opener`. */
  function linksBack(win) {
    try {
      return win.opener === window;
    } catch (error) {
      return true;
    }
  }

  function closeWindow(win) {
    try {
      if (win && !win.closed) {
        win.close();
      }
    } catch (error) {
      // Nothing to do: the merchant closes it.
    }
  }

  /** One "Connect with Wajub" panel, and the session it waits on. */
  function Panel(root) {
    var idle = root.querySelector("[data-wajub-pairing-idle]");
    var pendingBox = root.querySelector("[data-wajub-pairing-pending]");
    var title = root.querySelector("[data-wajub-pairing-title]");
    var codeElement = root.querySelector("[data-wajub-pairing-code]");
    var instructions = root.querySelector("[data-wajub-pairing-instructions]");
    var blocked = root.querySelector("[data-wajub-pairing-blocked]");
    var link = root.querySelector("[data-wajub-pairing-link]");
    var cancel = root.querySelector("[data-wajub-pairing-cancel]");
    var countdown = root.querySelector("[data-wajub-pairing-countdown]");
    var result = root.querySelector("[data-wajub-pairing-result]");
    var live = root.querySelector("[data-wajub-pairing-live]");
    var starts = root.querySelectorAll("[data-wajub-pairing-start]");

    var state = {
      active: false,
      starting: false,
      inFlight: false,
      failures: 0,
      interval: MIN_INTERVAL,
      deadline: 0,
      code: "",
      pollTimer: null,
      countdownTimer: null,
    };

    function announce(message) {
      if (!live) {
        return;
      }
      // Emptied first, so the same words twice are read twice.
      live.textContent = "";
      window.setTimeout(function () {
        live.textContent = message;
      }, 60);
    }

    function clearTimers() {
      if (state.pollTimer !== null) {
        window.clearTimeout(state.pollTimer);
        state.pollTimer = null;
      }
      if (state.countdownTimer !== null) {
        window.clearTimeout(state.countdownTimer);
        state.countdownTimer = null;
      }
    }

    function stop() {
      state.active = false;
      clearTimers();
    }

    // --- Polling -----------------------------------------------------------

    function schedule() {
      if (state.pollTimer !== null) {
        window.clearTimeout(state.pollTimer);
      }
      state.pollTimer = window.setTimeout(function () {
        state.pollTimer = null;
        // A hidden page does not ask: it asks again as soon as it is shown.
        if (!isHidden()) {
          poll();
        }
      }, state.interval * 1000);
    }

    function poll() {
      if (!state.active || state.inFlight) {
        return;
      }
      state.inFlight = true;
      // The code this page shows: the shop says when another tab replaced it.
      post("pairing_poll", { code: state.code }).then(
        function (data) {
          state.inFlight = false;
          state.failures = 0;
          if (!state.active) {
            return;
          }
          if (TERMINAL.indexOf(data.status) !== -1) {
            finish(data);
            return;
          }
          schedule();
        },
        function (error) {
          state.inFlight = false;
          if (!state.active) {
            return;
          }
          state.failures++;
          if (state.failures >= MAX_FAILURES) {
            finish({ status: "error", message: error.message });
            return;
          }
          schedule();
        }
      );
    }

    /** The page is shown again, or its window has the focus back: the merchant may just have approved. */
    function wake() {
      if (state.active && !isHidden()) {
        poll();
      }
    }

    // --- Countdown ---------------------------------------------------------

    function tick() {
      state.countdownTimer = null;
      if (!state.active || !countdown) {
        return;
      }
      var left = state.deadline - Date.now();
      countdown.textContent = format(t("countdown", "This request expires in %minutes% min."), "minutes", Math.max(1, Math.ceil(left / 60000)));
      if (left > COUNTDOWN_STEP) {
        state.countdownTimer = window.setTimeout(tick, COUNTDOWN_STEP);
      }
    }

    // --- States ------------------------------------------------------------

    /**
     * `started` is true after a click: the focus then moves to the pending
     * state and the code is announced. A session resumed on load leaves the
     * focus where the page put it.
     */
    function showPending(session, windowBlocked, started) {
      var code = String(session.code || "");
      setHidden(idle, true);
      setHidden(result, true);
      setHidden(pendingBox, false);
      if (codeElement) {
        codeElement.textContent = code;
      }
      if (instructions) {
        instructions.textContent = format(t("instructions", "In the Wajub tab, check that the code is %code%, then click Authorize."), "code", code);
      }
      // The link is always there: the window may have been blocked, closed,
      // or opened behind this one.
      if (link && isHttps(session.confirm_url)) {
        link.setAttribute("href", session.confirm_url);
      }
      setHidden(blocked, !windowBlocked);

      state.interval = Math.min(MAX_INTERVAL, Math.max(MIN_INTERVAL, parseInt(session.interval, 10) || MIN_INTERVAL));
      state.deadline = Date.now() + Math.max(0, parseInt(session.expires_in, 10) || 0) * 1000;
      state.code = code;
      state.failures = 0;
      state.active = true;
      clearTimers();
      tick();
      if (started) {
        if (title) {
          title.focus();
        }
        announce(format(t("code", "Code %code%"), "code", code));
      }
    }

    /** What the page shows of the keys was read before: reloaded, it shows the new one. */
    function reloadButton() {
      var wrap = document.createElement("p");
      var reload = document.createElement("button");
      reload.type = "button";
      reload.className = "btn btn-default";
      reload.textContent = t("reload", "Reload this page");
      reload.addEventListener("click", function () {
        window.location.reload();
      });
      wrap.appendChild(reload);
      return wrap;
    }

    function showResult(tone, lines, action) {
      if (!result) {
        return;
      }
      result.textContent = "";
      result.className = "wajub-pairing-result alert alert-" + tone;
      result.setAttribute("role", tone === "danger" ? "alert" : "status");
      lines.forEach(function (line) {
        var paragraph = document.createElement("p");
        paragraph.textContent = line;
        result.appendChild(paragraph);
      });
      if (action) {
        result.appendChild(action);
      }
      result.hidden = false;
      result.focus();
    }

    var FALLBACKS = {
      denied: ["denied", "The request was refused in Wajub."],
      expired: ["expired", "The request expired. Start again."],
      consumed: ["consumed", "This request was already used."],
      replaced: ["replaced", "You started a new request in another tab or window, so this code no longer works."],
      error: ["unreachable", "Wajub could not be reached."],
    };

    function finish(data) {
      stop();
      setHidden(pendingBox, true);
      var authorized = data.status === "authorized";
      var fallback = FALLBACKS[data.status] || FALLBACKS.error;
      var lines = [data.message || (authorized ? "" : t(fallback[0], fallback[1]))].filter(Boolean);
      var warnings = authorized && Array.isArray(data.warnings) ? data.warnings : [];
      if (authorized && data.webhook && data.webhook.message) {
        lines.push(data.webhook.message);
      }
      lines = lines.concat(warnings);

      if (authorized) {
        // The buttons stay hidden, shown again by a Cancel that came too late.
        setHidden(idle, true);
        showResult(warnings.length ? "warning" : "success", lines, reloadButton());
        return;
      }
      // Start again, or paste keys: the buttons come back.
      setHidden(idle, false);
      showResult("danger", lines, null);
    }

    // --- Actions -----------------------------------------------------------

    function start(button) {
      if (state.starting || state.active) {
        return;
      }
      // Opened in the click itself: a window opened once the shop has
      // answered is no longer the merchant's doing, and browsers block it.
      var win = null;
      try {
        win = window.open("", WINDOW_NAME);
      } catch (error) {
        win = null;
      }

      state.starting = true;
      var label = button.textContent;
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
      button.textContent = t("starting", "Starting…");
      setHidden(result, true);

      function done() {
        state.starting = false;
        button.disabled = false;
        button.removeAttribute("aria-busy");
        button.textContent = label;
      }

      post("pairing_start", { environment: button.getAttribute("data-wajub-pairing-start") || "" }).then(
        function (session) {
          done();
          var opened = navigate(win, session.confirm_url);
          if (!opened) {
            closeWindow(win);
          }
          showPending(session, !opened, true);
          // The first question after one interval: nobody approves faster.
          schedule();
        },
        function (error) {
          done();
          closeWindow(win);
          showResult("danger", [error.message], null);
        }
      );
    }

    Array.prototype.forEach.call(starts, function (button) {
      button.addEventListener("click", function (event) {
        if (event && event.preventDefault) {
          event.preventDefault();
        }
        start(button);
      });
    });

    if (cancel) {
      cancel.addEventListener("click", function () {
        stop();
        // The Dashboard has no cancel: the shop forgets the session, which
        // expires on its own. With the code this page shows: a session
        // another tab started since is not this page's to forget.
        post("pairing_cancel", { code: state.code }).then(
          function (data) {
            // That tab's session is left to it, or the key is saved already:
            // said here, unless the merchant has started again meanwhile.
            if ((data.status === "replaced" || data.status === "authorized") && !state.active && !state.starting) {
              finish(data);
            }
          },
          function () {}
        );
        setHidden(pendingBox, true);
        setHidden(idle, false);
        announce(t("cancelled", "Connection cancelled."));
        if (starts.length) {
          starts[0].focus();
        }
      });
    }

    return {
      wake: wake,
      /** A session the shop still waits on, from before the page loaded: asked about at once, if the page is shown. */
      resume: function (session) {
        showPending(session, false, false);
        wake();
      },
    };
  }

  function init() {
    var roots = document.querySelectorAll("[data-wajub-pairing]");
    var panels = Array.prototype.map.call(roots, Panel);
    if (!panels.length) {
      return;
    }

    if (config.pending && config.pending.code) {
      panels[0].resume(config.pending);
    }

    function wakeAll() {
      panels.forEach(function (panel) {
        panel.wake();
      });
    }
    document.addEventListener("visibilitychange", wakeAll);
    window.addEventListener("focus", wakeAll);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window, document);

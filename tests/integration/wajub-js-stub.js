/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * A stand-in for Wajub.js 1.7.2's payment components, served by
 * api-stub.py at /wajub.js, which a local shop in debug mode loads instead
 * of https://js.wajub.com when its configuration defines _WAJUB_JS_URL_.
 * It draws a small form in a frame of its own, says it is up and complete,
 * and, once submitted, waits for the test to say how the payment ends:
 *
 *     window.__wajubStub.last().succeed()
 *     window.__wajubStub.last().decline()
 *     window.__wajubStub.last().cancel()
 *     window.__wajubStub.last().state("APPROVAL_REQUIRED")
 *
 * The same events and methods fields-core.js uses (ready, change,
 * statechange, success, error, resize; mount, submit, isComplete, focus,
 * destroy), nothing more. Never shipped: tests/ stays out of the zip.
 */
(function () {
  "use strict";

  var created = [];

  function Component(sessionId, type, cfg) {
    this.sessionId = sessionId;
    this.type = type;
    this.cfg = cfg || {};
    this.listeners = {};
    this.frame = null;
    this.complete = false;
    this.submits = 0;
  }

  Component.prototype.on = function (event, fn) {
    (this.listeners[event] = this.listeners[event] || []).push(fn);
    return this;
  };

  Component.prototype.off = function (event, fn) {
    this.listeners[event] = (this.listeners[event] || []).filter(function (f) {
      return f !== fn;
    });
    return this;
  };

  Component.prototype.emit = function (event, data) {
    (this.listeners[event] || []).slice().forEach(function (fn) {
      fn(data);
    });
  };

  Component.prototype.mount = function (container) {
    var self = this;
    if (this.frame && this.frame.parentNode) {
      this.frame.parentNode.removeChild(this.frame);
    }
    var frame = document.createElement("iframe");
    frame.setAttribute("title", this.cfg.frameTitle || "Wajub " + this.type + " component");
    frame.style.width = "100%";
    frame.style.border = "0";
    frame.style.display = "block";
    var label = this.type === "card" ? "Card number" : "Mobile Money number";
    frame.srcdoc =
      '<body style="margin:2px;font:15px sans-serif"><label style="display:block;margin-bottom:4px">' +
      label +
      '</label><input style="box-sizing:border-box;width:100%;height:44px" value="' +
      (this.type === "card" ? "4242 4242 4242 4242" : "677 12 34 56") +
      '"><p style="font-size:12px">Wajub.js stand-in · session ' +
      this.sessionId +
      "</p></body>";
    container.appendChild(frame);
    this.frame = frame;
    setTimeout(function () {
      frame.style.height = self.type === "card" ? "252px" : "108px";
      self.emit("resize", { height: self.type === "card" ? 252 : 108 });
      self.complete = true;
      if (self.cfg.onReady) self.cfg.onReady(self);
      self.emit("ready", self);
      var change = { complete: true, empty: false };
      if (self.cfg.onChange) self.cfg.onChange(change);
      self.emit("change", change);
    }, 300);
    return this;
  };

  Component.prototype.submit = function () {
    var self = this;
    this.submits++;
    Promise.resolve(this.cfg.onBeforeSubmit ? this.cfg.onBeforeSubmit() : true).then(function (allowed) {
      if (allowed === false) {
        self.fail({ code: "checkout_validation", message: "Check your order details before paying." });
        return;
      }
      self.state("APPROVAL_REQUIRED");
    });
    return this;
  };

  Component.prototype.state = function (state) {
    this.emit("statechange", { state: state });
  };

  Component.prototype.succeed = function () {
    var transaction = { id: "stub", status: "succeeded" };
    if (this.cfg.onSuccess) this.cfg.onSuccess(transaction);
    this.emit("success", transaction);
  };

  Component.prototype.fail = function (error) {
    if (this.cfg.onError) this.cfg.onError(error);
    this.emit("error", error);
  };

  Component.prototype.decline = function () {
    var error = { code: "payment_failed", message: "Payment failed" };
    var change = { complete: false, empty: false, error: error };
    if (this.cfg.onChange) this.cfg.onChange(change);
    this.emit("change", change);
    this.fail(error);
  };

  Component.prototype.cancel = function () {
    this.fail({ code: "payment_cancelled", message: "Payment cancelled" });
  };

  Component.prototype.isComplete = function () {
    return this.complete;
  };

  Component.prototype.focus = function () {
    return this;
  };

  Component.prototype.destroy = function () {
    if (this.frame && this.frame.parentNode) {
      this.frame.parentNode.removeChild(this.frame);
    }
    this.listeners = {};
  };

  window.wajub = {
    version: "1.7.2",
    components: function (sessionId, base) {
      return {
        create: function (type, cfg) {
          var merged = {};
          var key;
          for (key in base || {}) merged[key] = base[key];
          for (key in cfg || {}) merged[key] = cfg[key];
          var component = new Component(sessionId, type, merged);
          created.push(component);
          return component;
        },
      };
    },
  };

  window.__wajubStub = {
    created: created,
    last: function () {
      return created[created.length - 1];
    },
  };
})();

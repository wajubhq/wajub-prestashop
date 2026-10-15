/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Stand-ins for what the payment form's scripts run against, for
 * tests/run-fields-js-tests.js: a small DOM with the selectors the scripts
 * use, Wajub.js's payment components (which record what they are given and
 * emit what the test says), fetch answered by a fake shop, and timers the
 * test runs by hand. A port of the WordPress plugin's tests/fakes/fields-dom.js.
 *
 * A Node module, never shipped (tests/ is left out of the zip).
 */
"use strict";

// --- A small DOM -----------------------------------------------------------------

class ClassList {
  constructor(node) {
    this.node = node;
  }
  list() {
    return (this.node.getAttribute("class") || "").split(/\s+/).filter(Boolean);
  }
  contains(name) {
    return this.list().includes(name);
  }
  add(...names) {
    const list = this.list();
    names.forEach((n) => list.includes(n) || list.push(n));
    this.node.setAttribute("class", list.join(" "));
  }
  remove(...names) {
    this.node.setAttribute("class", this.list().filter((n) => !names.includes(n)).join(" "));
  }
  toggle(name, force) {
    const on = force === undefined ? !this.contains(name) : !!force;
    if (on) this.add(name);
    else this.remove(name);
    return on;
  }
}

/** An element's inline style: plain properties, and custom properties through setProperty(). */
class FakeStyle {
  constructor() {
    this.custom = {};
  }
  setProperty(name, value) {
    this.custom[name] = String(value);
  }
  getPropertyValue(name) {
    return name in this.custom ? this.custom[name] : "";
  }
  removeProperty(name) {
    delete this.custom[name];
  }
}

const COMPOUND = /([a-zA-Z][\w-]*)|#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\]]*)))?\]|:(checked|disabled)/g;

function matchCompound(node, compound) {
  if (!node || node.nodeType !== 1) return false;
  COMPOUND.lastIndex = 0;
  let m;
  let any = false;
  while ((m = COMPOUND.exec(compound))) {
    any = true;
    if (m[1] && node.tagName !== m[1].toUpperCase()) return false;
    if (m[2] && node.id !== m[2]) return false;
    if (m[3] && !node.classList.contains(m[3])) return false;
    if (m[4]) {
      if (!node.hasAttribute(m[4])) return false;
      const wanted = m[5] !== undefined ? m[5] : m[6] !== undefined ? m[6] : m[7];
      if (wanted !== undefined && String(node.getAttribute(m[4])) !== wanted) return false;
    }
    if (m[8] === "checked" && !node.checked) return false;
    if (m[8] === "disabled" && !node.disabled) return false;
  }
  return any;
}

/** One selector of a list: compounds separated by spaces (descendant combinators). */
function matchSelector(node, selector) {
  const compounds = selector.trim().split(/\s+/);
  if (!matchCompound(node, compounds[compounds.length - 1])) return false;
  let ancestor = node.parentNode;
  for (let i = compounds.length - 2; i >= 0; i--) {
    while (ancestor && !matchCompound(ancestor, compounds[i])) ancestor = ancestor.parentNode;
    if (!ancestor) return false;
    ancestor = ancestor.parentNode;
  }
  return true;
}

class FakeNode {
  constructor(tag, doc) {
    this.tagName = tag.toUpperCase();
    this.nodeType = 1;
    this.ownerDocument = doc;
    this.childNodes = [];
    this.parentNode = null;
    this.attributes = {};
    this.classList = new ClassList(this);
    this.listeners = {};
    this.disabled = false;
    this.checked = false;
    this.value = "";
    this.style = new FakeStyle();
    this._text = "";
    this.focusCount = 0;
  }
  get children() {
    return this.childNodes.filter((n) => n.nodeType === 1);
  }
  get firstChild() {
    return this.childNodes[0] || null;
  }
  get id() {
    return this.attributes.id || "";
  }
  set id(v) {
    this.attributes.id = String(v);
  }
  get className() {
    return this.attributes.class || "";
  }
  set className(v) {
    this.attributes.class = String(v);
  }
  get hidden() {
    return "hidden" in this.attributes;
  }
  set hidden(v) {
    if (v) this.attributes.hidden = "";
    else delete this.attributes.hidden;
  }
  get name() {
    return this.attributes.name || "";
  }
  get type() {
    return this.attributes.type || "";
  }
  set type(v) {
    this.attributes.type = String(v);
  }
  get href() {
    return this.attributes.href || "";
  }
  get title() {
    return this.attributes.title || "";
  }
  get textContent() {
    return this.childNodes.length ? this.childNodes.map((c) => c.textContent).join("") : this._text;
  }
  set textContent(v) {
    this.childNodes.forEach((c) => (c.parentNode = null));
    this.childNodes = [];
    this._text = String(v);
  }
  setAttribute(n, v) {
    this.attributes[n] = String(v);
    if (n === "checked") this.checked = true;
    if (n === "value") this.value = String(v);
  }
  getAttribute(n) {
    return n in this.attributes ? this.attributes[n] : null;
  }
  hasAttribute(n) {
    return n in this.attributes;
  }
  removeAttribute(n) {
    delete this.attributes[n];
  }
  appendChild(child) {
    if (child.parentNode) child.parentNode.removeChild(child);
    child.parentNode = this;
    this.childNodes.push(child);
    this.mutated({ addedNodes: [child], removedNodes: [] });
    return child;
  }
  insertBefore(child, ref) {
    if (!ref) return this.appendChild(child);
    if (child.parentNode) child.parentNode.removeChild(child);
    child.parentNode = this;
    this.childNodes.splice(this.childNodes.indexOf(ref), 0, child);
    this.mutated({ addedNodes: [child], removedNodes: [] });
    return child;
  }
  removeChild(child) {
    const i = this.childNodes.indexOf(child);
    if (i >= 0) this.childNodes.splice(i, 1);
    child.parentNode = null;
    this.mutated({ addedNodes: [], removedNodes: [child] });
    return child;
  }
  /** A change of this node's children, for the document's MutationObservers (on the page only). */
  mutated(record) {
    const doc = this.ownerDocument;
    if (doc && doc.observers && doc.observers.length && doc.body && doc.body.contains(this)) {
      doc.observers.forEach((observer) => observer.record(Object.assign({ target: this }, record)));
    }
  }
  /** As jQuery's replaceWith(): the new node takes this one's place. */
  replaceWith(node) {
    const parent = this.parentNode;
    parent.insertBefore(node, this);
    parent.removeChild(this);
  }
  contains(node) {
    for (let n = node; n; n = n.parentNode) {
      if (n === this) return true;
    }
    return false;
  }
  addEventListener(type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  }
  dispatch(type) {
    (this.listeners[type] || []).slice().forEach((fn) => fn({ type, target: this, currentTarget: this, preventDefault() {} }));
  }
  toggleAttribute(n, on) {
    if (on) this.setAttribute(n, "");
    else this.removeAttribute(n);
  }
  click() {
    this.clicks = (this.clicks || 0) + 1;
    if (!this.disabled) this.dispatch("click");
  }
  focus() {
    this.focusCount++;
    this.ownerDocument.activeElement = this;
  }
  scrollIntoView(options) {
    this.scrolledWith = options;
  }
  matches(selector) {
    return selector.split(",").some((s) => matchSelector(this, s));
  }
  closest(selector) {
    for (let node = this; node && node.nodeType === 1; node = node.parentNode) {
      if (node.matches(selector)) return node;
    }
    return null;
  }
  querySelectorAll(selector) {
    const found = [];
    const walk = (node) => {
      node.children.forEach((child) => {
        if (child.matches(selector)) found.push(child);
        walk(child);
      });
    };
    walk(this);
    return found;
  }
  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
  }
}

function makeDocument() {
  const doc = {
    activeElement: null,
    readyState: "complete",
    listeners: {},
    createElement: (tag) => new FakeNode(tag, doc),
    createTextNode: (text) => ({ nodeType: 3, textContent: String(text), parentNode: null }),
    getElementById: (id) => doc.body.querySelector("#" + id),
    querySelector: (s) => doc.body.querySelector(s),
    querySelectorAll: (s) => doc.body.querySelectorAll(s),
    contains: (node) => doc.body.contains(node),
    addEventListener(type, fn, capture) {
      (doc.listeners[type] = doc.listeners[type] || []).push({ fn, capture: !!capture });
    },
    removeEventListener(type, fn) {
      doc.listeners[type] = (doc.listeners[type] || []).filter((l) => l.fn !== fn);
    },
    /**
     * An event reaching the document: its capturing listeners first, then,
     * unless one stopped it, `atTarget` (the page's own handlers on the way)
     * and its bubbling listeners. Returns the event, which says whether its
     * default was prevented.
     */
    dispatch(type, target, atTarget) {
      const event = {
        type,
        target: target || doc.activeElement,
        defaultPrevented: false,
        stopped: false,
        preventDefault() {
          this.defaultPrevented = true;
        },
        stopImmediatePropagation() {
          this.stopped = true;
        },
        stopPropagation() {
          this.stopped = true;
        },
      };
      const run = (capture) =>
        (doc.listeners[type] || [])
          .filter((l) => l.capture === capture)
          .slice()
          .forEach((l) => {
            if (!event.stopped) l.fn(event);
          });
      run(true);
      if (!event.stopped && atTarget) atTarget(event);
      if (!event.stopped) run(false);
      return event;
    },
  };
  doc.body = new FakeNode("body", doc);
  doc.observers = [];
  /** As a browser's: records delivered together, once the current task is done. */
  doc.MutationObserver = class {
    constructor(callback) {
      this.callback = callback;
      this.records = [];
    }
    observe() {
      doc.observers.push(this);
    }
    disconnect() {
      doc.observers = doc.observers.filter((o) => o !== this);
    }
    record(record) {
      this.records.push(record);
      if (this.records.length === 1) {
        Promise.resolve().then(() => {
          const records = this.records;
          this.records = [];
          this.callback(records, this);
        });
      }
    }
  };
  return doc;
}

/** Builds `<tag attrs>children</tag>` from [tag, attrs, ...children]. */
function build(doc, spec) {
  const [tag, attrs, ...children] = spec;
  const node = doc.createElement(tag);
  Object.keys(attrs || {}).forEach((k) => node.setAttribute(k, attrs[k]));
  children.forEach((c) => node.appendChild(typeof c === "string" ? doc.createTextNode(c) : build(doc, c)));
  return node;
}

// --- color-mix(), as fields-core.js converts a colour through it ---------------------

/**
 * Lets `doc` convert colours as a browser computes `color-mix(in srgb, X, X)`
 * for fields-core.js (convertColor()): a <span> whose inline colour takes
 * such a mix only of a colour of `known` (CSS text => the `color(srgb …)` a
 * browser computes the mix to), as a browser refuses a colour it does not
 * know, and none at all with `refuse` (a browser without color-mix()). Its
 * computed colour is the mix's only while it is on the page, as
 * getComputedStyle() gives nothing for a node off it. Returns what was
 * made: `probes` (each <span>), `important` (whether every colour set on
 * one was), and `canvases` (each <canvas>).
 */
function mixColors(doc, known, refuse) {
  const seen = { probes: [], important: true, canvases: 0 };
  const MIX = /^color-mix\(in srgb, (.+), \1\)$/;
  const make = doc.createElement;
  doc.createElement = (tag) => {
    if (tag === "canvas") seen.canvases++;
    const node = make(tag);
    if (tag !== "span") return node;
    seen.probes.push(node);
    const style = node.style;
    const set = style.setProperty.bind(style);
    style.setProperty = (name, value, priority) => {
      if (name !== "color") return set(name, value);
      if (priority !== "important") seen.important = false;
      const mix = MIX.exec(String(value));
      if (!refuse && mix && mix[1] in known) set(name, value);
    };
    Object.defineProperty(node, "computed", {
      get() {
        const mix = MIX.exec(style.getPropertyValue("color"));
        return mix && node.parentNode ? { color: known[mix[1]] } : {};
      },
    });
    return node;
  };
  return seen;
}

// --- Timers the test runs by hand --------------------------------------------------

function makeTimers() {
  let next = 1;
  const list = [];
  return {
    list,
    setTimeout(fn, ms) {
      const id = next++;
      list.push({ id, fn, ms: ms || 0 });
      return id;
    },
    clearTimeout(id) {
      const i = list.findIndex((t) => t.id === id);
      if (i >= 0) list.splice(i, 1);
    },
    /** Runs the timers `predicate` picks, as they are now; returns how many ran. */
    run(predicate) {
      const due = list.filter(predicate);
      due.forEach((t) => {
        const i = list.indexOf(t);
        if (i >= 0) {
          list.splice(i, 1);
          t.fn();
        }
      });
      return due.length;
    },
    /** follow()'s next look at the order: its timer runs the function named poll. */
    poll() {
      return this.run((t) => t.fn && t.fn.name === "poll");
    },
    pending(ms) {
      return list.filter((t) => t.ms === ms).length;
    },
  };
}

// --- Wajub.js's payment components --------------------------------------------------

const VALID_TYPES = ["card", "mobileMoney", "wallet", "bankTransfer", "crypto", "payment", "address"];

/**
 * The steps the frame never reports (checkout.wajub ComponentHostBridge.tsx,
 * TERMINAL_STATES): their outcome is a success, an error, or a change that
 * carries one.
 */
const TERMINAL_STATES = ["SUCCESS", "FAILED", "CANCELLED", "EXPIRED"];

/**
 * A component as Wajub.js 1.6.0 builds one (sdk/src/components-core.js): the
 * options it is created with, its iframe, its listeners, and what its frame
 * would post, emitted when the test says so. As there, mount() keeps the
 * last step and completeness it heard: only the frame's ready and change
 * set completeness, and only state_change the step. From 1.7.0 on, as
 * there too, the frame is named by `frameTitle`, and a `redirect` takes the
 * page to the provider's (redirect()).
 */
class FakeComponent {
  constructor(doc, type, cfg, sdk) {
    this.doc = doc;
    this.type = type;
    this.cfg = cfg;
    this.sdk = sdk || null;
    this.listeners = {};
    this.iframe = null;
    this.container = null;
    this.mounts = 0;
    this.submits = 0;
    this.focuses = 0;
    this.complete = false;
    this.currentState = "INITIATED";
    this.destroyed = false;
  }
  /** Whether this is Wajub.js 1.7.0 or later. */
  recent() {
    const match = this.sdk && typeof this.sdk.version === "string" ? /^(\d+)\.(\d+)/.exec(this.sdk.version) : null;
    return !!match && (Number(match[1]) > 1 || (Number(match[1]) === 1 && Number(match[2]) >= 7));
  }
  mount(el) {
    if (this.iframe) this.unmount();
    const frame = this.doc.createElement("iframe");
    const named = this.recent() && typeof this.cfg.frameTitle === "string" ? this.cfg.frameTitle.trim() : "";
    frame.setAttribute("title", named || "Wajub " + this.type + " component");
    frame.style = { cssText: "width:100%;border:0;min-height:120px;display:block;" };
    el.appendChild(frame);
    this.iframe = frame;
    this.container = el;
    this.mounts++;
    return this;
  }
  unmount() {
    if (this.container) {
      (this.listeners.error || []).slice().forEach((fn) => fn({ code: "component_unmounted", message: "Payment component unmounted" }));
    }
    if (this.iframe && this.iframe.parentNode) this.iframe.parentNode.removeChild(this.iframe);
    this.iframe = null;
    this.container = null;
  }
  destroy() {
    this.unmount();
    this.listeners = {};
    this.destroyed = true;
  }
  on(event, fn) {
    (this.listeners[event] = this.listeners[event] || []).push(fn);
    return this;
  }
  off(event, fn) {
    this.listeners[event] = (this.listeners[event] || []).filter((f) => f !== fn);
    return this;
  }
  submit() {
    if (this.iframe) this.submits++;
    return this;
  }
  focus() {
    this.focuses++;
    return this;
  }
  isComplete() {
    return this.complete;
  }
  getState() {
    return this.currentState;
  }
  listening(event) {
    return (this.listeners[event] || []).length;
  }
  /** The address Wajub.js would load, as far as these options go. */
  params() {
    const cfg = this.cfg;
    const p = [];
    if (cfg.locale) p.push("locale=" + cfg.locale);
    if (cfg.onBeforeSubmit) p.push("before_submit=1");
    if (cfg.fields && cfg.fields.customer === "external") p.push("fields_customer=external");
    if (cfg.fields && typeof cfg.fields.country === "string" && /^[A-Za-z]{2}$/.test(cfg.fields.country)) p.push("fields_country=" + cfg.fields.country.toUpperCase());
    if (cfg.fields && cfg.fields.operator === "detect") p.push("fields_operator=detect");
    if (cfg.variant === "fields") p.push("variant=fields");
    return p;
  }
  /** The frame says how tall it is: Wajub.js sets the frame's height, then tells the page. */
  resize(height) {
    if (this.iframe) this.iframe.style.height = height + "px";
    this.emit("resize", { height });
  }
  // What the frame posts, as the SDK hands it on.
  emit(event, data) {
    (this.listeners[event] || []).slice().forEach((fn) => fn(data));
  }
  /** The frame is up, as complete as its form (a fresh frame's is not). */
  ready(complete) {
    this.complete = !!complete;
    if (this.cfg.onReady) this.cfg.onReady(this);
    this.emit("ready", this);
  }
  change(msg) {
    this.complete = !!msg.complete;
    if (this.cfg.onChange) this.cfg.onChange(msg);
    this.emit("change", msg);
  }
  loadError(error) {
    if (this.cfg.onLoadError) this.cfg.onLoadError(error);
    this.emit("loaderror", error);
  }
  succeed(transaction) {
    if (this.cfg.onSuccess) this.cfg.onSuccess(transaction);
    this.emit("success", transaction);
  }
  fail(error) {
    if (this.cfg.onError) this.cfg.onError(error);
    this.emit("error", error);
  }
  cancel() {
    this.fail({ code: "payment_cancelled", message: "Payment cancelled" });
  }
  expire() {
    this.fail({ code: "session_expired", message: "Payment session expired" });
  }
  state(state) {
    if (TERMINAL_STATES.includes(state)) {
      throw new Error("The frame sends no state_change for " + state + ": use succeed(), decline(), cancel() or expire()");
    }
    this.currentState = state;
    this.emit("statechange", { state, method: undefined });
  }
  /**
   * The attempt declined, as the frame reports it when it shows its failure
   * screen (ComponentCheckoutView.tsx, then useEmbedBridge.ts): a change that
   * carries the error, then the error. No step.
   */
  decline(error) {
    const e = error || { code: "payment_failed", message: "Payment failed" };
    this.change({ complete: false, empty: false, error: e });
    this.fail(e);
  }
  /**
   * The payment continues on the provider's own page (Wajub.js 1.7.0): the
   * page hears `redirect`, then Wajub.js takes it there (`sdk.navigate`, the
   * page's location.assign).
   */
  redirect(url, method) {
    try {
      this.emit("redirect", { url, method: method || "GET" });
    } finally {
      if (this.sdk && typeof this.sdk.navigate === "function") this.sdk.navigate(url);
    }
  }
  /** Enter pressed in the frame: it asks the page before it submits (submit_request). */
  askToSubmit() {
    return Promise.resolve()
      .then(() => (this.cfg.onBeforeSubmit ? this.cfg.onBeforeSubmit() : true))
      .then(
        (allowed) => allowed !== false,
        () => false
      );
  }
  /**
   * Enter pressed in a valid Mobile Money form (ComponentCheckoutView.tsx
   * onPay): the frame reports itself incomplete as it submits, asks the page
   * (componentSubmission.ts posts the question at once), and, re-rendered
   * while it waits (formComplete turns false), reports itself incomplete
   * again in the same task: that change reaches the page before anything
   * the page put off until after its answer. Resolves with the answer. A
   * refusal is reported later: refused().
   */
  pressEnter() {
    this.change({ complete: false, empty: false });
    return this.askToSubmit().then((allowed) => {
      this.change({ complete: false, empty: true, error: null });
      return allowed;
    });
  }
  /**
   * The frame heard no to its question (onPay's catch, then
   * setSubmitting(false)): it reports the refusal, then that its form is
   * complete again.
   */
  refused() {
    this.fail({ code: "checkout_validation", message: "Check your order details before paying." });
    this.change({ complete: true, empty: false, error: null });
  }
}

function makeSdk(doc, version) {
  const sdk = {
    version: version === undefined ? "1.6.0" : version,
    created: [],
    /** Where Wajub.js takes the page for a component's `redirect`: the test sets it. */
    navigate: null,
    components(sessionId, base) {
      return {
        sessionId,
        create(type, cfg) {
          if (VALID_TYPES.indexOf(type) === -1) throw new Error("wajub.components.create: invalid type '" + type + "'");
          const merged = Object.assign({ sessionId }, base || {}, cfg || {});
          if (!merged.sessionId) throw new Error("wajub.components.create: sessionId is required");
          const component = new FakeComponent(doc, type, merged, sdk);
          sdk.created.push(component);
          return component;
        },
      };
    },
    confirmPayment() {
      throw new Error("confirmPayment is not used by the payment box");
    },
  };
  return sdk;
}

// --- The shop: its prepare, validation, release and status controllers ---------------

/**
 * fetch(), answered as the shop would: the prepare controller gives each
 * kind a new payment (`trx_<kind>_<n>`) unless the test queued another
 * answer; validation, release and status answer what the test queued, else
 * their default. A queued 'reject' fails the request; 'hang' never answers.
 */
function makeShop(urls) {
  const shop = {
    requests: [],
    queues: { prepare: [], validation: [], release: [], status: [] },
    defaults: {
      validation: { success: false, data: { code: "start_failed", message: "Your payment could not be started." } },
      release: { success: true, data: { to: "checkout", url: "https://shop.test/order", message: "Your cart is back." } },
      status: { success: true, data: { status: "pending", processing: false, redirect: "https://shop.test/order-confirmation?id_order=9" } },
    },
    count: 0,
  };
  const kindOf = (url) => Object.keys(urls).find((k) => urls[k] === url.split("?")[0]) || "";
  shop.of = (kind) => shop.requests.filter((r) => r.kind === kind);
  shop.fields = (request) => {
    const out = {};
    String(request.body || "")
      .split("&")
      .filter(Boolean)
      .forEach((pair) => {
        const [k, v] = pair.split("=");
        out[decodeURIComponent(k)] = decodeURIComponent((v || "").replace(/\+/g, " "));
      });
    return out;
  };
  shop.fetch = (url, init) => {
    const kind = kindOf(url);
    const request = { url, kind, method: (init && init.method) || "GET", body: (init && init.body) || "", headers: (init && init.headers) || {} };
    shop.requests.push(request);
    let answer;
    if (kind === "prepare" && !shop.queues.prepare.length) {
      shop.count++;
      const k = shop.fields(request).kind || "";
      answer = { success: true, data: { kind: k, paymentId: "trx_" + k + "_" + shop.count, sessionId: "tok_" + k + "_" + shop.count, currency: "XAF" } };
    } else if (kind && shop.queues[kind].length) {
      answer = shop.queues[kind].shift();
    } else {
      answer = shop.defaults[kind] || { success: false };
    }
    if (answer === "reject") return Promise.reject(new Error("offline"));
    if (answer === "hang") return new Promise(() => {});
    return Promise.resolve({ json: () => Promise.resolve(answer) });
  };
  return shop;
}

module.exports = {
  FakeNode,
  FakeStyle,
  makeDocument,
  build,
  mixColors,
  makeTimers,
  makeSdk,
  makeShop,
};

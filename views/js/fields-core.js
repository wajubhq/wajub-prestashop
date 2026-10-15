/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The payment form of the Mobile Money and Card options: window.WajubFields,
 * which the checkout's script (checkout-fields.js) runs. A port of the
 * WordPress plugin's fields-core.js, the same on both platforms but for the
 * store it asks (the module's prepare controller) and the checkout's look
 * it reads (PrestaShop's themes' inputs).
 *
 * The form is a Wajub.js payment component (`variant: "fields"`) mounted in
 * the option's own payment box, on a payment the shop prepared for the
 * payer's cart (controllers/front/prepare.php). That payment cannot be
 * charged until the shop has activated it for the order "Place order"
 * creates, so the page places the order first and only then submits the
 * form (run()).
 *
 * Wajub.js's confirmPayment() is not used: it gives up at the first declined
 * attempt, which the form lets the payer retry in place, and after a minute,
 * too soon for an approval on a phone. pay() submits the form and listens to
 * it instead, with no time limit, while follow() asks the shop how the order
 * stands, so a success the form never reports still lands.
 *
 * Every word comes from the page's configuration (Checkout\CheckoutStrings,
 * in PHP): this file holds none.
 */
(function () {
  "use strict";

  /** Wajub.js's components learned the fields variant (variant, fields.customer, fields.operator) in 1.5.0. */
  var MIN_VERSION = [1, 5, 0];

  /** How long the form may take to say it is up before the box falls back to Wajub's own page. */
  var READY_TIMEOUT = 15000;

  /** How long a submitted form may stay silent before the payer is asked to check it. */
  var QUIET_TIMEOUT = 15000;

  /**
   * The shop prepares the payment with Wajub, which it may ask twice: a
   * little more than both tries, after which the box falls back to Wajub's
   * page rather than load forever.
   */
  var PREPARE_TIMEOUT = 35000;

  /** One look at the order, when the payer is about to be sent somewhere (checkStatusOnce). */
  var STATUS_TIMEOUT = 5000;

  /** One of follow()'s looks: a slow answer only delays the next. */
  var FOLLOW_TIMEOUT = 10000;

  /**
   * follow() stops after about an hour: the payment expires before then, and
   * the order's confirmation page follows it for a payer who comes back.
   */
  var MAX_POLLS = 400;

  /**
   * The follow-up screens Wajub.js shows over its form (a code to enter, an
   * approval to wait for, 3-D Secure), marked on the box with `.is-action`.
   * The frame measures them and sizes itself (its `resize`): the box only
   * stops remembering the form's height meanwhile (checkout-fields.css).
   */
  var ACTION_STATES = [
    "PENDING",
    "OTP_REQUIRED",
    "PIN_REQUIRED",
    "PHONE_REQUIRED",
    "BIRTHDAY_REQUIRED",
    "ADDRESS_REQUIRED",
    "APPROVAL_REQUIRED",
    "USSD_REQUIRED",
    "VERIFYING",
    "3DS_REQUIRED",
  ];

  function has(object, key) {
    return Object.prototype.hasOwnProperty.call(object, key);
  }

  function after(ms, value) {
    return new Promise(function (resolve) {
      setTimeout(function () {
        resolve(value);
      }, ms);
    });
  }

  function errorCode(error) {
    if (!error || typeof error !== "object") {
      return "";
    }
    if (typeof error.code === "string") {
      return error.code;
    }
    return error.error && typeof error.error.code === "string" ? error.error.code : "";
  }

  function toggleClass(node, name, on) {
    if (!node || !node.classList) {
      return;
    }
    if (on) {
      node.classList.add(name);
    } else {
      node.classList.remove(name);
    }
  }

  function versionAtLeast(version, minimum) {
    var match = typeof version === "string" ? /^\s*v?(\d+)\.(\d+)(?:\.(\d+))?/.exec(version) : null;
    if (!match) {
      return false;
    }
    for (var i = 0; i < minimum.length; i++) {
      var part = parseInt(match[i + 1] || "0", 10);
      if (part !== minimum[i]) {
        return part > minimum[i];
      }
    }
    return true;
  }

  /** Whether Wajub.js is on the page, with payment components that know the fields variant. */
  function available() {
    var sdk = window.wajub;
    return !!sdk && typeof sdk.components === "function" && versionAtLeast(sdk.version, MIN_VERSION);
  }

  function failure(code, message) {
    return { error: { code: code, message: message || "" } };
  }

  // --- The site's own look ------------------------------------------------------
  //
  // The form takes the look of the checkout it sits in: the font, sizes and
  // colours of the checkout's own text inputs, their labels and its "Place
  // order" button, read from the page as the payer sees it, and handed to
  // Wajub.js as its appearance (AppearanceConfig, wajub-checkout.d.ts). Only
  // what can be read is handed over, as well-formed CSS: rgb() colours, px
  // sizes. Anything else is left to Wajub.js. A colour the browser gives in
  // another syntax (oklch() from a Tailwind v4 theme, lab(), color() in
  // another space) is read as the browser converts it to sRGB (readColor()).

  /**
   * Below this relative luminance a background is dark: white text reads
   * better on it than black (where both contrasts are equal, WCAG 2).
   */
  var DARK_BELOW = 0.179;

  /**
   * The button's or a link's colour becomes the form's own only if it stands
   * out from the box this much: the frame draws its focus ring in it, which
   * WCAG 2 (1.4.11) wants at 3:1.
   */
  var MIN_PRIMARY_CONTRAST = 3;

  /** Text the form draws on the box (labels, hints, the steps shown over the form): WCAG 2's AA. */
  var MIN_TEXT_CONTRAST = 4.5;

  /**
   * Text a site writes on a picture or a gradient reads on it at least at
   * WCAG 2's 3:1 (large text): text under that on white is written on a
   * dark backdrop, under that on a dark canvas on a light one. A mid grey
   * in between (#777, many a light theme's body text) tells neither
   * (buildAppearance()).
   */
  var MIN_INK_CONTRAST = 3;

  /**
   * On a dark form, the frame fills its buttons (the code to confirm, for
   * one) with the primary colour and labels them in its near-black
   * (neutral-950): the colour must let that label read (MIN_TEXT_CONTRAST).
   */
  var DARK_BUTTON_LABEL = { r: 10, g: 10, b: 10, a: 1 };

  /**
   * The rounding of the form's inputs at most: half of their height in the
   * frame (2.75rem). A pill-shaped input on the site is a pill in the form,
   * and the form's other boxes stay boxes.
   */
  var MAX_RADIUS = 22;

  /** The text inputs whose look the form copies: never a checkbox, a radio or a hidden field. */
  var TEXT_TYPES = ["", "text", "email", "tel", "number", "search", "url", "password"];

  /** Where to look when the checkout gives nothing better (siteStyles). */
  var SITE_FALLBACK = {
    inputs: ["form input"],
    buttons: ['form button[type="submit"]', 'button[type="submit"]'],
    links: ["main a", "a"],
  };

  /** The page's canvas, light or dark (canvasColor()), and what stands for a backdrop that is a picture (buildAppearance()). */
  var LIGHT_CANVAS = { r: 255, g: 255, b: 255, a: 1 };
  var DARK_CANVAS = { r: 18, g: 18, b: 18, a: 1 };

  /**
   * The colour functions a browser may compute a colour to besides rgb():
   * CSS Color 4's (oklch() and oklab(), lab() and lch(), color() in any
   * space), and hsl() and hwb() for good measure. Converted to be read
   * (readColor()); never a keyword (currentcolor, a system colour).
   */
  var COLOR_FUNCTION = /^(?:oklch|oklab|lab|lch|hsla?|hwb|color)\(\s*[^()]*\)$/;

  function clamp(n, low, high) {
    return Math.min(high, Math.max(low, n));
  }

  /** A colour channel: "128", "50%", or "0.5" in a color() function (`unit`: 1). */
  function channel(text, unit) {
    var match = /^(-?\d*\.?\d+(?:e-?\d+)?)(%?)$/i.exec(text || "");
    if (!match) {
      return NaN;
    }
    var n = parseFloat(match[1]);
    return match[2] ? (n * 255) / 100 : n * (unit ? 255 : 1);
  }

  function opacity(text) {
    if (text === undefined || text === "") {
      return 1;
    }
    var match = /^(-?\d*\.?\d+(?:e-?\d+)?)(%?)$/i.exec(text);
    if (!match) {
      return NaN;
    }
    return parseFloat(match[1]) / (match[2] ? 100 : 1);
  }

  function rgba(r, g, b, a) {
    if (isNaN(r) || isNaN(g) || isNaN(b) || isNaN(a)) {
      return null;
    }
    return { r: clamp(r, 0, 255), g: clamp(g, 0, 255), b: clamp(b, 0, 255), a: clamp(a, 0, 1) };
  }

  /** "r g b" or "r g b / a": the channels of a function written without commas. */
  function spaced(body, unit) {
    var halves = body.split(/\s*\/\s*/);
    var parts = halves[0].split(/\s+/);
    if (halves.length > 2 || parts.length !== 3) {
      return null;
    }
    return rgba(channel(parts[0], unit), channel(parts[1], unit), channel(parts[2], unit), opacity(halves[1]));
  }

  /**
   * A CSS colour as `{r, g, b, a}` (0-255, alpha 0-1): rgb() and rgba(), with
   * or without commas, hex, `transparent` and color(srgb …), the forms
   * getComputedStyle() gives. Null for anything else (a named colour,
   * oklch(), lab()…): not guessed.
   */
  function parseColor(value) {
    if (typeof value !== "string") {
      return null;
    }
    var text = value.replace(/^\s+|\s+$/g, "").toLowerCase();
    if (text === "transparent") {
      return { r: 0, g: 0, b: 0, a: 0 };
    }
    var hex = /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/.exec(text);
    if (hex) {
      var h = hex[1].length <= 4 ? hex[1].replace(/./g, "$&$&") : hex[1];
      return rgba(parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16), h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1);
    }
    var fn = /^rgba?\(\s*([^()]*?)\s*\)$/.exec(text);
    if (fn) {
      if (fn[1].indexOf(",") === -1) {
        return spaced(fn[1], false);
      }
      var parts = fn[1].split(/\s*,\s*/);
      if (parts.length !== 3 && parts.length !== 4) {
        return null;
      }
      return rgba(channel(parts[0]), channel(parts[1]), channel(parts[2]), opacity(parts[3]));
    }
    var srgb = /^color\(\s*srgb\s+([^()]*?)\s*\)$/.exec(text);
    return srgb ? spaced(srgb[1], true) : null;
  }

  /** `rgb(r, g, b)`, or `rgba(r, g, b, a)` when it lets the page through. */
  function formatColor(color) {
    var r = Math.round(color.r);
    var g = Math.round(color.g);
    var b = Math.round(color.b);
    var a = Math.round(color.a * 1000) / 1000;
    return a >= 1 ? "rgb(" + r + ", " + g + ", " + b + ")" : "rgba(" + r + ", " + g + ", " + b + ", " + a + ")";
  }

  /** WCAG 2's relative luminance, 0 (black) to 1 (white). */
  function luminance(color) {
    function linear(c) {
      var s = c / 255;
      return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * linear(color.r) + 0.7152 * linear(color.g) + 0.0722 * linear(color.b);
  }

  /** WCAG 2's contrast ratio between two opaque colours, 1 to 21. */
  function contrast(one, other) {
    var a = luminance(one);
    var b = luminance(other);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }

  /** `top` painted over `bottom`. */
  function composite(top, bottom) {
    var a = top.a + bottom.a * (1 - top.a);
    if (a <= 0) {
      return { r: 0, g: 0, b: 0, a: 0 };
    }
    function mix(t, b) {
      return (t * top.a + b * bottom.a * (1 - top.a)) / a;
    }
    return { r: mix(top.r, bottom.r), g: mix(top.g, bottom.g), b: mix(top.b, bottom.b), a: a };
  }

  /**
   * What shows through a stack of backgrounds, the nearest first, down to
   * the first opaque one, or else down to the page's own `canvas`.
   */
  function layered(layers, canvas) {
    var colors = [];
    for (var i = 0; i < layers.length; i++) {
      var color = typeof layers[i] === "string" ? parseColor(layers[i]) : layers[i];
      if (color && color.a > 0) {
        colors.push(color);
        if (color.a >= 1) {
          break;
        }
      }
    }
    var result = colors.length && colors[colors.length - 1].a >= 1 ? colors.pop() : canvas;
    while (colors.length) {
      result = composite(colors.pop(), result);
    }
    return result;
  }

  /** 'dark' for a background white text reads better on, else 'light'. */
  function schemeOf(background) {
    return luminance(background) < DARK_BELOW ? "dark" : "light";
  }

  /** A size in px within [min, max], as "16px"; "" for anything else. */
  function cssPx(value, min, max) {
    var match = /^(\d*\.?\d+)px$/.exec(typeof value === "string" ? value.replace(/^\s+|\s+$/g, "") : "");
    if (!match) {
      return "";
    }
    var n = parseFloat(match[1]);
    return n >= min && n <= max ? Math.round(n * 100) / 100 + "px" : "";
  }

  /** An input's rounding (its top corner's: "4px", or "4px 6px" when elliptic), at most MAX_RADIUS. */
  function radiusPx(value) {
    var first = typeof value === "string" ? value.replace(/^\s+/, "").split(/\s+/)[0] : "";
    var size = cssPx(first, 0, Infinity);
    return size ? Math.min(parseFloat(size), MAX_RADIUS) + "px" : "";
  }

  /** A font stack as the page computes it, if nothing in it reaches outside a font name. */
  function fontStack(value) {
    var text = typeof value === "string" ? value.replace(/\s+/g, " ").replace(/^ | $/g, "") : "";
    if (text === "" || text.length > 300 || /[;{}<>\\]|url\(|expression\(|@import/i.test(text)) {
      return "";
    }
    return text;
  }

  /**
   * The merchant's "Payment form colours" (Appearance, in the module's
   * settings): 'light' or 'dark' forces it; 'system', the default, follows
   * the site.
   */
  function schemeSetting(appearance) {
    var value = appearance && typeof appearance === "object" ? appearance.colorScheme || appearance.theme : "";
    return value === "light" || value === "dark" ? value : "system";
  }

  /**
   * Wajub.js's appearance (AppearanceConfig) from what readSite() found
   * (computed-style strings, "" when unread) and the merchant's setting
   * (schemeSetting()). The colours are the site's only on the scheme they
   * were made for: a form forced dark on a light site keeps Wajub.js's dark
   * colours, and only takes the site's font, size and rounding.
   *
   * The frame draws its text on the box as well as in its inputs, in one
   * colour (colorText): the input's own colours go, as a whole, only where
   * its text reads on the box. White inputs on a dark site leave the frame
   * its own dark inputs, rather than black text on the dark box. So does a
   * label that does not read on the box.
   *
   * A picture or a gradient behind the box (`backdrop: 'image'`) has no
   * colour to read, and the colour under it may say nothing of how it
   * looks: the text the site writes there (`ink`) does, light text being
   * written on a dark backdrop (MIN_INK_CONTRAST). The form takes that
   * scheme, and the box's own colour stays Wajub.js's: the colours read off
   * the site are checked against that scheme's plain canvas instead. A mid
   * grey, which could be on either, leaves the scheme to the colour under
   * the picture.
   */
  function buildAppearance(site, setting) {
    var s = site || {};
    var behind = parseColor(s.background);
    var siteScheme = behind && behind.a >= 1 ? schemeOf(behind) : "";
    var pictured = s.backdrop === "image";
    if (pictured) {
      var ink = parseColor(s.ink);
      var guess = "";
      if (ink && ink.a > 0) {
        guess = contrast(ink, LIGHT_CANVAS) < MIN_INK_CONTRAST ? "dark" : contrast(ink, DARK_CANVAS) < MIN_INK_CONTRAST ? "light" : siteScheme;
      }
      if (guess !== siteScheme) {
        siteScheme = guess;
        behind = guess === "dark" ? DARK_CANVAS : LIGHT_CANVAS;
      }
    }
    var scheme = setting === "light" || setting === "dark" ? setting : siteScheme;
    var out = {};
    var vars = {};

    function color(value, into, key) {
      var c = parseColor(value);
      if (c && c.a > 0) {
        into[key] = formatColor(c);
      }
    }

    /** Whether the colour, as it shows on the box, reads on it. */
    function readsOnBox(value) {
      var c = parseColor(value);
      return !!c && c.a > 0 && contrast(composite(c, behind), behind) >= MIN_TEXT_CONTRAST;
    }

    if (scheme) {
      out.colorScheme = scheme;
    }
    var family = fontStack(s.fontFamily);
    if (family) {
      vars.fontFamily = family;
    }
    var size = cssPx(s.fontSize, 10, 32);
    if (size) {
      vars.fontSizeBase = size;
    }
    var radius = radiusPx(s.radius);
    if (radius) {
      vars.borderRadius = radius;
    }
    if (siteScheme && scheme === siteScheme) {
      if (!pictured) {
        vars.colorBackground = formatColor(behind);
      }
      if (readsOnBox(s.text)) {
        color(s.text, vars, "colorText");
        color(s.placeholder, vars, "colorTextPlaceholder");
        color(s.inputBackground, out, "inputBackgroundColor");
        color(s.inputBorder, out, "inputBorderColor");
      }
      if (readsOnBox(s.label)) {
        color(s.label, vars, "colorTextSecondary");
      }
      var candidates = [s.button, s.link];
      for (var i = 0; i < candidates.length; i++) {
        var c = parseColor(candidates[i]);
        if (c && c.a > 0) {
          var painted = composite(c, behind);
          if (contrast(painted, behind) >= MIN_PRIMARY_CONTRAST && (scheme !== "dark" || contrast(painted, DARK_BUTTON_LABEL) >= MIN_TEXT_CONTRAST)) {
            vars.colorPrimary = formatColor(painted);
            break;
          }
        }
      }
    }
    for (var key in vars) {
      if (has(vars, key)) {
        out.variables = vars;
        break;
      }
    }
    return out;
  }

  function computed(node, pseudo) {
    if (!node || node.nodeType !== 1 || typeof window.getComputedStyle !== "function") {
      return null;
    }
    try {
      return window.getComputedStyle(node, pseudo || null);
    } catch (e) {
      return null;
    }
  }

  function prop(style, name) {
    var value = style ? style[name] : "";
    return typeof value === "string" ? value.replace(/^\s+|\s+$/g, "") : "";
  }

  /** What readColor() found for each colour function it was given, by its text: the page's colours do not change. */
  var converted = {};

  /**
   * A colour function of CSS Color 4 as the browser converts it to sRGB:
   * the colour mixed with itself in srgb (CSS Color 5's color-mix()), which
   * the browser computes to `color(srgb r g b / a)`, its channels outside
   * [0, 1] for a colour outside sRGB (parseColor() clips them, as the
   * browser paints it), on an unseen element of the page, its colour its
   * own whatever the site's stylesheets say (`!important`). Null where the
   * browser refuses the mix (one without color-mix(): Safari before 16.2).
   *
   * No canvas: a pixel read back from one is what browsers that guard
   * against fingerprinting (Tor Browser, Firefox's resistFingerprinting,
   * Brave) ask the payer about, or blur.
   */
  function convertColor(text) {
    if (has(converted, text)) {
      return converted[text];
    }
    var color = null;
    var root = document.documentElement || document.body;
    var probe = null;
    try {
      probe = root ? document.createElement("span") : null;
      var style = probe && probe.style;
      if (style && typeof style.setProperty === "function") {
        style.setProperty("display", "none", "important");
        style.setProperty("transition", "none", "important");
        style.setProperty("animation", "none", "important");
        style.setProperty("forced-color-adjust", "none", "important");
        style.setProperty("color", "color-mix(in srgb, " + text + ", " + text + ")", "important");
        if (style.getPropertyValue("color") !== "") {
          root.appendChild(probe);
          color = parseColor(prop(computed(probe), "color"));
        }
      }
    } catch (e) {
      color = null;
    }
    if (probe && probe.parentNode) {
      probe.parentNode.removeChild(probe);
    }
    converted[text] = color;
    return color;
  }

  /**
   * A computed colour as `{r, g, b, a}` in sRGB: what parseColor() reads,
   * else, for a colour function of CSS Color 4 (COLOR_FUNCTION), what the
   * browser converts it to (convertColor()). Null for anything else.
   */
  function readColor(value) {
    var known = parseColor(value);
    if (known || typeof value !== "string") {
      return known;
    }
    var text = value.replace(/^\s+|\s+$/g, "").toLowerCase();
    return COLOR_FUNCTION.test(text) ? convertColor(text) : null;
  }

  /** A computed colour as well-formed CSS, rgb() or rgba() (readColor()); "" when it cannot be read. */
  function colorOf(value) {
    var color = readColor(value);
    return color ? formatColor(color) : "";
  }

  /** On the page and drawn: not in a hidden section (no box), not hidden. */
  function shown(node) {
    if (!node || node.nodeType !== 1) {
      return false;
    }
    if (typeof node.getClientRects === "function" && node.getClientRects().length === 0) {
      return false;
    }
    var style = computed(node);
    return !style || (prop(style, "display") !== "none" && prop(style, "visibility") !== "hidden" && prop(style, "opacity") !== "0");
  }

  function matches(node, selector) {
    try {
      return typeof node.matches === "function" && node.matches(selector);
    } catch (e) {
      return false;
    }
  }

  function autofilled(node) {
    return matches(node, ":autofill") || matches(node, ":-webkit-autofill");
  }

  /** The first node the selectors find, in their order, that `accept` takes. */
  function firstOf(selectors, accept) {
    for (var i = 0; i < selectors.length; i++) {
      var found;
      try {
        found = document.querySelectorAll(selectors[i]);
      } catch (e) {
        continue;
      }
      for (var j = 0; j < found.length; j++) {
        if (accept(found[j])) {
          return found[j];
        }
      }
    }
    return null;
  }

  function sourcesOf(siteStyles, key) {
    var given = siteStyles && Array.isArray(siteStyles[key]) ? siteStyles[key] : [];
    return given.concat(SITE_FALLBACK[key]);
  }

  /**
   * A field the theme found valid: Bootstrap's `.is-valid` (Hummingbird)
   * draws the input's border green once the payer has filled it in.
   */
  function validated(node) {
    return matches(node, ".is-valid, .form-control-valid") || (typeof node.closest === "function" && !!node.closest(".is-valid"));
  }

  /**
   * A text input the payer sees as the site draws it at rest: not one the
   * checkout marked invalid, nor the one being typed in. One the browser
   * autofilled (it paints its background) or the checkout found valid (it
   * paints its border) only when `painted` allows.
   */
  function restingInput(node, painted) {
    var type = typeof node.getAttribute === "function" ? (node.getAttribute("type") || "").toLowerCase() : "";
    return (
      node.tagName === "INPUT" &&
      TEXT_TYPES.indexOf(type) !== -1 &&
      !node.disabled &&
      node !== document.activeElement &&
      !matches(node, ".is-invalid") &&
      !(typeof node.closest === "function" && node.closest(".has-error, .has-danger, .is-invalid")) &&
      (painted || !(autofilled(node) || validated(node))) &&
      shown(node)
    );
  }

  function labelOf(input) {
    var id = input.getAttribute("id");
    var label = id && /^[A-Za-z][\w-]*$/.test(id) ? document.querySelector('label[for="' + id + '"]') : null;
    if (!shown(label)) {
      var row = typeof input.closest === "function" ? input.closest(".form-group, .mb-3, .form-row") : null;
      label = row ? row.querySelector("label") : null;
    }
    return shown(label) ? label : null;
  }

  /** The colour of the input's first drawn border: the top, or the bottom of an underlined one. */
  function borderOf(style) {
    var sides = ["Top", "Bottom", "Left", "Right"];
    for (var i = 0; i < sides.length; i++) {
      var kind = prop(style, "border" + sides[i] + "Style");
      if (parseFloat(prop(style, "border" + sides[i] + "Width")) > 0 && kind !== "none" && kind !== "hidden") {
        return colorOf(prop(style, "border" + sides[i] + "Color"));
      }
    }
    return "";
  }

  /** The page's canvas, under every background: white, unless the site asks for a dark one. */
  function canvasColor() {
    var scheme = prop(computed(document.documentElement), "colorScheme");
    var dark = /\bdark\b/.test(scheme);
    if (dark && /\blight\b/.test(scheme)) {
      dark = !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
    }
    return dark ? DARK_CANVAS : LIGHT_CANVAS;
  }

  /**
   * What shows behind `node`: its own background and its ancestors', down to
   * the first opaque one (`color`), and whether a picture or a gradient is
   * painted on the way (`image`), whose colours cannot be read.
   */
  function backgroundBehind(node) {
    var layers = [];
    var image = false;
    for (var n = node; n && n.nodeType === 1; n = n.parentNode) {
      var style = computed(n);
      var picture = prop(style, "backgroundImage");
      if (picture !== "" && picture !== "none") {
        image = true;
      }
      var color = readColor(prop(style, "backgroundColor"));
      if (color && color.a > 0) {
        layers.push(color);
        if (color.a >= 1) {
          break;
        }
      }
    }
    return { color: layered(layers, canvasColor()), image: image };
  }

  /**
   * Reads the checkout's look around `container`: the background behind the
   * box, and the computed styles of a resting text input of the checkout,
   * its label, its "Place order" button and a link. `siteStyles` gives the
   * selectors to try first: `{inputs, buttons, links}`. Every value is the
   * computed style, a colour as rgb() (colorOf()), or "" when there was
   * nothing to read. A picture or a gradient behind the box is said
   * (`backdrop: 'image'`), with the colour of the text written on it (`ink`).
   */
  function readSite(container, siteStyles) {
    var behind = backgroundBehind(container);
    var site = { background: formatColor(behind.color) };
    if (behind.image) {
      site.backdrop = "image";
      site.ink = colorOf(prop(computed(container), "color"));
    }
    var inputs = sourcesOf(siteStyles, "inputs");
    var input =
      firstOf(inputs, function (node) {
        return restingInput(node, false);
      }) ||
      firstOf(inputs, function (node) {
        return restingInput(node, true);
      });
    var style = computed(input);
    if (style) {
      site.fontFamily = prop(style, "fontFamily");
      site.fontSize = prop(style, "fontSize");
      site.text = colorOf(prop(style, "color"));
      // The browser paints an autofilled input's background its own way,
      // and the checkout a valid field's border.
      site.inputBackground = autofilled(input) ? "" : colorOf(prop(style, "backgroundColor"));
      site.inputBorder = validated(input) ? "" : borderOf(style);
      site.radius = prop(style, "borderTopLeftRadius");
      // Unreadable where the browser gives the input's own colour for it.
      var placeholder = colorOf(prop(computed(input, "::placeholder"), "color"));
      site.placeholder = placeholder !== site.text ? placeholder : "";
      site.label = colorOf(prop(computed(labelOf(input)), "color"));
    }
    site.button = colorOf(prop(computed(firstOf(sourcesOf(siteStyles, "buttons"), shown)), "backgroundColor"));
    site.link = colorOf(prop(computed(firstOf(sourcesOf(siteStyles, "links"), shown)), "color"));
    return site;
  }

  /**
   * Wajub.js's appearance for a form mounted in `container`: the site's look,
   * under the merchant's setting. A page that cannot be read never keeps the
   * form from mounting: it then has the merchant's setting alone.
   */
  function siteAppearance(container, options) {
    var o = options || {};
    var setting = schemeSetting(o.appearance);
    try {
      return buildAppearance(readSite(container, o.siteStyles), setting);
    } catch (e) {
      return buildAppearance({}, setting);
    }
  }

  // --- Before the form is up ------------------------------------------------------
  //
  // From the first render, the box holds the room the form will take, with a
  // quiet outline of it drawn in the site's own input style (the skeleton),
  // while the frame loads unseen behind it, out of the keyboard's and screen
  // readers' reach. Once the frame says it is up, one fades into the other,
  // and the frame's own height (Wajub.js sets it as the frame resizes) is the
  // box's (checkout-fields.css).

  var SKELETON = "wajub-fields__skeleton";

  /**
   * The height each kind of form last took on this page as it first shows,
   * before the payer does anything in it: a form loaded again (fresh, with
   * nothing typed) holds that room.
   */
  var heights = {};

  /** The steps of a form at rest, its fields waiting for the payer (checkout.wajub's CheckoutState). */
  var RESTING_STATES = ["INITIATED", "COLLECTING_DETAILS"];

  function makeNode(owner, className) {
    var node = (owner || document).createElement("div");
    node.className = className;
    return node;
  }

  function skeletonOf(container) {
    var children = container.children || [];
    for (var i = 0; i < children.length; i++) {
      if (children[i].classList && children[i].classList.contains(SKELETON)) {
        return children[i];
      }
    }
    return null;
  }

  function setVar(node, name, value) {
    var style = node && node.style;
    if (!style || typeof style.setProperty !== "function") {
      return;
    }
    if (value) {
      style.setProperty(name, value);
    } else if (typeof style.removeProperty === "function") {
      style.removeProperty(name);
    }
  }

  /**
   * The outline of the form, as the frame lays it out: a label and its
   * input; for Mobile Money the hint under it, for a card a second row with
   * the expiry date and the security code, and a third with the cardholder's
   * name (the frame's own skeleton draws the same rows).
   */
  function drawSkeleton(owner, component) {
    var skeleton = makeNode(owner, SKELETON + " " + SKELETON + "--" + (component === "card" ? "card" : "single"));
    skeleton.setAttribute("aria-hidden", "true");
    function field(parent) {
      var node = makeNode(owner, SKELETON + "-field");
      node.appendChild(makeNode(owner, SKELETON + "-label"));
      node.appendChild(makeNode(owner, SKELETON + "-input"));
      parent.appendChild(node);
    }
    field(skeleton);
    if (component === "card") {
      var split = makeNode(owner, SKELETON + "-split");
      field(split);
      field(split);
      skeleton.appendChild(split);
      field(skeleton);
    } else {
      skeleton.appendChild(makeNode(owner, SKELETON + "-hint"));
    }
    return skeleton;
  }

  /** The skeleton takes the look the form is given: its input's border, background, rounding and ink. */
  function dressSkeleton(container, appearance) {
    var a = appearance || {};
    var vars = a.variables || {};
    setVar(container, "--wajub-fields-skeleton-border", a.inputBorderColor || "");
    setVar(container, "--wajub-fields-skeleton-background", a.inputBackgroundColor || vars.colorBackground || "");
    setVar(container, "--wajub-fields-skeleton-radius", vars.borderRadius || "");
    setVar(container, "--wajub-fields-skeleton-ink", vars.colorTextSecondary || vars.colorText || "");
  }

  function frameOf(container) {
    return typeof container.querySelector === "function" ? container.querySelector("iframe") : null;
  }

  /**
   * A frame that loads unseen is out of reach (`inert`): Tab never lands in
   * a form the payer cannot see, nor a screen reader reads it. It is back
   * once it shows.
   */
  function reach(container, on) {
    var frame = frameOf(container);
    if (!frame || typeof frame.setAttribute !== "function") {
      return;
    }
    if (on) {
      frame.removeAttribute("inert");
    } else {
      frame.setAttribute("inert", "");
    }
  }

  /** The room, the skeleton and the busy box of a form about to load (reserve()), its frame out of reach. */
  function hold(container, component, appearance) {
    if (!skeletonOf(container)) {
      container.insertBefore(drawSkeleton(container.ownerDocument, component), container.firstChild || null);
    }
    dressSkeleton(container, appearance);
    var known = heights[component];
    if (known) {
      setVar(container, "--wajub-fields-reserve", known + "px");
    }
    toggleClass(container, "is-loading", true);
    container.setAttribute("aria-busy", "true");
    reach(container, false);
  }

  /**
   * Holds the room of a form about to load in `container`, and draws its
   * skeleton in the site's look (options: component, appearance, the
   * merchant's setting, and siteStyles), before there is a payment to mount
   * it on. Screen readers hear the box is busy. Nothing changes if it is
   * already held. mount() holds it too.
   */
  function reserve(container, options) {
    if (!container || !container.classList || typeof container.insertBefore !== "function") {
      return;
    }
    if (container.classList.contains("is-loading") && skeletonOf(container)) {
      return;
    }
    var o = options || {};
    hold(container, o.component, siteAppearance(container, o));
  }

  /**
   * The box holds no room for a form any more (it falls back, or the method
   * is refused): a frame left in it shows as it is, within reach.
   */
  function unreserve(container) {
    if (!container || !container.classList) {
      return;
    }
    var skeleton = skeletonOf(container);
    if (skeleton && skeleton.parentNode) {
      skeleton.parentNode.removeChild(skeleton);
    }
    toggleClass(container, "is-loading", false);
    if (typeof container.removeAttribute === "function") {
      container.removeAttribute("aria-busy");
    }
    reach(container, true);
  }

  /**
   * The frame says it is up: it shows, within reach, and the skeleton
   * fades. A frame that has not said how tall it is yet keeps the room it
   * was given until it does, rather than the browser's default frame height.
   */
  function reveal(container) {
    var frame = frameOf(container);
    if (frame && frame.style && !frame.style.height) {
      var held = cssPx(prop(computed(frame), "height"), 1, Infinity);
      if (held) {
        frame.style.height = held;
      }
    }
    toggleClass(container, "is-loading", false);
    if (typeof container.removeAttribute === "function") {
      container.removeAttribute("aria-busy");
    }
    reach(container, true);
  }

  /**
   * Asks the shop for the payment the option's form runs on: the cart's
   * own, or a new one with `renew` (the form's session ended). `cfg` gives
   * the prepare controller's address (`prepareUrl`), the option's `kind`
   * and the cart's `token`. Resolves with `{paymentId, sessionId,
   * currency}`, or `{error: {code, message}}` when there is none to have
   * (the code is the shop's: method_unavailable, amount_out_of_range,
   * not_configured, unavailable; or timeout). Never rejects.
   */
  function prepare(cfg, options) {
    var renew = !!(options && options.renew);
    if (!cfg || !cfg.prepareUrl || typeof fetch !== "function") {
      return Promise.resolve(failure("unavailable"));
    }
    var body =
      "kind=" + encodeURIComponent(cfg.kind || "") + "&token=" + encodeURIComponent(cfg.token || "") + "&renew=" + (renew ? "1" : "0");
    var request = fetch(cfg.prepareUrl, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", Accept: "application/json" },
      body: body,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (answer) {
        var data = answer && typeof answer.data === "object" && answer.data ? answer.data : {};
        if (
          answer &&
          answer.success === true &&
          typeof data.paymentId === "string" &&
          data.paymentId !== "" &&
          typeof data.sessionId === "string" &&
          data.sessionId !== ""
        ) {
          return {
            paymentId: data.paymentId,
            sessionId: data.sessionId,
            currency: typeof data.currency === "string" ? data.currency : "",
          };
        }
        return failure(typeof data.code === "string" && data.code ? data.code : "unavailable", typeof data.message === "string" ? data.message : "");
      })
      .catch(function () {
        return failure("unavailable");
      });
    return Promise.race([request, after(PREPARE_TIMEOUT, failure("timeout"))]);
  }

  /** Names the frame for screen readers, in the payer's language: Wajub.js before 1.7.0 names it in English. */
  function nameFrame(container, title) {
    var frame = title ? frameOf(container) : null;
    if (frame) {
      frame.setAttribute("title", title);
    }
  }

  /**
   * Mounts the method's form in `container`. Options: sessionId, component
   * ('mobileMoney' | 'card'), locale, appearance (the merchant's "Payment
   * form colours": `{theme: 'light'|'dark'|'system'}`), siteStyles (where
   * the checkout's own inputs, button and links are: readSite()), country
   * (the billing country, read once: the form asks no country it already
   * has), componentOptions ({variant, fields}), frameTitle, and the
   * callbacks onReady(handle), onChange(change, handle), onLoadError(error,
   * handle), onState(state, handle) and onBeforeSubmit(handle, activated).
   *
   * The form is given the site's look (siteAppearance()), and loads unseen
   * behind its skeleton, in the room it will take (reserve()), until it
   * says it is up.
   *
   * A form that does not say it is up within 15 seconds (an embedding the
   * merchant restricted, a blocked frame) is reported as the load error
   * `ready_timeout`.
   *
   * Pressing Enter in the form asks onBeforeSubmit first: the form may charge
   * only once the payment is activated (setActivated(true)); before that the
   * answer is no, and the callback is where the page places the order.
   *
   * Returns the handle `{instance, sessionId, container, isComplete(),
   * submitAsked(), hasFailed(), focus(), setActivated(bool), isActivated(),
   * remount(), isDestroyed(), destroy()}`, or null when Wajub.js cannot
   * mount it.
   */
  function mount(container, options) {
    var o = options || {};
    if (!container || !o.sessionId || !o.component || !available()) {
      return null;
    }

    var activated = false;
    var destroyed = false;
    // The form ended an attempt on its own failure screen, which has no field
    // to submit until it is loaded again (remount()). Wajub.js 1.6.0 reports
    // no step for it (the frame sends none for SUCCESS, FAILED, CANCELLED or
    // EXPIRED), only a change that carries the error: getState() still holds
    // the step before.
    var failed = false;
    // Enter was pressed in the form before the payment was activated. The
    // frame asks only once its fields are valid, but reports itself
    // incomplete while it waits: a change just before it asks and another
    // just after, then its report of the refusal (checkout_validation, or
    // submission_failed for a card), and only then a change that says it is
    // complete again. Until a change after that report, the form is as
    // complete as it was when it asked.
    var asked = false;
    // The answer was no, and the frame has not reported the refusal yet: a
    // change meanwhile is the frame waiting for the answer, not the payer
    // editing the form.
    var refusing = false;
    // The focus was asked for (focus()) while the frame loaded unseen, out of
    // reach: where the payer's focus was then. It moves to the form once the
    // form is up, unless the payer has moved it meanwhile.
    var focusWanted = null;
    // Whatever the payer does meanwhile (types on, clicks somewhere) keeps
    // the focus where they have it: the form does not take it on its ready.
    var watching = false;
    function payerActs() {
      if (focusWanted) {
        focusWanted.acted = true;
      }
      stopWatching();
    }
    function watchPayer() {
      if (watching || typeof document.addEventListener !== "function") {
        return;
      }
      watching = true;
      document.addEventListener("keydown", payerActs, true);
      document.addEventListener("pointerdown", payerActs, true);
    }
    function stopWatching() {
      if (!watching) {
        return;
      }
      watching = false;
      if (typeof document.removeEventListener === "function") {
        document.removeEventListener("keydown", payerActs, true);
        document.removeEventListener("pointerdown", payerActs, true);
      }
    }
    // The frame shows its form as it first does, before the payer does
    // anything in it: the height it says then is the room the next form of
    // its kind holds (heights). Not once the payer is in it (a line under a
    // number found wrong), nor once a submission starts (the veil the frame
    // draws over its form while it charges), a step or its failure screen.
    var resting = true;
    var handle = null;
    var readyTimer = null;
    var title = typeof o.frameTitle === "string" ? o.frameTitle : "";
    var readyTimeout = typeof o.readyTimeout === "number" ? o.readyTimeout : READY_TIMEOUT;

    var base = o.componentOptions && typeof o.componentOptions === "object" ? o.componentOptions : {};
    var cfg = {};
    for (var key in base) {
      if (has(base, key) && key !== "fields") {
        cfg[key] = base[key];
      }
    }
    var fields = {};
    var given = base.fields && typeof base.fields === "object" ? base.fields : {};
    for (var name in given) {
      if (has(given, name)) {
        fields[name] = given[name];
      }
    }
    if (typeof o.country === "string" && /^[A-Za-z]{2}$/.test(o.country)) {
      fields.country = o.country.toUpperCase();
    }
    cfg.fields = fields;
    if (o.locale) {
      cfg.locale = o.locale;
    }
    // The site's look, under the merchant's "Payment form colours".
    var appearance = siteAppearance(container, o);
    cfg.appearance = appearance;
    if (title) {
      // Wajub.js 1.7.0 names the frame with it; before it, nameFrame() below.
      cfg.frameTitle = title;
    }

    function clearReady() {
      if (readyTimer !== null) {
        clearTimeout(readyTimer);
        readyTimer = null;
      }
    }

    function startReady() {
      clearReady();
      if (readyTimeout > 0) {
        readyTimer = setTimeout(function () {
          readyTimer = null;
          if (!destroyed && o.onLoadError) {
            o.onLoadError({ code: "ready_timeout", message: "" }, handle);
          }
        }, readyTimeout);
      }
    }

    /** The keyboard and the screen reader to the form, scrolled into view. */
    function focusFrame() {
      var frame = frameOf(container);
      var target = frame || container;
      if (target && typeof target.focus === "function") {
        target.focus();
      }
      try {
        instance.focus();
      } catch (e) {
        // Not mounted any more.
      }
    }

    cfg.onReady = function () {
      clearReady();
      if (destroyed) {
        return;
      }
      reveal(container);
      var wanted = focusWanted;
      focusWanted = null;
      stopWatching();
      if (wanted && !wanted.acted) {
        var now = document.activeElement || null;
        if (now === wanted.from || now === null || now === document.body) {
          focusFrame();
        }
      }
      if (o.onReady) {
        o.onReady(handle);
      }
    };
    cfg.onChange = function (change) {
      var c = change || {};
      if (c.complete === false && c.empty === false) {
        // The frame says so as it starts a submission, and of a field the
        // payer has been in.
        resting = false;
      }
      if (c.error) {
        // The frame sends an error with a change only as it shows its
        // failure screen: the room a follow-up step needed goes with it,
        // and so does a question asked before.
        asked = false;
        refusing = false;
        failed = true;
        resting = false;
        toggleClass(container, "is-action", false);
      } else if (!refusing) {
        asked = false;
      }
      if (!destroyed && o.onChange) {
        o.onChange(c, handle);
      }
    };
    // An attempt that ends is reported by its outcome, never by a step: the
    // follow-up step's room goes. A step the frame reports after it (a
    // refusal that leaves the form open) gives it back.
    cfg.onSuccess = function () {
      toggleClass(container, "is-action", false);
    };
    cfg.onError = function (error) {
      // A refusal may show a line under a field.
      resting = false;
      toggleClass(container, "is-action", false);
      var code = errorCode(error);
      if (refusing && (code === "checkout_validation" || code === "submission_failed")) {
        // The frame reported the refusal: its next change is the payer's form again.
        refusing = false;
      }
    };
    cfg.onLoadError = function (error) {
      clearReady();
      if (!destroyed && o.onLoadError) {
        o.onLoadError(error || {}, handle);
      }
    };
    cfg.onBeforeSubmit = function () {
      resting = false;
      var allowed = activated;
      if (!allowed) {
        asked = true;
        refusing = true;
      }
      if (!destroyed && o.onBeforeSubmit) {
        try {
          o.onBeforeSubmit(handle, allowed);
        } catch (e) {
          // The page's own trouble: the form's answer stays the same.
        }
      }
      return allowed;
    };

    var instance = null;
    hold(container, o.component, appearance);
    try {
      instance = window.wajub.components(o.sessionId, {}).create(o.component, cfg);
      instance.mount(container);
    } catch (e) {
      if (instance && typeof instance.destroy === "function") {
        try {
          instance.destroy();
        } catch (ignored) {
          // Never mounted.
        }
      }
      unreserve(container);
      return null;
    }
    nameFrame(container, title);
    reach(container, false);

    if (typeof instance.on === "function") {
      // The form's own height, as it first lays itself out (resting): the
      // room the next form of its kind holds while it loads (a redraw of the
      // checkout, "Try again"), so the page does not move under the payer.
      instance.on("resize", function (payload) {
        var height = payload && typeof payload.height === "number" ? Math.ceil(payload.height) : 0;
        if (height > 0 && !destroyed && resting && !failed && !container.classList.contains("is-action")) {
          heights[o.component] = height;
        }
      });
      // The payer is in the form: what they type may show more of it.
      instance.on("focus", function () {
        resting = false;
      });
      instance.on("statechange", function (payload) {
        var state = payload && typeof payload.state === "string" ? payload.state : "";
        if (RESTING_STATES.indexOf(state) === -1) {
          resting = false;
        }
        // Any step the frame reports is not its failure screen: the payer
        // used the frame's own retry.
        failed = false;
        toggleClass(container, "is-action", ACTION_STATES.indexOf(state) !== -1);
        if (!destroyed && o.onState) {
          o.onState(state, handle);
        }
      });
    }

    handle = {
      instance: instance,
      sessionId: o.sessionId,
      container: container,
      isComplete: function () {
        try {
          return !destroyed && !!instance.isComplete();
        } catch (e) {
          return false;
        }
      },
      /**
       * Enter was pressed in the form before activation, and the payer has
       * not changed the form since: it was valid when it asked, whatever
       * isComplete() says while the frame waits for the answer and reports
       * the refusal.
       */
      submitAsked: function () {
        return !destroyed && asked;
      },
      /** The form shows its failure screen after a declined attempt: only remount() brings its fields back. */
      hasFailed: function () {
        return !destroyed && failed;
      },
      /**
       * Moves the keyboard and the screen reader to the form, scrolled into
       * view. A form still loading, unseen and out of reach, takes them once
       * it is up, if the payer has not moved them meanwhile.
       */
      focus: function () {
        if (destroyed) {
          return;
        }
        if (container.classList.contains("is-loading")) {
          focusWanted = { from: document.activeElement || null, acted: false };
          watchPayer();
          return;
        }
        focusFrame();
      },
      setActivated: function (value) {
        activated = !!value;
        if (activated) {
          asked = false;
          refusing = false;
        }
      },
      isActivated: function () {
        return activated;
      },
      /** Loads the form again on the same payment: what the payer typed goes, the payment stays. */
      remount: function () {
        if (destroyed) {
          return false;
        }
        try {
          instance.mount(container);
        } catch (e) {
          return false;
        }
        failed = false;
        asked = false;
        refusing = false;
        // A new frame, fresh: unseen and out of reach behind the skeleton,
        // which holds its room again until it is up.
        focusWanted = null;
        stopWatching();
        resting = true;
        nameFrame(container, title);
        toggleClass(container, "is-action", false);
        hold(container, o.component, appearance);
        startReady();
        return true;
      },
      isDestroyed: function () {
        return destroyed;
      },
      destroy: function () {
        if (destroyed) {
          return;
        }
        destroyed = true;
        focusWanted = null;
        stopWatching();
        clearReady();
        toggleClass(container, "is-action", false);
        try {
          instance.destroy();
        } catch (e) {
          // Already gone from the page.
        }
        unreserve(container);
      },
    };
    startReady();
    return handle;
  }

  function outcomeOf(code) {
    if (code === "payment_cancelled") {
      return "cancelled";
    }
    if (code.indexOf("session_") === 0) {
      return "expired";
    }
    if (code === "submission_failed") {
      return "invalid";
    }
    if (code === "component_unmounted") {
      return "unmounted";
    }
    return "declined";
  }

  /**
   * Submits the form (unless `options.submit` is false: the form submitted
   * itself) and resolves with what came of it: `{status: 'success'|
   * 'declined'|'invalid'|'cancelled'|'expired'|'unmounted', error?,
   * transaction?}`. A declined attempt is reported by an error or by a
   * change that carries one; the form then offers to try again in the same
   * session. 'invalid': the form refused the submission (`submission_failed`:
   * a field it found wrong, a card provider's window the browser blocked),
   * with why in the payer's language in `error.message`. No time limit, and
   * never rejects: an approval on a phone takes as long as it takes, and
   * follow() watches the order meanwhile.
   */
  function pay(handle, options) {
    var send = !(options && options.submit === false);
    return new Promise(function (resolve) {
      var instance = handle && handle.instance;
      if (!instance || typeof instance.on !== "function" || (typeof handle.isDestroyed === "function" && handle.isDestroyed())) {
        resolve({ status: "unmounted" });
        return;
      }

      var done = false;
      function finish(outcome) {
        if (done) {
          return;
        }
        done = true;
        if (typeof instance.off === "function") {
          instance.off("success", onSuccess);
          instance.off("error", onError);
          instance.off("change", onChange);
        }
        resolve(outcome);
      }
      function onSuccess(transaction) {
        finish({ status: "success", transaction: transaction || null });
      }
      function onError(error) {
        var code = errorCode(error);
        // The form asked whether it may charge before the payment was
        // activated (Enter pressed in it), and was told no: the order is
        // being placed, nothing was tried.
        if (code === "checkout_validation" && !handle.isActivated()) {
          return;
        }
        finish({ status: outcomeOf(code), error: error || null });
      }
      function onChange(change) {
        if (change && change.error) {
          finish({ status: "declined", error: change.error });
        }
      }

      instance.on("success", onSuccess);
      instance.on("error", onError);
      instance.on("change", onChange);
      if (send) {
        try {
          instance.submit();
        } catch (e) {
          finish({ status: "unmounted", error: null });
        }
      }
    });
  }

  function readStatus(url, cap) {
    if (!url || typeof fetch !== "function") {
      return Promise.resolve(null);
    }
    var request = fetch(url, { credentials: "same-origin", cache: "no-store" })
      .then(function (response) {
        return response.json();
      })
      .then(function (answer) {
        return answer && answer.success && answer.data ? answer.data : null;
      })
      .catch(function () {
        return null;
      });
    return Promise.race([request, after(cap, null)]);
  }

  /**
   * Asks the shop once where the order stands (`{status, processing,
   * redirect, reason?}`, Checkout\\StatusAnswer): it settles a paid
   * order before its confirmation page opens. Resolves null when it could
   * not be had within five seconds. Never rejects.
   */
  function checkStatusOnce(url) {
    return readStatus(url, STATUS_TIMEOUT);
  }

  /**
   * How long follow() waits before asking again: 5 seconds, then a little
   * longer each time, never more than 10. The shop keeps Wajub's answer a
   * few seconds for every page that asks (Order\\Settlement::outcome()).
   */
  function pollDelay(polls) {
    return Math.min(5000 + polls * 500, 10000);
  }

  /**
   * Follows the order while the payer pays: onPaid(answer) or
   * onFailed(answer) once it ends, onProcessing(answer) while Wajub is
   * taking the payment. Returns stop().
   */
  function follow(statusUrl, callbacks) {
    var cb = callbacks || {};
    var stopped = false;
    var timer = null;
    var polls = 0;

    function stop() {
      stopped = true;
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
      }
    }

    if (!statusUrl || typeof fetch !== "function") {
      return stop;
    }

    function next() {
      if (stopped) {
        return;
      }
      if (polls >= MAX_POLLS) {
        stop();
        return;
      }
      timer = setTimeout(poll, pollDelay(polls));
    }

    function poll() {
      timer = null;
      if (stopped) {
        return;
      }
      polls++;
      readStatus(statusUrl, FOLLOW_TIMEOUT).then(function (answer) {
        if (stopped) {
          return;
        }
        if (answer && answer.status === "paid") {
          stop();
          if (cb.onPaid) {
            cb.onPaid(answer);
          }
          return;
        }
        if (answer && answer.status === "failed") {
          stop();
          if (cb.onFailed) {
            cb.onFailed(answer);
          }
          return;
        }
        if (answer && answer.processing && cb.onProcessing) {
          cb.onProcessing(answer);
        }
        next();
      });
    }

    next();
    return stop;
  }

  /**
   * Takes an activated form to the end of its payment: submits it, follows
   * the order alongside, and reports what the page should show and where
   * the payer goes.
   *
   * Options: statusUrl, returnUrl, i18n (the box's words), onUpdate(view)
   * with `{status, error, busy, checking}` (busy: an attempt is under way,
   * "Try again" waits; checking: the shop is being asked, nothing else can
   * be done; error: the box's words, or the form's own when it refused a
   * submission), and onEnd(end), once:
   * - `{type: 'paid', url}`: the order is paid, the payer goes to `url`;
   * - `{type: 'confirming', url}`: Wajub is still taking the payment (an
   *   approval on its way), and the order's confirmation page follows it:
   *   never a second attempt while the first may still be charged;
   * - `{type: 'left', reason, message, tone}`: the payment ended unpaid
   *   ('switch': the payer chose another method; 'cancelled', 'expired',
   *   'failed'). The page gives the order back as a cart (the shop's
   *   release controller), and says `message`: an activated payment is
   *   never handed out again.
   *
   * Returns `{retry(), leave(reason), submitted(), remounted(handle)}`:
   * "Try again", "Use another payment method" (reason 'switch' by
   * default), the form let charge once activated (onBeforeSubmit's yes: it
   * submitted itself, Enter, or charges the box's submission), and the form
   * mounted again in a new node on the same payment.
   */
  function run(handle, options) {
    var o = options || {};
    var words = o.i18n || {};
    var current = handle;
    var ended = false;
    var checking = false;
    var inFlight = false;
    // A submission is waiting for its outcome: the box's own, or the form's
    // once it asked and was told yes (Enter). Still true once "Nothing
    // happened?" lets the payer try again: the outcome may come late.
    var pending = false;
    // The form asked whether it may charge, during the box's submission or
    // as it started one (Enter), and was told yes: it is charging, and says
    // what came of it once it knows. A card paid in its provider's own
    // window says nothing until that window closes, however long the payer
    // takes there: meanwhile "Nothing happened?" is never said, and "Try
    // again" waits.
    var charging = false;
    var declined = false;
    // "Try again" found the form incomplete and said so, in the alert.
    var missing = false;
    // "Try again" loaded the form again and asked the payer to complete it,
    // in the status line.
    var hinted = false;
    var attempt = 0;
    var quietTimer = null;
    var stopFollow = null;
    var unwatch = null;
    var view = { status: "", error: "", busy: false, checking: false };

    function t(key) {
      return typeof words[key] === "string" ? words[key] : "";
    }

    function update(changes) {
      for (var key in changes) {
        if (has(changes, key)) {
          view[key] = changes[key];
        }
      }
      // A line written over is no longer one the completed form clears.
      if (has(changes, "error")) {
        missing = false;
      }
      if (has(changes, "status")) {
        hinted = false;
      }
      view.busy = inFlight || checking;
      view.checking = checking;
      if (!ended && o.onUpdate) {
        o.onUpdate({ status: view.status, error: view.error, busy: view.busy, checking: view.checking });
      }
    }

    function clearQuiet() {
      if (quietTimer !== null) {
        clearTimeout(quietTimer);
        quietTimer = null;
      }
    }

    /**
     * A form that finds a field invalid can stay silent: after a while with
     * nothing from it, the payer is asked to check it, and may try again.
     * Not once it charges: what comes of that is on its way.
     */
    function quiet() {
      clearQuiet();
      if (charging) {
        return;
      }
      var mine = attempt;
      quietTimer = setTimeout(function () {
        quietTimer = null;
        if (!ended && !checking && mine === attempt && inFlight) {
          inFlight = false;
          update({ status: t("nothingHappened") });
        }
      }, QUIET_TIMEOUT);
    }

    /**
     * The form did something after the submission (a change, the focus
     * leaving it for a provider's window or coming back): it is not silent,
     * and its 15 seconds start again.
     */
    function stirred() {
      if (quietTimer !== null && !ended && !checking) {
        quiet();
      }
    }

    function stopFollowing() {
      if (stopFollow) {
        stopFollow();
        stopFollow = null;
      }
    }

    // Any step the form takes after a submission means it is working on it,
    // and so does a form that takes the page to a provider's (Wajub.js 1.7.0
    // `redirect`: the whole tab leaves, and nothing here holds it back).
    // Anything else it reports (a change, its focus leaving it for a
    // provider's window or coming back) means it is not silent: the wait
    // for "Nothing happened?" starts again. A form the payer completes after
    // "Try again" said it was not, or asked them to complete it, clears what
    // was said, as it does before the order is placed.
    function watch(target) {
      if (unwatch) {
        unwatch();
      }
      var instance = target.instance;
      var onState = function () {
        clearQuiet();
      };
      var onFocus = function () {
        stirred();
      };
      var onChange = function (change) {
        stirred();
        if (!change || !change.complete || ended || checking || (!missing && !hinted)) {
          return;
        }
        var cleared = {};
        if (missing) {
          cleared.error = "";
        }
        if (hinted) {
          cleared.status = "";
        }
        update(cleared);
      };
      if (instance && typeof instance.on === "function") {
        instance.on("statechange", onState);
        instance.on("redirect", onState);
        instance.on("change", onChange);
        instance.on("focus", onFocus);
        instance.on("blur", onFocus);
        unwatch = function () {
          if (typeof instance.off === "function") {
            instance.off("statechange", onState);
            instance.off("redirect", onState);
            instance.off("change", onChange);
            instance.off("focus", onFocus);
            instance.off("blur", onFocus);
          }
        };
      } else {
        unwatch = null;
      }
    }

    function finish(end) {
      if (ended) {
        return;
      }
      ended = true;
      checking = false;
      inFlight = false;
      clearQuiet();
      stopFollowing();
      if (unwatch) {
        unwatch();
        unwatch = null;
      }
      if (o.onEnd) {
        o.onEnd(end);
      }
    }

    /** What the payer reads when the payment ended unpaid. */
    function said(reason, answer) {
      var why = answer && typeof answer.reason === "string" ? answer.reason : "";
      if (reason === "expired" || why === "expired") {
        return { key: "expired", tone: "error" };
      }
      if (reason === "failed") {
        return why === "cancelled" ? { key: "cancelled", tone: "info" } : { key: "failed", tone: "error" };
      }
      // Left after a declined attempt: the payment failed, not merely stopped.
      return declined ? { key: "failed", tone: "error" } : { key: "cancelled", tone: "info" };
    }

    function succeed() {
      if (ended || checking) {
        return;
      }
      checking = true;
      inFlight = false;
      clearQuiet();
      stopFollowing();
      update({ status: t("paid"), error: "" });
      // Settled by the shop before the confirmation page opens, which would
      // otherwise show the paid order as waiting until the webhook lands.
      checkStatusOnce(o.statusUrl).then(function (answer) {
        finish({ type: "paid", url: (answer && answer.redirect) || o.returnUrl || "" });
      });
    }

    /**
     * The payer leaves the payment, or it ended: the shop is asked once,
     * since a Mobile Money approval may have gone through a moment before. A
     * paid order goes to its confirmation; so does a payment Wajub is still
     * taking, and one the shop could not be asked about when the payer
     * chose another method without a declined attempt: a second "Place
     * order" could charge them twice.
     */
    function leave(reason, failure) {
      if (ended || checking) {
        return;
      }
      checking = true;
      inFlight = false;
      // Whatever the shop answers, this form charges no more: Enter in it
      // is refused (onBeforeSubmit) while the payer is let go.
      current.setActivated(false);
      clearQuiet();
      stopFollowing();
      update({ status: t("checking"), error: "" });
      checkStatusOnce(o.statusUrl).then(function (answer) {
        if (ended) {
          return;
        }
        if (answer && answer.status === "paid") {
          update({ status: t("paid") });
          finish({ type: "paid", url: answer.redirect || o.returnUrl || "" });
          return;
        }
        var onItsWay = answer ? answer.status === "pending" && !!answer.processing : reason === "switch" && !declined;
        if (onItsWay) {
          update({ status: t("confirming") });
          finish({ type: "confirming", url: (answer && answer.redirect) || o.returnUrl || "" });
          return;
        }
        var message = said(reason, answer || failure);
        finish({ type: "left", reason: reason, message: t(message.key), tone: message.tone });
      });
    }

    /**
     * The form refused the submission. Wajub.js's checkout says why from
     * 1.7.0 on, in the payer's language (a number it found wrong, a check
     * still running, a card provider's window the browser blocked and what
     * to press in the form to open it): the box says it too, and "Try
     * again" is back. Before, it said nothing, or nothing came (the 15
     * seconds of submit()). The payment may still end without a submission
     * of the box's: a card paid in its provider's window, opened from the
     * form, so what comes next is heard.
     */
    function refused(outcome) {
      var why = outcome.error && typeof outcome.error.message === "string" ? outcome.error.message.replace(/^\s+|\s+$/g, "") : "";
      update(why ? { status: "", error: why } : { status: t("nothingHappened"), error: "" });
      listen(false);
    }

    function settle(outcome) {
      switch (outcome.status) {
        case "success":
          succeed();
          return;
        case "cancelled":
          leave("cancelled");
          return;
        case "expired":
          leave("expired");
          return;
        case "declined":
          // The payment stays activated: the same form can try again.
          declined = true;
          update({ status: "", error: t("declined") });
          return;
        case "invalid":
          refused(outcome);
          return;
        default:
          // The form left its node: the page mounts it again (remounted()).
          update({});
      }
    }

    /**
     * A submission: the box's own (`send`), or one the form started itself
     * (Enter, once activated), which it asked to charge and was let.
     */
    function submit(send) {
      if (ended || checking || inFlight) {
        return;
      }
      attempt++;
      inFlight = true;
      pending = true;
      charging = !send;
      quiet();
      update({ status: t("paying"), error: "" });
      listen(send);
    }

    /**
     * Hears what comes of the form's latest attempt: the one submit()
     * starts, or, once the form is loaded again on the same payment, one
     * the frame carries on with or reports by itself. An outcome that
     * arrives once a newer attempt started, or while the shop is asked
     * whether the payer may leave, is not news. Nor is a refused submission
     * while none is waiting here: the form tells the payer by itself (a
     * provider's window it could not open again), and what comes next is
     * still heard.
     */
    function listen(send) {
      var mine = attempt;
      pay(current, { submit: send }).then(function (outcome) {
        if (ended || checking || mine !== attempt) {
          return;
        }
        if (outcome.status === "invalid" && !pending) {
          listen(false);
          return;
        }
        pending = false;
        inFlight = false;
        clearQuiet();
        settle(outcome);
      });
    }

    function retry() {
      if (ended || checking || inFlight) {
        return;
      }
      // After a decline the form shows its own failure screen, which has no
      // field to submit: it is loaded again on the same payment, for the
      // payer to complete. A refusal that left the form open (a number the
      // operator rejected) is submitted again as it is.
      if (current.hasFailed()) {
        // What the form says as it goes is not news of the payment.
        attempt++;
        if (current.remount()) {
          update({ status: t("incomplete"), error: "" });
          hinted = true;
          current.focus();
          // A form that comes back on its failure screen says so again.
          listen(false);
        }
        return;
      }
      if (!current.isComplete()) {
        update({ status: "", error: t("incomplete") });
        missing = true;
        current.focus();
        return;
      }
      submit(true);
    }

    /**
     * The form was mounted again in a new node, on the same payment. What
     * the box says stays, and the new form reports what comes of an attempt
     * under way. "Try again" is offered again: the box cannot tell whether
     * the new form still shows that attempt or its fields.
     */
    function remounted(next) {
      if (ended || !next) {
        return;
      }
      // Whatever the previous form reports as it goes is not this payment's news.
      attempt++;
      inFlight = false;
      pending = false;
      clearQuiet();
      current = next;
      watch(current);
      // While the shop is asked whether the payer may leave, the new form
      // may not charge either, and nothing it says changes the answer.
      if (!checking) {
        current.setActivated(true);
        listen(false);
      }
      update({});
    }

    current.setActivated(true);
    watch(current);
    stopFollow = follow(o.statusUrl, {
      onPaid: function () {
        if (!ended && !checking) {
          succeed();
        }
      },
      onFailed: function (answer) {
        if (!ended && !checking) {
          leave("failed", answer);
        }
      },
    });
    submit(true);

    return {
      retry: retry,
      leave: function (reason) {
        leave(typeof reason === "string" && reason ? reason : "switch");
      },
      submitted: function () {
        if (ended || checking) {
          return;
        }
        if (inFlight) {
          // The form asks during the box's submission: it charges now.
          charging = true;
          clearQuiet();
          return;
        }
        submit(false);
      },
      remounted: remounted,
    };
  }

  window.WajubFields = {
    available: available,
    prepare: prepare,
    mount: mount,
    pay: pay,
    follow: follow,
    checkStatusOnce: checkStatusOnce,
    run: run,
    reserve: reserve,
    unreserve: unreserve,
    ACTION_STATES: ACTION_STATES.slice(),
    /** How the site's look becomes the form's: pure, but for readSite() and siteAppearance(). */
    look: {
      parseColor: parseColor,
      formatColor: formatColor,
      luminance: luminance,
      contrast: contrast,
      composite: composite,
      layered: layered,
      schemeOf: schemeOf,
      cssPx: cssPx,
      radiusPx: radiusPx,
      fontStack: fontStack,
      schemeSetting: schemeSetting,
      buildAppearance: buildAppearance,
      readSite: readSite,
      siteAppearance: siteAppearance,
    },
  };
})();

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * Every Wajub option of the checkout's payment step, whatever draws it: the
 * logo PrestaShop draws beside an option's name, which its themes leave
 * without a text alternative, gets one, the names of the brands it shows
 * (`data-wajub-logo-alt` of the option's box, payment-info.tpl). Done again
 * when the payment step is drawn again. No dependency.
 */
(function () {
  "use strict";

  if (!document.querySelectorAll) {
    return;
  }

  /** The radio of the option an option's box belongs to. */
  function radioOf(info) {
    var holder = typeof info.closest === "function" ? info.closest('[id$="-additional-information"]') : null;
    if (holder && holder.id) {
      var radio = document.getElementById(holder.id.slice(0, -"-additional-information".length));
      if (radio) {
        return radio;
      }
    }
    // An option a checkout module draws its own way: the radio of the
    // largest block around the box that holds one radio only.
    var found = null;
    for (var node = info.parentNode; node && node !== document.body && node.nodeType === 1; node = node.parentNode) {
      var radios = node.querySelectorAll('input[name="payment-option"]');
      if (radios.length > 1) {
        break;
      }
      if (radios.length === 1) {
        found = radios[0];
      }
    }
    return found;
  }

  /**
   * The radio's labels: one that names it (Classic: `label for`), or one
   * that holds it (Hummingbird).
   */
  function labelsOf(radio) {
    var labels = [];
    var all = radio.labels || document.querySelectorAll('label[for="' + radio.id + '"]');
    for (var i = 0; i < all.length; i++) {
      labels.push(all[i]);
    }
    var holder = typeof radio.closest === "function" ? radio.closest("label") : null;
    if (holder && labels.indexOf(holder) === -1) {
      labels.push(holder);
    }
    return labels;
  }

  function describe() {
    var infos = document.querySelectorAll("[data-wajub-option][data-wajub-logo-alt]");
    for (var i = 0; i < infos.length; i++) {
      var radio = radioOf(infos[i]);
      if (!radio || !radio.id) {
        continue;
      }
      var alt = infos[i].getAttribute("data-wajub-logo-alt") || "";
      for (var labels = labelsOf(radio), l = 0; l < labels.length; l++) {
        var images = labels[l].querySelectorAll("img");
        for (var m = 0; m < images.length; m++) {
          if (!images[m].getAttribute("alt")) {
            images[m].setAttribute("alt", alt);
          }
        }
      }
    }
  }

  function watch() {
    if (typeof window.MutationObserver !== "function" || !document.body) {
      return;
    }
    var pending = false;
    new window.MutationObserver(function (records) {
      for (var i = 0; i < records.length && !pending; i++) {
        for (var n = 0; n < records[i].addedNodes.length; n++) {
          var node = records[i].addedNodes[n];
          if (node.nodeType === 1 && (node.matches("[data-wajub-option], label") || node.querySelector("[data-wajub-option]"))) {
            pending = true;
            setTimeout(function () {
              pending = false;
              describe();
            }, 0);
            break;
          }
        }
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  function init() {
    describe();
    watch();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * The Copy button of the back office's notices (the scheduled task's
 * address): shown only when this script runs; without it, the address is
 * in a read-only field the merchant selects. No dependency.
 */
(function (window, document) {
  "use strict";

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-wajub-notice-copy]"), function (button) {
      if (button.getAttribute("data-wajub-ready") === "1") {
        return;
      }
      var box = button.closest("[data-wajub-notice-copy-box]");
      var field = box && box.querySelector("[data-wajub-notice-field]");
      var status = box && box.querySelector("[data-wajub-notice-copied]");
      if (!field) {
        return;
      }
      button.setAttribute("data-wajub-ready", "1");
      button.hidden = false;
      button.addEventListener("click", function () {
        function said() {
          if (status) {
            status.textContent = button.getAttribute("data-copied") || "";
          }
        }
        field.select();
        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
          window.navigator.clipboard.writeText(field.value).then(said, function () {});
        } else if (typeof document.execCommand === "function" && document.execCommand("copy")) {
          // A back office served over plain http has no clipboard API.
          said();
        }
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window, document);

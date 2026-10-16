/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * A payment that went through but could not count is due to be read again
 * (Order\Recounts): this page, once loaded, asks the shop to read it
 * (controllers/front/recount.php, `window.wajubRecount.url`). The page is
 * on screen already and waits for nothing: a beacon, or a fetch kept alive
 * when the browser has no beacon. Loaded only while a payment is due.
 */
(function () {
  'use strict';

  var config = window.wajubRecount;
  if (!config || typeof config.url !== 'string' || config.url === '') {
    return;
  }

  function ask() {
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(config.url, '')) {
        return;
      }
    } catch (e) {
      // A browser that refuses the beacon: the fetch below.
    }
    try {
      if (window.fetch) {
        window.fetch(config.url, { method: 'POST', keepalive: true, credentials: 'same-origin' }).catch(function () {});
      }
    } catch (e) {
      // Nothing to tell the visitor: the scheduled task reads it too.
    }
  }

  if (document.readyState === 'complete') {
    ask();
  } else {
    window.addEventListener('load', ask, { once: true });
  }
}());

{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* The form PrestaShop submits on "Place order" with Mobile Money or Card,
   which the checkout's script (checkout-fields.js) takes over to place the
   order, then pay in the option's payment box (payment-info.tpl). Without
   the script, or when the payment form could not load, it posts no
   payment, and the payer pays on Wajub's hosted page. *}
<form class="wajub-payment-form" method="post" action="{$wajub.action|escape:'htmlall':'UTF-8'}" data-wajub-form="{$wajub.kind|escape:'htmlall':'UTF-8'}" novalidate>
  <input type="hidden" name="kind" value="{$wajub.kind|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="token" value="{$wajub.token|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="cart" value="{$wajub.cart|intval}">
  <input type="hidden" name="wajub_prepared" value="" data-wajub-prepared>
</form>

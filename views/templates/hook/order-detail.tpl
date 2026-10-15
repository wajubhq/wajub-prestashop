{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* The customer's order page: what was received and what is left of an
   order part of which is paid, or that it is not paid, with the button that
   pays it on Wajub's page (controllers/front/pay.php). A form: it starts a
   payment. *}
<section class="box wajub-order-pay" aria-labelledby="wajub-order-pay-title">
  <h3 class="wajub-order-pay__title" id="wajub-order-pay-title">{l s='Payment' d='Modules.Wajub.Shop'}</h3>
  {if $wajub_pay.rest}
    <p class="wajub-order-pay__lead">{l s='Part of your order is paid.' d='Modules.Wajub.Shop'} {$wajub_pay.line|escape:'htmlall':'UTF-8'}</p>
  {else}
    <p class="wajub-order-pay__lead">{l s='This order is not paid yet.' d='Modules.Wajub.Shop'}</p>
  {/if}
  <form method="post" action="{$wajub_pay.url|escape:'htmlall':'UTF-8'}" class="wajub-order-pay__form">
    <input type="hidden" name="token" value="{$wajub_pay.token|escape:'htmlall':'UTF-8'}">
    <button type="submit" class="btn btn-primary wajub-button">
      {if $wajub_pay.rest}{l s='Pay the rest' d='Modules.Wajub.Shop'}{else}{l s='Pay for this order' d='Modules.Wajub.Shop'}{/if}
    </button>
  </form>
</section>

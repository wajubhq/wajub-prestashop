{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* The order confirmation page of a Wajub order. While its payment is being
   confirmed (a Mobile Money approval on the payer's phone, often after they
   are back), a panel says what to do and follows the payment
   (order-status.js); once part of the order is paid, what is left, and
   "Pay the rest" (controllers/front/pay.php). Then the Wajub payment IDs,
   which the payer quotes to the shop. *}
<div class="wajub-payment-return">
  {if $wajub_return.panel == 'waiting'}
    <section class="wajub-order-status wajub-order-status--waiting" id="wajub-order-status-{$wajub_status.order_id|intval}" aria-labelledby="wajub-order-status-{$wajub_status.order_id|intval}-title" data-wajub-order-status data-status-url="{$wajub_status.status_url|escape:'htmlall':'UTF-8'}" data-release-url="{$wajub_status.release_url|escape:'htmlall':'UTF-8'}" data-pay-url="{$wajub_status.pay_url|escape:'htmlall':'UTF-8'}" data-token="{$wajub_status.token|escape:'htmlall':'UTF-8'}" data-timeout="{$wajub_status.timeout|intval}">
      <div class="wajub-order-status__icon" aria-hidden="true"><span class="wajub-spinner"></span></div>
      <div class="wajub-order-status__body">
        {if $wajub_status.phone}
          <h2 class="wajub-order-status__title" id="wajub-order-status-{$wajub_status.order_id|intval}-title" tabindex="-1" data-wajub-title>{l s='Confirm the payment on your phone' d='Modules.Wajub.Shop'}</h2>
          <p class="wajub-order-status__lead" data-wajub-lead>{l s='Your order is saved. To finish paying:' d='Modules.Wajub.Shop'}</p>
        {else}
          <h2 class="wajub-order-status__title" id="wajub-order-status-{$wajub_status.order_id|intval}-title" tabindex="-1" data-wajub-title>{l s='Confirming your payment…' d='Modules.Wajub.Shop'}</h2>
          <p class="wajub-order-status__lead" data-wajub-lead>{l s='Your order is saved. Keep this page open: it updates by itself once Wajub confirms the payment.' d='Modules.Wajub.Shop'}</p>
        {/if}
        {if $wajub_status.paid_so_far}
          <p class="wajub-order-status__paid">{$wajub_status.paid_so_far|escape:'htmlall':'UTF-8'}</p>
        {/if}
        {if $wajub_status.phone}
          <ol class="wajub-order-status__steps" data-wajub-steps>
            <li>{l s='Approve the payment request on your phone.' d='Modules.Wajub.Shop'}</li>
            <li>{l s='Enter your PIN to confirm it.' d='Modules.Wajub.Shop'}</li>
            <li>{l s='Keep this page open: it updates by itself once the payment is confirmed.' d='Modules.Wajub.Shop'}</li>
          </ol>
        {/if}
        <p class="wajub-order-status__live" role="status" aria-live="polite" data-wajub-live>{l s='Waiting for the payment to be confirmed…' d='Modules.Wajub.Shop'}</p>
        <p class="wajub-order-status__actions" data-wajub-actions hidden></p>
      </div>
    </section>
  {elseif $wajub_return.panel == 'partial'}
    <section class="wajub-order-status wajub-order-status--partial" id="wajub-order-status-{$wajub_status.order_id|intval}" aria-labelledby="wajub-order-status-{$wajub_status.order_id|intval}-title">
      <div class="wajub-order-status__body">
        <h2 class="wajub-order-status__title" id="wajub-order-status-{$wajub_status.order_id|intval}-title">{l s='Part of your order is paid' d='Modules.Wajub.Shop'}</h2>
        <p class="wajub-order-status__lead">{$wajub_status.paid_so_far|escape:'htmlall':'UTF-8'}</p>
        {if $wajub_status.can_pay}
          <form method="post" action="{$wajub_status.pay_url|escape:'htmlall':'UTF-8'}" class="wajub-order-status__actions">
            <input type="hidden" name="token" value="{$wajub_status.token|escape:'htmlall':'UTF-8'}">
            <button type="submit" class="btn btn-primary wajub-button">{l s='Pay the rest' d='Modules.Wajub.Shop'}</button>
          </form>
        {/if}
      </div>
    </section>
  {/if}

  {if $wajub_return.ids}
    <p class="wajub-payment-reference">
      <span class="wajub-payment-reference__label">{$wajub_return.label|escape:'htmlall':'UTF-8'}</span>
      {foreach from=$wajub_return.ids item=payment_id}
        <code class="wajub-payment-reference__value">{$payment_id|escape:'htmlall':'UTF-8'}</code>
        <button type="button" class="wajub-copy" data-wajub-copy="{$payment_id|escape:'htmlall':'UTF-8'}" hidden>{l s='Copy' d='Modules.Wajub.Shop'}<span class="wajub-sr-only"> {l s='the payment ID' d='Modules.Wajub.Shop'}</span></button>
      {/foreach}
      <span class="wajub-copy-feedback" role="status" aria-live="polite"></span>
    </p>
  {/if}
</div>

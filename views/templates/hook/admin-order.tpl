{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* The "Wajub payment" panel of the order page (Admin\OrderPanel). Its
   style and script come with the page's own (Wajub::hookActionAdminControllerSetMedia()). *}
<div class="card mt-2 wajub-order-panel" id="wajub-order-panel" data-wajub-order-panel data-order="{$wajub_order.order_id|intval}" data-ajax-url="{$wajub_order.ajax_url|escape:'htmlall':'UTF-8'}" data-token="{$wajub_order.token|escape:'htmlall':'UTF-8'}" data-refresh="{if $wajub_order.refresh && $wajub_order.can_check}1{else}0{/if}">
  <div class="card-header">
    <h3 class="card-header-title">
      <img class="wajub-order-panel__mark" src="{$module_dir|escape:'htmlall':'UTF-8'}views/img/wajub-mark.svg" alt="" width="20" height="20">
      {l s='Wajub payment' d='Modules.Wajub.Admin'}
    </h3>
  </div>
  <div class="card-body">
    {if $wajub_order.group}
      <p class="alert alert-info wajub-order-panel__group">{$wajub_order.group|escape:'htmlall':'UTF-8'}</p>
    {/if}
    {if $wajub_order.payments}
      {foreach from=$wajub_order.payments item=payment}
        <dl class="wajub-order-panel__payment">
          <div class="wajub-order-panel__row"><dt>{l s='Payment ID' d='Modules.Wajub.Admin'}</dt><dd><code>{$payment.id|escape:'htmlall':'UTF-8'}</code></dd></div>
          <div class="wajub-order-panel__row"><dt>{l s='Status' d='Modules.Wajub.Admin'}</dt><dd><span class="badge {if $payment.status == 'succeeded'}badge-success{elseif $payment.status == 'failed' || $payment.status == 'cancelled' || $payment.status == 'expired'}badge-danger{else}badge-warning{/if}">{$payment.status_label|escape:'htmlall':'UTF-8'}</span>{if $payment.uncounted} <span class="badge badge-warning">{l s='Not counted yet' d='Modules.Wajub.Admin'}</span>{/if}</dd></div>
          <div class="wajub-order-panel__row"><dt>{l s='Method' d='Modules.Wajub.Admin'}</dt><dd>{$payment.method|escape:'htmlall':'UTF-8'}<br><span class="text-muted">{$payment.mode|escape:'htmlall':'UTF-8'}</span></dd></div>
          <div class="wajub-order-panel__row"><dt>{l s='Amount' d='Modules.Wajub.Admin'}</dt><dd>{$payment.amount|escape:'htmlall':'UTF-8'}{if $payment.paid}<br><span class="text-muted">{if $payment.paid_more}{l s='Customer paid %amount%, fees included' sprintf=['%amount%' => $payment.paid] d='Modules.Wajub.Admin'}{else}{l s='Customer paid %amount%' sprintf=['%amount%' => $payment.paid] d='Modules.Wajub.Admin'}{/if}</span>{/if}{if $payment.counted}<br><span class="text-muted">{l s='Counted toward the order: %amount%' sprintf=['%amount%' => $payment.counted] d='Modules.Wajub.Admin'}{if $payment.not_counted} · {l s='not counted now: another environment or currency' d='Modules.Wajub.Admin'}{/if}</span>{/if}{if $payment.refunded}<br><span class="text-muted">{l s='%amount% refunded' sprintf=['%amount%' => $payment.refunded] d='Modules.Wajub.Admin'}</span>{/if}</dd></div>
          <div class="wajub-order-panel__row"><dt>{l s='Environment' d='Modules.Wajub.Admin'}</dt><dd><span class="badge {if $payment.environment == 'live'}badge-success{else}badge-warning{/if}">{$payment.environment_label|escape:'htmlall':'UTF-8'}</span></dd></div>
          {if $payment.reference}
            <div class="wajub-order-panel__row"><dt>{l s='Reference' d='Modules.Wajub.Admin'}</dt><dd>{$payment.reference|escape:'htmlall':'UTF-8'}</dd></div>
          {/if}
          <div class="wajub-order-panel__row"><dt></dt><dd><a href="{$payment.url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='View in the Wajub Dashboard' d='Modules.Wajub.Admin'}<span class="sr-only"> {l s='(opens in a new tab)' d='Modules.Wajub.Admin'}</span></a>{if $payment.environment != 'live'}<br><span class="text-muted">{l s='Sandbox payment: switch your Wajub Dashboard to Sandbox to open it.' d='Modules.Wajub.Admin'}</span>{/if}</dd></div>
        </dl>
      {/foreach}
    {else}
      <p class="text-muted">{l s='No Wajub payment was started for this order yet.' d='Modules.Wajub.Admin'}</p>
    {/if}

    {if $wajub_order.received}
      <dl class="wajub-order-panel__payment">
        <div class="wajub-order-panel__row"><dt>{l s='Received' d='Modules.Wajub.Admin'}</dt><dd>{$wajub_order.received|escape:'htmlall':'UTF-8'}</dd></div>
        <div class="wajub-order-panel__row"><dt>{l s='Left to pay' d='Modules.Wajub.Admin'}</dt><dd>{$wajub_order.left|escape:'htmlall':'UTF-8'}</dd></div>
      </dl>
    {/if}

    {if $wajub_order.refunds}
      <h4 class="wajub-order-panel__notes-title">{l s='Refunds' d='Modules.Wajub.Admin'}</h4>
      <ul class="wajub-order-panel__notes">
        {foreach from=$wajub_order.refunds item=refund}
          <li><code>{$refund.id|escape:'htmlall':'UTF-8'}</code> {$refund.amount|escape:'htmlall':'UTF-8'} · <span class="badge {if $refund.status == 'succeeded'}badge-success{elseif $refund.status == 'failed' || $refund.status == 'cancelled'}badge-danger{else}badge-warning{/if}">{$refund.status_label|escape:'htmlall':'UTF-8'}</span><br><span class="text-muted">{$refund.origin|escape:'htmlall':'UTF-8'} · {l s='payment %payment%' sprintf=['%payment%' => $refund.payment] d='Modules.Wajub.Admin'}{if $refund.slip} · {l s='credit slip %slip%' sprintf=['%slip%' => $refund.slip] d='Modules.Wajub.Admin'}{/if}</span></li>
        {/foreach}
      </ul>
    {/if}

    {if $wajub_order.notes}
      <h4 class="wajub-order-panel__notes-title">{l s='Notes' d='Modules.Wajub.Admin'}</h4>
      <ul class="wajub-order-panel__notes">
        {foreach from=$wajub_order.notes item=note}
          <li><span class="text-muted">{$note.date|escape:'htmlall':'UTF-8'}</span> {$note.message|escape:'htmlall':'UTF-8'}</li>
        {/foreach}
      </ul>
    {/if}

    {if $wajub_order.can_check && $wajub_order.checkable && $wajub_order.payments}
      <p class="wajub-order-panel__actions">
        <button type="button" class="btn btn-outline-secondary" data-wajub-check-status>{l s='Check status' d='Modules.Wajub.Admin'}</button>
        <span class="wajub-order-panel__result" role="status" aria-live="polite" data-wajub-check-result></span>
      </p>
    {/if}
  </div>
</div>

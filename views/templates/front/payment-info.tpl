{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* What shows under a Wajub option once it is chosen: the test badge, the
   description, then for Mobile Money and Card the payment box the
   checkout's script (checkout-fields.js) mounts Wajub's payment form in,
   and for Wajub's hosted page where the payer goes. No token of a
   payment, no total: the invoice country only, which the form does not
   ask again. *}
<div class="wajub-payment-info" data-wajub-option="{$wajub.kind|escape:'htmlall':'UTF-8'}"{if $wajub.logo_alt} data-wajub-logo-alt="{$wajub.logo_alt|escape:'htmlall':'UTF-8'}"{/if}>
  {if $wajub.test_mode}
    <p class="wajub-test-badge"><span class="wajub-test-badge__dot" aria-hidden="true"></span>{$wajub.test_badge|escape:'htmlall':'UTF-8'}</p>
  {/if}
  {if $wajub.description_lines}
    <p class="wajub-payment-info__description">{foreach from=$wajub.description_lines item=line name=lines}{$line|escape:'htmlall':'UTF-8'}{if !$smarty.foreach.lines.last}<br>{/if}{/foreach}</p>
  {/if}
  {if $wajub.component}
    <div class="wajub-payment-fields wajub-fields{if !$wajub.secured} wajub-fields--plain{/if}" data-wajub-fields="{$wajub.kind|escape:'htmlall':'UTF-8'}" data-wajub-component="{$wajub.component|escape:'htmlall':'UTF-8'}" data-wajub-country="{$wajub.country|escape:'htmlall':'UTF-8'}">
      <fieldset class="wajub-fields__group">
        <legend class="wajub-sr-only">{$wajub.words.legend|escape:'htmlall':'UTF-8'}</legend>
        <div class="wajub-fields__mount" id="wajub-fields-{$wajub.kind|escape:'htmlall':'UTF-8'}" data-wajub-fields-mount tabindex="-1" aria-describedby="wajub-fields-{$wajub.kind|escape:'htmlall':'UTF-8'}-error"></div>
      </fieldset>
      <p class="wajub-fields__error" id="wajub-fields-{$wajub.kind|escape:'htmlall':'UTF-8'}-error" role="alert" data-wajub-fields-error></p>
      <p class="wajub-fields__status" role="status" aria-live="polite" data-wajub-fields-status></p>
      <div class="wajub-fields__actions" data-wajub-fields-actions hidden></div>
      <noscript><p class="wajub-fields__hint">{$wajub.words.hostedHint|escape:'htmlall':'UTF-8'}</p></noscript>
      {if $wajub.secured}
        <p class="wajub-secured"><span class="wajub-secured__icon" aria-hidden="true"></span><span>{$wajub.secured|escape:'htmlall':'UTF-8'}</span></p>
      {/if}
    </div>
  {else}
    <p class="wajub-fields__hint">{$wajub.words.hostedHint|escape:'htmlall':'UTF-8'}</p>
    {if $wajub.secured}
      <p class="wajub-secured"><span class="wajub-secured__icon" aria-hidden="true"></span><span>{$wajub.secured|escape:'htmlall':'UTF-8'}</span></p>
    {/if}
  {/if}
</div>

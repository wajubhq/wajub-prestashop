{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* On every page of the back office: a key "Connect with Wajub" created that
   a shop pays with, and that expires within two weeks, or has. The shop it
   is for is named in a multistore. Its link opens the module's settings in
   that shop's context, where "Connect with Wajub" gives a new key. *}
{foreach from=$wajub_key_notices item=notice}
  <div class="alert {if $notice.expired}alert-danger{else}alert-warning{/if} wajub-key-notice" role="{if $notice.expired}alert{else}status{/if}">
    <p class="alert-text">
      <strong>{if $notice.expired}{l s='Your Wajub key has expired.' d='Modules.Wajub.Admin'}{else}{l s='Your Wajub key expires soon.' d='Modules.Wajub.Admin'}{/if}</strong>
      {if $notice.scope}<span class="wajub-key-notice__scope">({$notice.scope|escape:'htmlall':'UTF-8'})</span>{/if}
      {$notice.text|escape:'htmlall':'UTF-8'}
      <a href="{$notice.url|escape:'htmlall':'UTF-8'}">{l s='Connect with Wajub again' d='Modules.Wajub.Admin'}</a>
    </p>
  </div>
{/foreach}

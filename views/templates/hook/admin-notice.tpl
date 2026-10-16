{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* One notice of the back office (Wajub::adminNotices()), inside the alert
   admin-notices.tpl draws, or the one a PrestaShop 9 legacy page draws for
   its warnings: what is wrong, what fixes it, the scheduled task's address
   to copy, and "Hide for 7 days" for a notice that can be hidden. *}
<p class="alert-text wajub-notice__text">
  <strong>{$wajub_notice.title|escape:'htmlall':'UTF-8'}</strong>
  {if $wajub_notice.scope}<span class="wajub-key-notice__scope">({$wajub_notice.scope|escape:'htmlall':'UTF-8'})</span>{/if}
  {$wajub_notice.text|escape:'htmlall':'UTF-8'}
</p>
{if $wajub_notice.copy}
  <div class="wajub-notice__copy" data-wajub-notice-copy-box>
    <label class="wajub-notice__label" for="wajub-notice-{$wajub_notice.id|escape:'htmlall':'UTF-8'}-address">{l s='Scheduled task address (keep it private)' d='Modules.Wajub.Admin'}</label>
    <span class="wajub-notice__field-line">
      <input type="text" class="form-control wajub-notice__field" id="wajub-notice-{$wajub_notice.id|escape:'htmlall':'UTF-8'}-address" value="{$wajub_notice.copy|escape:'htmlall':'UTF-8'}" readonly spellcheck="false" data-wajub-notice-field>
      <button type="button" class="btn btn-default btn-sm" hidden data-wajub-notice-copy data-copied="{l s='Copied.' d='Modules.Wajub.Admin'}">{l s='Copy' d='Modules.Wajub.Admin'}</button>
    </span>
    <span class="wajub-notice__copied" role="status" aria-live="polite" data-wajub-notice-copied></span>
  </div>
{/if}
{if $wajub_notice.links || $wajub_notice.hide_url}
  <p class="wajub-notice__actions">
    {foreach from=$wajub_notice.links item=link}
      <a class="alert-link wajub-notice__action" href="{$link.url|escape:'htmlall':'UTF-8'}">{$link.label|escape:'htmlall':'UTF-8'}</a>
    {/foreach}
    {if $wajub_notice.hide_url}
      <a class="wajub-notice__hide" href="{$wajub_notice.hide_url|escape:'htmlall':'UTF-8'}">{l s='Hide for 7 days' d='Modules.Wajub.Admin'}</a>
    {/if}
  </p>
{/if}

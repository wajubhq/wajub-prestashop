{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* One notice of admin-key-notice.tpl, as a warning of a PrestaShop 9 legacy
   page, which draws it in its own alert: a key "Connect with Wajub" created
   that a shop pays with, and that expires within two weeks, or has. *}
<strong>{if $wajub_key_notice.expired}{l s='Your Wajub key has expired.' d='Modules.Wajub.Admin'}{else}{l s='Your Wajub key expires soon.' d='Modules.Wajub.Admin'}{/if}</strong>
{if $wajub_key_notice.scope}<span class="wajub-key-notice__scope">({$wajub_key_notice.scope|escape:'htmlall':'UTF-8'})</span>{/if}
{$wajub_key_notice.text|escape:'htmlall':'UTF-8'}
<a href="{$wajub_key_notice.url|escape:'htmlall':'UTF-8'}">{l s='Connect with Wajub again' d='Modules.Wajub.Admin'}</a>

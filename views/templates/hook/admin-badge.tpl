{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* In the back office's header, while the shop takes test payments
   (Wajub::hookDisplayBackOfficeTop()): Live, the normal state, shows
   nothing. *}
<a class="wajub-env-badge" href="{$wajub_badge.url|escape:'htmlall':'UTF-8'}" title="{l s='Wajub takes test payments: no real money moves. Open the settings to go live.' d='Modules.Wajub.Admin'}" data-wajub-env-badge>{l s='Wajub · Sandbox' d='Modules.Wajub.Admin'}</a>

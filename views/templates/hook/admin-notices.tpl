{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* The module's notices (Wajub::adminNotices()), most serious first: on
   every page of the back office that has displayAdminAfterHeader, and at
   the top of the module's settings page. An error is an alert, the others
   are said politely. *}
{foreach from=$wajub_notices item=notice}
  <div class="alert {if $notice.type == 'error'}alert-danger{elseif $notice.type == 'warning'}alert-warning{else}alert-info{/if} wajub-notice{if $notice.key} wajub-key-notice{/if}" role="{if $notice.type == 'error'}alert{else}status{/if}" data-wajub-notice="{$notice.id|escape:'htmlall':'UTF-8'}">
    {include file="./admin-notice.tpl" wajub_notice=$notice}
  </div>
{/foreach}

{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="wajub-admin" id="wajub-admin" data-wajub-admin>
  <div class="panel wajub-header">
    <img class="wajub-header-logo" src="{$wajub.logo_uri|escape:'htmlall':'UTF-8'}" width="48" height="48" alt="">
    <div class="wajub-header-text">
      <h2 class="wajub-header-title">{l s='Wajub' d='Modules.Wajub.Admin'} <small>{$wajub.version|escape:'htmlall':'UTF-8'}</small></h2>
      <p class="wajub-header-lead">{l s='Mobile Money, cards and other payment methods, with Wajub.' d='Modules.Wajub.Admin'}</p>
      {if $wajub.scope_label}
        <p class="wajub-header-scope">{$wajub.scope_label|escape:'htmlall':'UTF-8'}</p>
      {/if}
    </div>
    <p class="wajub-header-environment">
      {if $wajub.is_live}
        <span class="wajub-badge wajub-badge--live">{l s='Live: real payments' d='Modules.Wajub.Admin'}</span>
      {else}
        <span class="wajub-badge wajub-badge--sandbox">{l s='Sandbox: test payments' d='Modules.Wajub.Admin'}</span>
      {/if}
    </p>
  </div>

  {if $wajub.key_notices}
    {include file="../hook/admin-key-notice.tpl" wajub_key_notices=$wajub.key_notices}
  {/if}

  {foreach from=$wajub.notices item=notice}
    <div class="alert alert-{if $notice.type == 'error'}danger{elseif $notice.type == 'warning'}warning{elseif $notice.type == 'success'}success{else}info{/if}" role="{if $notice.type == 'error'}alert{else}status{/if}">
      {$notice.text|escape:'htmlall':'UTF-8'}
    </div>
  {/foreach}

  {if !$wajub.can_edit}
    <div class="alert alert-info" role="status">{l s='You can see these settings, but not change them.' d='Modules.Wajub.Admin'}</div>
  {/if}

  <ul class="nav nav-tabs wajub-tabs" role="tablist" data-wajub-tabs>
    {foreach from=['connection', 'methods', 'appearance', 'webhooks'] item=tab}
      <li class="nav-item{if $wajub.active_tab == $tab} active{/if}" role="presentation">
        <a href="{$wajub.form_action|escape:'htmlall':'UTF-8'}&amp;wajub_tab={$tab|escape:'htmlall':'UTF-8'}" class="nav-link{if $wajub.active_tab == $tab} active{/if}" id="wajub-tab-{$tab|escape:'htmlall':'UTF-8'}" role="tab" aria-controls="wajub-panel-{$tab|escape:'htmlall':'UTF-8'}" aria-selected="{if $wajub.active_tab == $tab}true{else}false{/if}" {if $wajub.active_tab != $tab}tabindex="-1"{/if} data-wajub-tab="{$tab|escape:'htmlall':'UTF-8'}">
          {if $tab == 'connection'}{l s='Connection' d='Modules.Wajub.Admin'}{elseif $tab == 'methods'}{l s='Payment methods' d='Modules.Wajub.Admin'}{elseif $tab == 'appearance'}{l s='Appearance' d='Modules.Wajub.Admin'}{else}{l s='Webhooks' d='Modules.Wajub.Admin'}{/if}
        </a>
      </li>
    {/foreach}
  </ul>

  <div class="wajub-panels">
    <section class="wajub-panel" id="wajub-panel-connection" role="tabpanel" aria-labelledby="wajub-tab-connection" {if $wajub.active_tab != 'connection'}hidden{/if}>
      {include file="./partials/connection.tpl"}
    </section>
    <section class="wajub-panel" id="wajub-panel-methods" role="tabpanel" aria-labelledby="wajub-tab-methods" {if $wajub.active_tab != 'methods'}hidden{/if}>
      {include file="./partials/methods.tpl"}
    </section>
    <section class="wajub-panel" id="wajub-panel-appearance" role="tabpanel" aria-labelledby="wajub-tab-appearance" {if $wajub.active_tab != 'appearance'}hidden{/if}>
      {include file="./partials/appearance.tpl"}
    </section>
    <section class="wajub-panel" id="wajub-panel-webhooks" role="tabpanel" aria-labelledby="wajub-tab-webhooks" {if $wajub.active_tab != 'webhooks'}hidden{/if}>
      {include file="./partials/webhooks.tpl"}
    </section>
  </div>
</div>

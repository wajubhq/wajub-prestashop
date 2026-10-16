{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{if $wajub.multistore.on && !$wajub.scope_all}
  <div class="panel wajub-scope-panel">
    <h3 class="panel-heading">{if $wajub.multistore.group}{l s='This group of shops' d='Modules.Wajub.Admin'}{else}{l s='This shop' d='Modules.Wajub.Admin'}{/if}</h3>
    {if $wajub.multistore.own}
      <p>
        {if $wajub.multistore.group}
          {l s='This group has its own Wajub connection: its own environment, keys or webhook. The other settings follow the shop selector too.' d='Modules.Wajub.Admin'}
        {else}
          {l s='This shop has its own Wajub connection: its own environment, keys or webhook. The other settings follow the shop selector too.' d='Modules.Wajub.Admin'}
        {/if}
      </p>
      {if $wajub.multistore.environment_saved_for}
        <p class="help-block">{l s='Environment saved for: %scope%' sprintf=['%scope%' => $wajub.multistore.environment_saved_for] d='Modules.Wajub.Admin'}</p>
      {/if}
      {if $wajub.can_edit}
        <form method="post" action="{$wajub.form_action|escape:'htmlall':'UTF-8'}" class="wajub-inline-form" data-wajub-inherit-form>
          <input type="hidden" name="wajub_form" value="inherit">
          <input type="hidden" name="wajub_token" value="{$wajub.csrf_token|escape:'htmlall':'UTF-8'}">
          <button type="submit" class="btn btn-default" name="submitWajubForm" value="1" data-wajub-confirm="{if $wajub.multistore.group}{l s='Use the connection saved for %scope%? The environment, keys and webhook saved for this group are forgotten here; they stay in your Wajub Dashboard, from where the orders already paid with them are refunded.' sprintf=['%scope%' => $wajub.multistore.parent_in_text] d='Modules.Wajub.Admin'}{else}{l s='Use the connection saved for %scope%? The environment, keys and webhook saved for this shop are forgotten here; they stay in your Wajub Dashboard, from where the orders already paid with them are refunded.' sprintf=['%scope%' => $wajub.multistore.parent_in_text] d='Modules.Wajub.Admin'}{/if}">
            {l s='Use the connection saved for %scope%' sprintf=['%scope%' => $wajub.multistore.parent_in_text] d='Modules.Wajub.Admin'}
          </button>
        </form>
      {/if}
    {else}
      <p>
        {if $wajub.multistore.group}
          {l s='This group uses the Wajub connection saved for %scope%. Connecting it here, or pasting a key, gives it its own.' sprintf=['%scope%' => $wajub.multistore.parent_in_text] d='Modules.Wajub.Admin'}
        {else}
          {l s='This shop uses the Wajub connection saved for %scope%. Connecting it here, or pasting a key, gives it its own.' sprintf=['%scope%' => $wajub.multistore.parent_in_text] d='Modules.Wajub.Admin'}
        {/if}
      </p>
    {/if}
  </div>
{/if}

<div class="panel">
  <h3 class="panel-heading">{l s='Connection' d='Modules.Wajub.Admin'}</h3>

  <div class="wajub-connection" data-wajub-connection data-status="{$wajub.connection.page_status|escape:'htmlall':'UTF-8'}">
    <span class="wajub-dot wajub-dot--{$wajub.connection.tone|escape:'htmlall':'UTF-8'}" aria-hidden="true" data-wajub-connection-dot></span>
    <div class="wajub-connection-text" role="status" aria-live="polite" data-wajub-connection-text>
      <strong class="wajub-connection-label">{$wajub.connection.label|escape:'htmlall':'UTF-8'}</strong>
      <span class="wajub-connection-detail">{$wajub.connection.detail|escape:'htmlall':'UTF-8'}</span>
    </div>
    {if $wajub.connection.can_test && $wajub.can_edit}
      <button type="button" class="btn btn-default" data-wajub-test-connection>{l s='Test connection' d='Modules.Wajub.Admin'}</button>
    {/if}
  </div>
</div>

<div class="panel wajub-pairing" data-wajub-pairing>
  <h3 class="panel-heading">{l s='Connect with Wajub' d='Modules.Wajub.Admin'}</h3>
  <p class="wajub-lead">{l s='Approve this shop in your Wajub Dashboard and Wajub creates its key. Nothing to copy.' d='Modules.Wajub.Admin'}</p>

  <div data-wajub-pairing-idle {if $wajub.pairing_pending}hidden{/if}>
    <ul class="wajub-environments">
      {foreach from=$wajub.environments item=env}
        <li class="wajub-environment">
          <div class="wajub-environment-text">
            <strong class="wajub-environment-name">{$env.label|escape:'htmlall':'UTF-8'}</strong>
            {if $env.connected_on}
              <span class="wajub-environment-state">{$env.connected_on|escape:'htmlall':'UTF-8'}</span>
            {elseif $env.has_key}
              <span class="wajub-environment-state">{l s='Key saved' d='Modules.Wajub.Admin'} ({$env.masked_key|escape:'htmlall':'UTF-8'})</span>
            {elseif $env.key_unreadable}
              <span class="wajub-environment-state wajub-text-error">{l s='The saved key can no longer be read: connect again.' d='Modules.Wajub.Admin'}</span>
            {else}
              <span class="wajub-environment-state">{l s='Not connected' d='Modules.Wajub.Admin'}</span>
            {/if}
            {if $env.saved_for}
              <span class="wajub-environment-detail">{l s='Saved for: %scope%' sprintf=['%scope%' => $env.saved_for] d='Modules.Wajub.Admin'}</span>
            {/if}
            {if $env.business}
              <span class="wajub-environment-detail">{l s='Wajub account: %business%' sprintf=['%business%' => $env.business] d='Modules.Wajub.Admin'}</span>
            {/if}
            {if $env.key_type == 'restricted'}
              <span class="wajub-environment-detail">
                {if $env.permissions}
                  {l s='Restricted key: %permissions%' sprintf=['%permissions%' => $env.permissions] d='Modules.Wajub.Admin'}
                {else}
                  {l s='Restricted key' d='Modules.Wajub.Admin'}
                {/if}
              </span>
            {elseif $env.key_type == 'private'}
              <span class="wajub-environment-detail">{l s='Full access key' d='Modules.Wajub.Admin'}</span>
            {/if}
            {if $env.expiry}
              <span class="wajub-environment-detail{if $env.expiry_state} wajub-text-{if $env.expiry_state == 'expired'}error{else}warning{/if}{/if}">{$env.expiry|escape:'htmlall':'UTF-8'}</span>
            {/if}
          </div>
          {if $wajub.can_edit}
            <button type="button" class="btn {if $env.environment == 'sandbox' && !$env.has_key}btn-primary{else}btn-default{/if}" data-wajub-pairing-start="{$env.environment|escape:'htmlall':'UTF-8'}">{$env.button|escape:'htmlall':'UTF-8'}</button>
          {/if}
        </li>
      {/foreach}
    </ul>
    <p class="help-block">
      {l s='Only the owner, an administrator or a developer of your Wajub account can approve, after entering a security code.' d='Modules.Wajub.Admin'}
      {l s='Wajub starts on Sandbox: choose the environment on its page.' d='Modules.Wajub.Admin'}
      {$wajub.no_expiry_advice|escape:'htmlall':'UTF-8'}
      {l s='The module needs access to payments, refunds and webhooks.' d='Modules.Wajub.Admin'}
    </p>
  </div>

  <div class="wajub-pairing-pending" data-wajub-pairing-pending {if !$wajub.pairing_pending}hidden{/if}>
    <p class="wajub-pairing-title" tabindex="-1" data-wajub-pairing-title><strong>{l s='Waiting for your approval in Wajub' d='Modules.Wajub.Admin'}</strong></p>
    <p class="wajub-pairing-code-line">
      <span class="sr-only">{l s='Code' d='Modules.Wajub.Admin'}</span>
      <strong class="wajub-pairing-code" translate="no" data-wajub-pairing-code>{if $wajub.pairing_pending}{$wajub.pairing_pending.code|escape:'htmlall':'UTF-8'}{/if}</strong>
    </p>
    <p data-wajub-pairing-instructions>{if $wajub.pairing_pending}{l s='In the Wajub tab, check that the code is %code%, choose the environment, then click Authorize. Keep this page open.' sprintf=['%code%' => $wajub.pairing_pending.code] d='Modules.Wajub.Admin'}{/if}</p>
    <p class="alert alert-warning" role="alert" data-wajub-pairing-blocked hidden>{l s='Your browser blocked the new tab. Open Wajub to approve.' d='Modules.Wajub.Admin'}</p>
    <p class="wajub-pairing-links">
      <a class="btn btn-default" href="{if $wajub.pairing_pending}{$wajub.pairing_pending.confirm_url|escape:'htmlall':'UTF-8'}{else}{$wajub.dashboard_url|escape:'htmlall':'UTF-8'}{/if}" target="_blank" rel="noopener noreferrer" data-wajub-pairing-link>
        {l s='Open Wajub to approve' d='Modules.Wajub.Admin'}<span class="sr-only"> {l s='(opens in a new tab)' d='Modules.Wajub.Admin'}</span>
      </a>
      <button type="button" class="btn btn-link" data-wajub-pairing-cancel>{l s='Cancel' d='Modules.Wajub.Admin'}</button>
    </p>
    <p class="help-block" role="status" aria-live="polite" data-wajub-pairing-countdown></p>
  </div>

  <div class="wajub-pairing-result" tabindex="-1" data-wajub-pairing-result hidden></div>
  <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-wajub-pairing-live></div>
</div>

<form method="post" action="{$wajub.form_action|escape:'htmlall':'UTF-8'}" class="form-horizontal" data-wajub-connection-form autocomplete="off">
  <input type="hidden" name="wajub_form" value="connection">
  <input type="hidden" name="wajub_token" value="{$wajub.csrf_token|escape:'htmlall':'UTF-8'}">

  <div class="panel">
    <h3 class="panel-heading">{l s='Environment' d='Modules.Wajub.Admin'}</h3>
    {* "Wajub takes no payments" is above the tabs, with the page's other notices. *}
    {if $wajub.plain_http}
      <div class="alert alert-warning" data-wajub-plain-http>{$wajub.plain_http|escape:'htmlall':'UTF-8'}</div>
    {/if}
    <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
      <legend class="wajub-legend">{l s='Which payments this shop takes' d='Modules.Wajub.Admin'}</legend>
      <div class="radio">
        <label for="wajub-environment-sandbox">
          <input type="radio" id="wajub-environment-sandbox" name="environment" value="sandbox" {if $wajub.environment == 'sandbox'}checked{/if} aria-describedby="wajub-environment-sandbox-help">
          <strong>{l s='Sandbox' d='Modules.Wajub.Admin'}</strong>
        </label>
        <p class="help-block" id="wajub-environment-sandbox-help">{l s='Test payments, with your sandbox key. No real money moves.' d='Modules.Wajub.Admin'}</p>
      </div>
      <div class="radio">
        <label for="wajub-environment-live">
          <input type="radio" id="wajub-environment-live" name="environment" value="live" {if $wajub.environment == 'live'}checked{/if} aria-describedby="wajub-environment-live-help" data-wajub-live-radio data-was-live="{if $wajub.environment == 'live'}1{else}0{/if}">
          <strong>{l s='Live' d='Modules.Wajub.Admin'}</strong>
        </label>
        <p class="help-block" id="wajub-environment-live-help">{l s='Real payments, with your live key. Each environment pays with its own key only.' d='Modules.Wajub.Admin'}</p>
      </div>
    </fieldset>
  </div>

  <div class="panel">
    <details class="wajub-details" {if !$wajub.environments.sandbox.has_key && !$wajub.environments.live.has_key}{else}open{/if}>
      <summary class="wajub-summary">{l s='Paste keys instead' d='Modules.Wajub.Admin'}</summary>
      <p class="help-block">
        {l s='Copy your secret keys from the Wajub Dashboard, under Developers, API keys. Each key is checked with Wajub before it is saved, and is never shown again here.' d='Modules.Wajub.Admin'}
        <a href="{$wajub.api_keys_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Open the API keys page' d='Modules.Wajub.Admin'}<span class="sr-only"> {l s='(opens in a new tab)' d='Modules.Wajub.Admin'}</span></a>
      </p>
      <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
        {foreach from=$wajub.environments item=env}
          <div class="form-group">
            <label class="control-label col-lg-3" for="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-key">
              {if $env.environment == 'live'}{l s='Live secret key' d='Modules.Wajub.Admin'}{else}{l s='Sandbox secret key' d='Modules.Wajub.Admin'}{/if}
            </label>
            <div class="col-lg-9">
              <input type="password" class="form-control" id="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-key" name="{$env.environment|escape:'htmlall':'UTF-8'}_key" value="" autocomplete="new-password" spellcheck="false" placeholder="{if $env.masked_key}{$env.masked_key|escape:'htmlall':'UTF-8'}{else}{$env.expected_prefix|escape:'htmlall':'UTF-8'}…{/if}" aria-describedby="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-key-help">
              <p class="help-block" id="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-key-help">
                {if $env.masked_key}
                  {l s='Saved: %key%. Leave empty to keep it.' sprintf=['%key%' => $env.masked_key] d='Modules.Wajub.Admin'}
                {else}
                  {l s='Starts with %prefix%' sprintf=['%prefix%' => $env.expected_prefix] d='Modules.Wajub.Admin'}
                {/if}
              </p>
              {if $env.masked_key || $env.key_unreadable}
                <div class="checkbox">
                  <label for="wajub-remove-{$env.environment|escape:'htmlall':'UTF-8'}-key">
                    <input type="checkbox" id="wajub-remove-{$env.environment|escape:'htmlall':'UTF-8'}-key" name="remove_{$env.environment|escape:'htmlall':'UTF-8'}_key" value="1">
                    {l s='Remove this key' d='Modules.Wajub.Admin'}
                  </label>
                </div>
              {/if}
            </div>
          </div>
        {/foreach}
      </fieldset>
    </details>
  </div>

  <div class="panel">
    <h3 class="panel-heading">{l s='Troubleshooting' d='Modules.Wajub.Admin'}</h3>
    <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
      <div class="checkbox">
        <label for="wajub-debug-log">
          <input type="checkbox" id="wajub-debug-log" name="debug_log" value="1" {if $wajub.debug_log}checked{/if} aria-describedby="wajub-debug-log-help">
          {l s='Write a detailed log' d='Modules.Wajub.Admin'}
        </label>
      </div>
      <p class="help-block" id="wajub-debug-log-help">{l s='In Advanced Parameters, Logs. Errors are always written; keys and signing secrets never are.' d='Modules.Wajub.Admin'}</p>
    </fieldset>
    {if $wajub.can_edit}
      <div class="panel-footer">
        <button type="submit" class="btn btn-default pull-right" name="submitWajubForm" value="1"><i class="process-icon-save" aria-hidden="true"></i> {l s='Save' d='Modules.Wajub.Admin'}</button>
      </div>
    {/if}
  </div>
</form>

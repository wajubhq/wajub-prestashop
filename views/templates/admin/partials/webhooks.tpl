{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3 class="panel-heading">{l s='Webhook' d='Modules.Wajub.Admin'}</h3>
  <p class="help-block">{l s='Wajub tells this shop when a payment succeeds, fails or is refunded, even when the customer closes the page. Without the webhook, orders paid on a phone may stay awaiting payment.' d='Modules.Wajub.Admin'}</p>

  <div class="form-group wajub-webhook-url">
    <label class="control-label" for="wajub-webhook-url">{l s='Webhook address of this shop' d='Modules.Wajub.Admin'}</label>
    <div class="input-group">
      <input type="text" class="form-control" id="wajub-webhook-url" value="{$wajub.webhook.url|escape:'htmlall':'UTF-8'}" readonly spellcheck="false">
      <span class="input-group-btn">
        <button type="button" class="btn btn-default" data-wajub-copy="#wajub-webhook-url">{l s='Copy' d='Modules.Wajub.Admin'}</button>
      </span>
    </div>
    <p class="help-block" role="status" aria-live="polite" data-wajub-copy-status></p>
  </div>

  {if $wajub.webhook.inherited_from}
    <p class="alert alert-info" role="status">{l s='Wajub’s deliveries reach this shop through the webhook saved for %scope%, whose last delivery shows below. Registering here gives this shop its own webhook, at the address above.' sprintf=['%scope%' => $wajub.webhook.inherited_in_text] d='Modules.Wajub.Admin'}</p>
  {/if}

  <h4 class="wajub-subheading">{l s='Last delivery' d='Modules.Wajub.Admin'}</h4>
  {if $wajub.webhook.state == 'rejected' && $wajub.webhook.rejected}
    <div class="alert alert-danger" role="alert">
      <strong>{l s='Wajub’s deliveries are refused.' d='Modules.Wajub.Admin'}</strong>
      {l s='The last one, %time% ago, could not be verified.' sprintf=['%time%' => $wajub.webhook.rejected.ago] d='Modules.Wajub.Admin'}
      {$wajub.webhook.rejected.guidance|escape:'htmlall':'UTF-8'}
    </div>
  {elseif $wajub.webhook.received}
    <p class="wajub-health wajub-health--ok">
      <span class="wajub-dot wajub-dot--success" aria-hidden="true"></span>
      {l s='Last delivery received %time% ago: %event%, %environment%.' sprintf=['%time%' => $wajub.webhook.received.ago, '%event%' => $wajub.webhook.received.event, '%environment%' => $wajub.webhook.received.environment] d='Modules.Wajub.Admin'}
    </p>
  {else}
    <p class="wajub-health">
      <span class="wajub-dot wajub-dot--neutral" aria-hidden="true"></span>
      {l s='No delivery received yet. The first comes with the first payment.' d='Modules.Wajub.Admin'}
    </p>
  {/if}
  {if $wajub.webhook.unverified}
    <p class="help-block">{$wajub.webhook.unverified|escape:'htmlall':'UTF-8'}</p>
  {/if}

  <h4 class="wajub-subheading">{l s='Registered with Wajub' d='Modules.Wajub.Admin'}</h4>
  <ul class="wajub-environments">
    {foreach from=$wajub.webhook.environments item=env}
      <li class="wajub-environment">
        <div class="wajub-environment-text">
          <strong class="wajub-environment-name">{$env.label|escape:'htmlall':'UTF-8'}</strong>
          {if !$env.has_key}
            <span class="wajub-environment-state">{l s='No key saved: connect this environment first.' d='Modules.Wajub.Admin'}</span>
          {elseif $env.endpoint_id}
            <span class="wajub-environment-state">{l s='Registered on %date%' sprintf=['%date%' => $env.registered_on] d='Modules.Wajub.Admin'}</span>
          {else}
            <span class="wajub-environment-state">{l s='Not registered by this module' d='Modules.Wajub.Admin'}</span>
          {/if}
          {if $env.masked_secret}
            <span class="wajub-environment-detail">{l s='Signing secret saved: %secret%' sprintf=['%secret%' => $env.masked_secret] d='Modules.Wajub.Admin'}</span>
          {elseif $env.secret_unreadable}
            <span class="wajub-environment-detail wajub-text-error">{l s='The saved signing secret can no longer be read: register the webhook again.' d='Modules.Wajub.Admin'}</span>
          {elseif $env.has_key}
            <span class="wajub-environment-detail wajub-text-warning">{l s='No signing secret saved: this environment’s deliveries are refused.' d='Modules.Wajub.Admin'}</span>
          {/if}
          {if $env.saved_for}
            <span class="wajub-environment-detail">{l s='Saved for: %scope%' sprintf=['%scope%' => $env.saved_for] d='Modules.Wajub.Admin'}</span>
          {/if}
          {if $env.moved}
            <span class="wajub-environment-detail wajub-text-warning">{l s='The shop’s webhook address changed since it was registered. Register it again.' d='Modules.Wajub.Admin'}</span>
          {/if}
          {if $env.has_key && $env.local_problem == 'private_url'}
            <span class="wajub-environment-detail wajub-text-warning">{l s='Wajub cannot reach this address: it is local or private. Register the webhook once the shop is online.' d='Modules.Wajub.Admin'}</span>
          {elseif $env.has_key && $env.local_problem == 'https_required'}
            <span class="wajub-environment-detail wajub-text-warning">{l s='Live webhooks need an https:// address. Turn on SSL for this shop.' d='Modules.Wajub.Admin'}</span>
          {/if}
        </div>
      </li>
    {/foreach}
  </ul>

  {if $wajub.can_edit}
    <p class="wajub-actions">
      <button type="button" class="btn btn-primary" data-wajub-register-webhooks>{l s='Register automatically' d='Modules.Wajub.Admin'}</button>
      <button type="button" class="btn btn-default" data-wajub-register-webhooks data-rotate="1">{l s='Get a new signing secret' d='Modules.Wajub.Admin'}</button>
    </p>
    <div class="wajub-webhook-results" tabindex="-1" role="status" aria-live="polite" data-wajub-webhook-results></div>
  {/if}
</div>

<form method="post" action="{$wajub.form_action|escape:'htmlall':'UTF-8'}" class="form-horizontal" autocomplete="off">
  <input type="hidden" name="wajub_form" value="webhooks">
  <input type="hidden" name="wajub_token" value="{$wajub.csrf_token|escape:'htmlall':'UTF-8'}">
  <div class="panel">
    <details class="wajub-details">
      <summary class="wajub-summary">{l s='Set the webhook up by hand' d='Modules.Wajub.Admin'}</summary>
      {if $wajub.webhook.local}
        {* A local address: no endpoint in the Dashboard, the Wajub CLI forwards the Sandbox events. *}
        <p class="help-block" data-wajub-webhook-local>{l s='This shop’s address is local: Wajub cannot reach it, and the Wajub Dashboard takes no endpoint at it. Until the shop is online, receive its Sandbox events on this computer with the Wajub CLI:' d='Modules.Wajub.Admin'}</p>
        <ol class="wajub-steps">
          <li>
            <span id="wajub-listen-command-label">{l s='Run this command, and keep it running while you test:' d='Modules.Wajub.Admin'}</span>
            <div class="form-group wajub-listen-command">
              <div class="input-group">
                <input type="text" class="form-control" id="wajub-listen-command" value="{$wajub.webhook.listen_command|escape:'htmlall':'UTF-8'}" readonly spellcheck="false" aria-labelledby="wajub-listen-command-label">
                <span class="input-group-btn">
                  <button type="button" class="btn btn-default" data-wajub-copy="#wajub-listen-command">{l s='Copy' d='Modules.Wajub.Admin'}</button>
                </span>
              </div>
              <p class="help-block" role="status" aria-live="polite" data-wajub-copy-status></p>
            </div>
          </li>
          <li>{l s='Paste the secret that “wajub listen --print-secret” shows into Sandbox signing secret below.' d='Modules.Wajub.Admin'}</li>
        </ol>
      {else}
        <ol class="wajub-steps">
          <li>
            {l s='In the Wajub Dashboard, under Developers, Webhooks, add an endpoint with the address above, in the environment you use.' d='Modules.Wajub.Admin'}
            <a href="{$wajub.webhooks_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Open the webhooks page' d='Modules.Wajub.Admin'}<span class="sr-only"> {l s='(opens in a new tab)' d='Modules.Wajub.Admin'}</span></a>
          </li>
          <li>{l s='Subscribe it to these events: %events%.' sprintf=['%events%' => $wajub.webhook.events] d='Modules.Wajub.Admin'}</li>
          <li>{l s='Paste the signing secret Wajub shows for it below.' d='Modules.Wajub.Admin'}</li>
        </ol>
      {/if}
      <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
        {foreach from=$wajub.webhook.environments item=env}
          <div class="form-group">
            <label class="control-label col-lg-3" for="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-secret">
              {if $env.environment == 'live'}{l s='Live signing secret' d='Modules.Wajub.Admin'}{else}{l s='Sandbox signing secret' d='Modules.Wajub.Admin'}{/if}
            </label>
            <div class="col-lg-9">
              <input type="password" class="form-control" id="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-secret" name="{$env.environment|escape:'htmlall':'UTF-8'}_secret" value="" autocomplete="new-password" spellcheck="false" placeholder="{if $env.masked_secret}{$env.masked_secret|escape:'htmlall':'UTF-8'}{else}{$env.expected_prefix|escape:'htmlall':'UTF-8'}…{/if}" aria-describedby="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-secret-help">
              <p class="help-block" id="wajub-{$env.environment|escape:'htmlall':'UTF-8'}-secret-help">
                {if $env.masked_secret}
                  {l s='Saved: %key%. Leave empty to keep it.' sprintf=['%key%' => $env.masked_secret] d='Modules.Wajub.Admin'}
                {else}
                  {l s='Starts with %prefix%' sprintf=['%prefix%' => $env.expected_prefix] d='Modules.Wajub.Admin'}
                {/if}
              </p>
              {if $env.masked_secret || $env.secret_unreadable}
                <div class="checkbox">
                  <label for="wajub-remove-{$env.environment|escape:'htmlall':'UTF-8'}-secret">
                    <input type="checkbox" id="wajub-remove-{$env.environment|escape:'htmlall':'UTF-8'}-secret" name="remove_{$env.environment|escape:'htmlall':'UTF-8'}_secret" value="1">
                    {l s='Remove this signing secret' d='Modules.Wajub.Admin'}
                  </label>
                </div>
              {/if}
            </div>
          </div>
        {/foreach}
      </fieldset>
      {if $wajub.can_edit}
        <div class="panel-footer">
          <button type="submit" class="btn btn-default pull-right" name="submitWajubForm" value="1"><i class="process-icon-save" aria-hidden="true"></i> {l s='Save' d='Modules.Wajub.Admin'}</button>
        </div>
      {/if}
    </details>
  </div>
</form>

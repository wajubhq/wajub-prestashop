{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<form method="post" action="{$wajub.form_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
  <input type="hidden" name="wajub_form" value="methods">
  <input type="hidden" name="wajub_token" value="{$wajub.csrf_token|escape:'htmlall':'UTF-8'}">

  <div class="alert alert-info">
    {l s='Each payment method shows at checkout only when your Wajub account offers it for the order: its currency, amount and country.' d='Modules.Wajub.Admin'}
  </div>
  {if $wajub.plain_http}
    <div class="alert alert-warning" data-wajub-plain-http>{$wajub.plain_http|escape:'htmlall':'UTF-8'}</div>
  {/if}

  {foreach from=$wajub.methods item=method}
    {assign var=prefix value="wajub-"|cat:$method.kind}
    <div class="panel">
      <h3 class="panel-heading">{$method.name|escape:'htmlall':'UTF-8'}</h3>
      <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
        <legend class="sr-only">{$method.name|escape:'htmlall':'UTF-8'}</legend>
        <p class="help-block">{$method.help|escape:'htmlall':'UTF-8'}</p>

        <div class="form-group">
          <div class="col-lg-9 col-lg-offset-3">
            <div class="checkbox">
              <label for="{$prefix|escape:'htmlall':'UTF-8'}-enabled">
                <input type="checkbox" id="{$prefix|escape:'htmlall':'UTF-8'}-enabled" name="{$method.kind|escape:'htmlall':'UTF-8'}_enabled" value="1" {if $method.enabled}checked{/if}>
                {l s='Offer %method% at checkout' sprintf=['%method%' => $method.name] d='Modules.Wajub.Admin'}
              </label>
            </div>
          </div>
        </div>

        {foreach from=$method.texts item=text}
          <div class="form-group">
            <label class="control-label col-lg-3" for="{$prefix|escape:'htmlall':'UTF-8'}-title-{$text.id_lang|intval}">
              {l s='Title' d='Modules.Wajub.Admin'}
              {if $wajub.multilingual}<span class="wajub-language">({$text.language|escape:'htmlall':'UTF-8'})</span>{/if}
            </label>
            <div class="col-lg-9">
              <input type="text" class="form-control" id="{$prefix|escape:'htmlall':'UTF-8'}-title-{$text.id_lang|intval}" name="{$method.kind|escape:'htmlall':'UTF-8'}_title[{$text.id_lang|intval}]" value="{$text.title|escape:'htmlall':'UTF-8'}" maxlength="128" lang="{$text.iso_code|escape:'htmlall':'UTF-8'}" placeholder="{$method.default_title|escape:'htmlall':'UTF-8'}" aria-describedby="{$prefix|escape:'htmlall':'UTF-8'}-title-help">
            </div>
          </div>
        {/foreach}
        <div class="form-group">
          <div class="col-lg-9 col-lg-offset-3">
            <p class="help-block" id="{$prefix|escape:'htmlall':'UTF-8'}-title-help">{l s='The name customers see at checkout. Leave empty for the default, in the customer’s language.' d='Modules.Wajub.Admin'}</p>
          </div>
        </div>

        {foreach from=$method.texts item=text}
          <div class="form-group">
            <label class="control-label col-lg-3" for="{$prefix|escape:'htmlall':'UTF-8'}-description-{$text.id_lang|intval}">
              {l s='Description' d='Modules.Wajub.Admin'}
              {if $wajub.multilingual}<span class="wajub-language">({$text.language|escape:'htmlall':'UTF-8'})</span>{/if}
            </label>
            <div class="col-lg-9">
              <textarea class="form-control" id="{$prefix|escape:'htmlall':'UTF-8'}-description-{$text.id_lang|intval}" name="{$method.kind|escape:'htmlall':'UTF-8'}_description[{$text.id_lang|intval}]" rows="2" maxlength="1000" lang="{$text.iso_code|escape:'htmlall':'UTF-8'}" placeholder="{$method.default_description|escape:'htmlall':'UTF-8'}" aria-describedby="{$prefix|escape:'htmlall':'UTF-8'}-description-help">{$text.description|escape:'htmlall':'UTF-8'}</textarea>
            </div>
          </div>
        {/foreach}
        <div class="form-group">
          <div class="col-lg-9 col-lg-offset-3">
            <p class="help-block" id="{$prefix|escape:'htmlall':'UTF-8'}-description-help">{l s='Shown under the title when customers choose this payment method. Leave empty for the default.' d='Modules.Wajub.Admin'}</p>
            <div class="checkbox">
              <label for="{$prefix|escape:'htmlall':'UTF-8'}-no-description">
                <input type="checkbox" id="{$prefix|escape:'htmlall':'UTF-8'}-no-description" name="{$method.kind|escape:'htmlall':'UTF-8'}_no_description" value="1" {if $method.no_description}checked{/if}>
                {l s='Show no description' d='Modules.Wajub.Admin'}
              </label>
            </div>
          </div>
        </div>

        {if $method.logos}
          <div class="form-group">
            <span class="control-label col-lg-3" id="{$prefix|escape:'htmlall':'UTF-8'}-logos-label">{l s='Logos shown at checkout' d='Modules.Wajub.Admin'}</span>
            <div class="col-lg-9">
              <div class="wajub-logos" role="group" aria-labelledby="{$prefix|escape:'htmlall':'UTF-8'}-logos-label" aria-describedby="{$prefix|escape:'htmlall':'UTF-8'}-logos-help">
                {foreach from=$method.logos item=logo}
                  <label class="wajub-logo-choice" for="{$prefix|escape:'htmlall':'UTF-8'}-logo-{$logo.key|escape:'htmlall':'UTF-8'}">
                    <input type="checkbox" id="{$prefix|escape:'htmlall':'UTF-8'}-logo-{$logo.key|escape:'htmlall':'UTF-8'}" name="{$method.kind|escape:'htmlall':'UTF-8'}_logos[]" value="{$logo.key|escape:'htmlall':'UTF-8'}" {if $logo.checked}checked{/if}>
                    <img class="wajub-logo{if $logo.plain} wajub-logo--plain{/if}" src="{$wajub.img_uri|escape:'htmlall':'UTF-8'}methods/{$logo.file|escape:'htmlall':'UTF-8'}" width="{$logo.width|intval}" height="24" alt="">
                    <span>{$logo.name|escape:'htmlall':'UTF-8'}</span>
                  </label>
                {/foreach}
              </div>
              <p class="help-block" id="{$prefix|escape:'htmlall':'UTF-8'}-logos-help">{l s='Show customers the networks and cards they can pay with. Untick them all to show no logo.' d='Modules.Wajub.Admin'}</p>
            </div>
          </div>
        {/if}

        {if $method.kind == 'mobile_money'}
          <fieldset class="form-group wajub-fieldset">
            <legend class="control-label col-lg-3 wajub-legend-inline">{l s='Operator' d='Modules.Wajub.Admin'}</legend>
            <div class="col-lg-9">
              <div class="radio">
                <label for="wajub-operator-list">
                  <input type="radio" id="wajub-operator-list" name="mobile_money_operator" value="list" {if !$wajub.operator_from_number}checked{/if}>
                  {l s='Customers choose their operator from a list with each one’s logo, their number’s own picked already' d='Modules.Wajub.Admin'}
                </label>
              </div>
              <div class="radio">
                <label for="wajub-operator-detect">
                  <input type="radio" id="wajub-operator-detect" name="mobile_money_operator" value="detect" {if $wajub.operator_from_number}checked{/if}>
                  {l s='The payment form finds the operator from the number alone, without the list' d='Modules.Wajub.Admin'}
                </label>
              </div>
            </div>
          </fieldset>
        {/if}
      </fieldset>
    </div>
  {/foreach}

  <div class="panel">
    <h3 class="panel-heading">{l s='Unpaid orders' d='Modules.Wajub.Admin'}</h3>
    <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
      <legend class="sr-only">{l s='Unpaid orders' d='Modules.Wajub.Admin'}</legend>
      <div class="form-group">
        <label class="control-label col-lg-3" for="wajub-cancel-after">{l s='Cancel an unpaid order after' d='Modules.Wajub.Admin'}</label>
        <div class="col-lg-9">
          <div class="input-group fixed-width-lg">
            <input type="number" class="form-control" id="wajub-cancel-after" name="cancel_after" value="{$wajub.cancel_after|intval}" min="0" max="{$wajub.cancel_after_max|intval}" step="1" inputmode="numeric" aria-describedby="wajub-cancel-after-help">
            <span class="input-group-addon">{l s='minutes' d='Modules.Wajub.Admin'}</span>
          </div>
          <p class="help-block" id="wajub-cancel-after-help">{l s='An order still awaiting its Wajub payment this long after it was placed is cancelled, and its products go back in stock: the customer left it. Never one Wajub holds money for, or whose payment may still go through. 0: never cancel.' d='Modules.Wajub.Admin'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="wajub-cron-url">{l s='Scheduled task' d='Modules.Wajub.Admin'}</label>
        <div class="col-lg-9">
          {if $wajub.cron_url !== ''}
            <div class="input-group">
              <input type="text" class="form-control" id="wajub-cron-url" value="{$wajub.cron_url|escape:'htmlall':'UTF-8'}" readonly spellcheck="false" aria-describedby="wajub-cron-url-help wajub-cron-last-run">
              <span class="input-group-btn">
                <button type="button" class="btn btn-default" data-wajub-copy="#wajub-cron-url">{l s='Copy' d='Modules.Wajub.Admin'}</button>
              </span>
            </div>
            <p class="help-block" role="status" aria-live="polite" data-wajub-copy-status></p>
            <p class="help-block" id="wajub-cron-url-help">{l s='This task cancels the unpaid orders, and reads again the payments Wajub could not tell about. PrestaShop runs it every hour when its Cron tasks manager module is installed. Otherwise, ask your web host to call this address every 15 minutes. Keep it private.' d='Modules.Wajub.Admin'}</p>
          {else}
            <p class="form-control-static" id="wajub-cron-url-help">{l s='This task cancels the unpaid orders, and reads again the payments Wajub could not tell about. Its address is shown to the employees who can change these settings.' d='Modules.Wajub.Admin'}</p>
          {/if}
          <p class="help-block" id="wajub-cron-last-run">{$wajub.task_last_run|escape:'htmlall':'UTF-8'}</p>
        </div>
      </div>
    </fieldset>
  </div>

  {if $wajub.can_edit}
    <div class="panel">
      <div class="panel-footer">
        <button type="submit" class="btn btn-default pull-right" name="submitWajubForm" value="1"><i class="process-icon-save" aria-hidden="true"></i> {l s='Save' d='Modules.Wajub.Admin'}</button>
      </div>
    </div>
  {/if}
</form>

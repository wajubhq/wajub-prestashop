{**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<form method="post" action="{$wajub.form_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
  <input type="hidden" name="wajub_form" value="appearance">
  <input type="hidden" name="wajub_token" value="{$wajub.csrf_token|escape:'htmlall':'UTF-8'}">

  <div class="panel">
    <h3 class="panel-heading">{l s='Payment form' d='Modules.Wajub.Admin'}</h3>
    <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
      <legend class="wajub-legend">{l s='Colours of the Mobile Money and Card forms' d='Modules.Wajub.Admin'}</legend>
      <div class="radio">
        <label for="wajub-theme-system">
          <input type="radio" id="wajub-theme-system" name="form_theme" value="system" {if $wajub.form_theme == 'system'}checked{/if} aria-describedby="wajub-theme-system-help">
          <strong>{l s='Your shop’s look' d='Modules.Wajub.Admin'}</strong>
        </label>
        <p class="help-block" id="wajub-theme-system-help">{l s='The form takes the colours, fonts and corners of your checkout, light or dark as your theme is.' d='Modules.Wajub.Admin'}</p>
      </div>
      <div class="radio">
        <label for="wajub-theme-light">
          <input type="radio" id="wajub-theme-light" name="form_theme" value="light" {if $wajub.form_theme == 'light'}checked{/if}>
          <strong>{l s='Light' d='Modules.Wajub.Admin'}</strong>
        </label>
      </div>
      <div class="radio">
        <label for="wajub-theme-dark">
          <input type="radio" id="wajub-theme-dark" name="form_theme" value="dark" {if $wajub.form_theme == 'dark'}checked{/if}>
          <strong>{l s='Dark' d='Modules.Wajub.Admin'}</strong>
        </label>
      </div>
    </fieldset>
  </div>

  <div class="panel">
    <h3 class="panel-heading">{l s='At checkout' d='Modules.Wajub.Admin'}</h3>
    <fieldset class="wajub-fieldset" {if !$wajub.can_edit}disabled{/if}>
      <legend class="sr-only">{l s='At checkout' d='Modules.Wajub.Admin'}</legend>
      <div class="checkbox">
        <label for="wajub-secured-line">
          <input type="checkbox" id="wajub-secured-line" name="secured_line" value="1" {if $wajub.secured_line}checked{/if} aria-describedby="wajub-secured-line-help">
          {l s='Show “Secured by Wajub” to customers' d='Modules.Wajub.Admin'}
        </label>
      </div>
      <p class="help-block" id="wajub-secured-line-help">{l s='A plain-text line, without a link, under the Wajub payment methods at checkout.' d='Modules.Wajub.Admin'}</p>
    </fieldset>
    {if $wajub.can_edit}
      <div class="panel-footer">
        <button type="submit" class="btn btn-default pull-right" name="submitWajubForm" value="1"><i class="process-icon-save" aria-hidden="true"></i> {l s='Save' d='Modules.Wajub.Admin'}</button>
      </div>
    {/if}
  </div>
</form>

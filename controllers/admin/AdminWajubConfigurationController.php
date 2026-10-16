<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\HttpFoundation\Request;
use Wajub\PrestaShop\Admin\AdminToken;
use Wajub\PrestaShop\Admin\AjaxActions;
use Wajub\PrestaShop\Admin\ConfigurationView;
use Wajub\PrestaShop\Admin\FormHandler;
use Wajub\PrestaShop\Services;

/**
 * The module's settings page: Connection, Payment methods, Appearance and
 * Webhooks, each a tab with its own form, and the buttons that answer
 * without a reload (AJAX: "Connect with Wajub", "Test connection",
 * "Register automatically").
 *
 * A legacy back office controller with a Smarty template, rather than a
 * Symfony controller with Twig: one code path for PrestaShop 8.1 and 9,
 * whose Symfony versions (4.4 and 6.4) differ in the controllers' base
 * class, how controllers are declared as services, and form themes, while
 * ModuleAdminController behaves the same on both.
 *
 * Every form and every AJAX call carries the page's token, checked here
 * whatever the shop's "token in URLs" setting says, and needs the right to
 * edit this page. Settings are read and written for the shop, group or all
 * shops the back office's shop selector says.
 */
class AdminWajubConfigurationController extends ModuleAdminController
{
    /** @var Wajub */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * @param bool $isNewTheme
     *
     * @return void
     */
    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        $base = $this->module->getPathUri();
        $this->addCSS($base . 'views/css/admin.css');
        $this->addJS($base . 'views/js/admin.js');
        $this->addJS($base . 'views/js/admin-pairing.js');
    }

    /**
     * @return void
     */
    public function initContent()
    {
        if (!$this->viewAccess()) {
            $this->errors[] = $this->trans('You do not have permission to view this.', [], 'Admin.Notifications.Error');

            return;
        }

        $services = $this->services();
        $t = $services->translator();
        $employeeId = (int) $this->context->employee->id;
        $view = (new ConfigurationView($services))->build($employeeId, $this->languages(), $this->currencyCode(), $this->scopeLabel(), $this->scopeNames(), $this->scopeNames(true));
        $tab = (string) Tools::getValue('wajub_tab');

        $ajaxUrl = $this->context->link->getAdminLink($this->controller_name, true, [], ['ajax' => 1]);
        Media::addJsDef([
            'wajubAdmin' => [
                'ajaxUrl' => $ajaxUrl,
                'token' => $this->csrfToken(),
                'status' => $view['connection']['page_status'],
                'i18n' => [
                    'testing' => $t->trans('Checking…', [], 'Modules.Wajub.Admin'),
                    'registering' => $t->trans('Registering…', [], 'Modules.Wajub.Admin'),
                    'requestFailed' => $t->trans('The request did not go through. Check your connection and try again.', [], 'Modules.Wajub.Admin'),
                    // With why Mobile Money and Card cannot bring payers back
                    // to a shop not served over HTTPS, before Live is saved.
                    'confirmLive' => $t->trans('Switch to Live? This shop will take real payments with your live key.', [], 'Modules.Wajub.Admin') . ($view['plain_http_if_live'] !== '' ? "\n\n" . $view['plain_http_if_live'] : ''),
                    'copied' => $t->trans('Copied.', [], 'Modules.Wajub.Admin'),
                ],
            ],
            'wajubPairing' => [
                'ajaxUrl' => $ajaxUrl,
                'token' => $this->csrfToken(),
                'pending' => $view['pairing_pending'],
                'i18n' => [
                    'starting' => $t->trans('Starting…', [], 'Modules.Wajub.Admin'),
                    'instructions' => $t->trans('In the Wajub tab, check that the code is %code%, choose the environment, then click Authorize. Keep this page open.', [], 'Modules.Wajub.Admin'),
                    'code' => $t->trans('Code %code%', [], 'Modules.Wajub.Admin'),
                    'countdown' => $t->trans('This request expires in %minutes% min.', [], 'Modules.Wajub.Admin'),
                    'blocked' => $t->trans('Your browser blocked the new tab. Open Wajub to approve.', [], 'Modules.Wajub.Admin'),
                    'cancelled' => $t->trans('Connection cancelled.', [], 'Modules.Wajub.Admin'),
                    'reload' => $t->trans('Reload this page', [], 'Modules.Wajub.Admin'),
                    'denied' => $services->pairing()->endMessage('denied'),
                    'expired' => $services->pairing()->endMessage('expired'),
                    'consumed' => $services->pairing()->endMessage('consumed'),
                    'replaced' => $services->pairing()->endMessage('replaced'),
                    'unreachable' => $services->pairing()->endMessage('error'),
                    'requestFailed' => $t->trans('The request did not go through. Check your connection and try again.', [], 'Modules.Wajub.Admin'),
                ],
            ],
        ]);

        if (!$this->access('edit')) {
            // The scheduled task's address holds its secret: for who may change the settings.
            $view['cron_url'] = '';
        }
        $this->context->smarty->assign([
            'wajub' => $view + [
                'active_tab' => in_array($tab, ConfigurationView::TABS, true) ? $tab : 'connection',
                'form_action' => $this->context->link->getAdminLink($this->controller_name),
                'csrf_token' => $this->csrfToken(),
                'notices' => $this->takeFlash($employeeId),
                // The back office's notices, shown by the page itself above
                // its tabs: PrestaShop 9 draws a legacy page without
                // displayAdminAfterHeader.
                'top_notices' => $this->module->adminNotices()['notices'],
                'img_uri' => $this->module->getPathUri() . 'views/img/',
                'logo_uri' => $this->module->getPathUri() . 'logo.png',
                'version' => $this->module->version,
                'can_edit' => (bool) $this->access('edit'),
            ],
        ]);

        $this->content = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/configure.tpl');
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * A form of the page: saved for the scope in context, then the page is
     * loaded again on the same tab, with what the save said. Or "Hide for 7
     * days" on one of the module's notices, on any page of the back office:
     * the employee goes back to it.
     */
    public function postProcess()
    {
        if (!$this->ajax && Tools::getIsset('wajub_hide')) {
            $this->hideNotice();

            return true;
        }
        if (!$this->ajax && Tools::isSubmit('submitWajubForm')) {
            $form = (string) Tools::getValue('wajub_form');
            $employeeId = (int) $this->context->employee->id;
            if (!in_array($form, FormHandler::FORMS, true)) {
                return false;
            }
            if (!$this->access('edit')) {
                $this->errors[] = $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error');

                return false;
            }
            if (!$this->tokenIsValid()) {
                $this->errors[] = $this->services()->translator()->trans('This page has expired. Reload it, then save again.', [], 'Modules.Wajub.Admin');

                return false;
            }

            $notices = (new FormHandler($this->services()))->handle($form, $this->posted(), array_column($this->languages(), 'id_lang'));
            $this->services()->state()->set('admin_flash:' . $employeeId, $notices, 300);
            Tools::redirectAdmin($this->context->link->getAdminLink($this->controller_name, true, [], ['wajub_tab' => $form === 'inherit' ? 'connection' : $form]));

            return true;
        }

        return parent::postProcess();
    }

    /**
     * The page's buttons: `action` names one of AjaxActions::ACTIONS. Each
     * answers JSON and ends the request.
     *
     * @return void
     */
    public function ajaxProcess()
    {
        $action = (string) Tools::getValue('action');
        $services = $this->services();
        if (!in_array($action, AjaxActions::ACTIONS, true)) {
            $this->respond(AjaxActions::failure(400, ['message' => $services->translator()->trans('Unknown action.', [], 'Modules.Wajub.Admin')]));
        }
        if (!$this->access('edit')) {
            $this->respond(AjaxActions::failure(403, ['message' => $services->translator()->trans('You are not allowed to do this.', [], 'Modules.Wajub.Admin')]));
        }
        if (!$this->tokenIsValid()) {
            $this->respond(AjaxActions::failure(403, ['message' => $services->translator()->trans('This page has expired. Reload it, then try again.', [], 'Modules.Wajub.Admin')]));
        }

        $this->respond((new AjaxActions($services, function (int $idShop): Services {
            return $this->module->servicesFor($idShop);
        }))->handle($action, $this->posted(), (int) $this->context->employee->id));
    }

    private function services(): Services
    {
        return $this->module->services();
    }

    /**
     * "Hide for 7 days" (Admin\Notices::hide()): for the employee who may
     * see the notice, with the page's token, then back to the page the
     * notice was on (only a page of this back office), or to the settings.
     */
    private function hideNotice(): void
    {
        // Only who is shown the notices may hide them: who may change the settings.
        if (!$this->access('edit')) {
            $this->errors[] = $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error');

            return;
        }
        if (!$this->tokenIsValid()) {
            $this->errors[] = $this->services()->translator()->trans('This page has expired. Reload it, then try again.', [], 'Modules.Wajub.Admin');

            return;
        }
        $this->module->hideAdminNotice((string) Tools::getValue('wajub_hide'));

        $back = $this->context->link->getAdminLink($this->controller_name);
        $referer = (string) Request::createFromGlobals()->headers->get('referer', '');
        $parts = parse_url($referer);
        $admin = '/' . basename((string) _PS_ADMIN_DIR_) . '/';
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
        // Its path and query only: always this back office's host. A path
        // a browser would read as another host's ("//host/…", "/\host/…")
        // is not one.
        if (str_contains($path, $admin) && $path[0] === '/' && preg_match('#^/[/\\\\]|\\\\#', $path) !== 1) {
            $back = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        Tools::redirectAdmin($back);
    }

    /** The page's token (Admin\AdminToken), sent back with every form and AJAX call. */
    private function csrfToken(): string
    {
        return AdminToken::ofContext();
    }

    private function tokenIsValid(): bool
    {
        $sent = (string) Tools::getValue('wajub_token');

        return $sent !== '' && hash_equals($this->csrfToken(), $sent);
    }

    /**
     * What the form or the AJAX call sent (the posted value, else the
     * address's), each field the page's forms and buttons have, read by its
     * name as the type it is: text, or, for the fields by language and the
     * logos ticked, a list. A field not sent reads empty, as an unticked box
     * does. FormHandler and AjaxActions check each value again.
     *
     * @return array<string, mixed>
     */
    private function posted(): array
    {
        return [
            // Connection
            'environment' => (string) Tools::getValue('environment', ''),
            'sandbox_key' => (string) Tools::getValue('sandbox_key', ''),
            'live_key' => (string) Tools::getValue('live_key', ''),
            'remove_sandbox_key' => (string) Tools::getValue('remove_sandbox_key', ''),
            'remove_live_key' => (string) Tools::getValue('remove_live_key', ''),
            'debug_log' => (string) Tools::getValue('debug_log', ''),
            // Payment methods, one by one (Payment\Methods::KINDS)
            'mobile_money_enabled' => (string) Tools::getValue('mobile_money_enabled', ''),
            'mobile_money_title' => (array) Tools::getValue('mobile_money_title', []),
            'mobile_money_description' => (array) Tools::getValue('mobile_money_description', []),
            'mobile_money_no_description' => (string) Tools::getValue('mobile_money_no_description', ''),
            'mobile_money_logos' => (array) Tools::getValue('mobile_money_logos', []),
            'mobile_money_operator' => (string) Tools::getValue('mobile_money_operator', ''),
            'card_enabled' => (string) Tools::getValue('card_enabled', ''),
            'card_title' => (array) Tools::getValue('card_title', []),
            'card_description' => (array) Tools::getValue('card_description', []),
            'card_no_description' => (string) Tools::getValue('card_no_description', ''),
            'card_logos' => (array) Tools::getValue('card_logos', []),
            'other_enabled' => (string) Tools::getValue('other_enabled', ''),
            'other_title' => (array) Tools::getValue('other_title', []),
            'other_description' => (array) Tools::getValue('other_description', []),
            'other_no_description' => (string) Tools::getValue('other_no_description', ''),
            'other_logos' => (array) Tools::getValue('other_logos', []),
            'cancel_after' => (string) Tools::getValue('cancel_after', ''),
            // Appearance
            'form_theme' => (string) Tools::getValue('form_theme', ''),
            'secured_line' => (string) Tools::getValue('secured_line', ''),
            // Webhooks
            'sandbox_secret' => (string) Tools::getValue('sandbox_secret', ''),
            'live_secret' => (string) Tools::getValue('live_secret', ''),
            'remove_sandbox_secret' => (string) Tools::getValue('remove_sandbox_secret', ''),
            'remove_live_secret' => (string) Tools::getValue('remove_live_secret', ''),
            // The buttons (AJAX)
            'rotate' => (string) Tools::getValue('rotate', ''),
            'id_order' => (int) Tools::getValue('id_order', 0),
            'code' => (string) Tools::getValue('code', ''),
        ];
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $answer
     */
    private function respond(array $answer): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code($answer['status']);
        $this->ajaxRender((string) json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    private function takeFlash(int $employeeId): array
    {
        $state = $this->services()->state();
        $notices = $state->get('admin_flash:' . $employeeId);
        $state->delete('admin_flash:' . $employeeId);

        return is_array($notices) ? array_values(array_filter($notices, 'is_array')) : [];
    }

    /**
     * The shop's active languages, the employee's first.
     *
     * @return list<array{id_lang: int, name: string, iso_code: string}>
     */
    private function languages(): array
    {
        $own = (int) $this->context->employee->id_lang;
        $languages = [];
        foreach (Language::getLanguages(true, $this->services()->scope()->idShop ?? false) as $language) {
            $entry = ['id_lang' => (int) $language['id_lang'], 'name' => (string) $language['name'], 'iso_code' => (string) $language['iso_code']];
            if ($entry['id_lang'] === $own) {
                array_unshift($languages, $entry);
            } else {
                $languages[] = $entry;
            }
        }

        return $languages;
    }

    private function currencyCode(): string
    {
        $currency = Currency::getDefaultCurrency();

        return $currency instanceof Currency ? (string) $currency->iso_code : '';
    }

    /**
     * What the page calls the scopes a setting of this one may come from:
     * its group, all shops; as a label ("All shops", "Group Default"), or,
     * `inText`, as a sentence names them ("all shops", "the group
     * Default"). Empty without multistore.
     *
     * @return array<string, string> by slug
     */
    private function scopeNames(bool $inText = false): array
    {
        if (!Shop::isFeatureActive()) {
            return [];
        }
        $t = $this->services()->translator();
        $scope = $this->services()->scope();
        $names = ['all' => $inText ? $t->trans('all shops', [], 'Modules.Wajub.Admin') : $t->trans('All shops', [], 'Modules.Wajub.Admin')];
        if ($scope->idShopGroup !== null) {
            $group = new ShopGroup($scope->idShopGroup);
            $name = Validate::isLoadedObject($group) ? (string) $group->name : (string) $scope->idShopGroup;
            $names['g' . $scope->idShopGroup] = $inText
                ? $t->trans('the group %name%', ['%name%' => $name], 'Modules.Wajub.Admin')
                : $t->trans('Group %name%', ['%name%' => $name], 'Modules.Wajub.Admin');
        }

        return $names;
    }

    /** What the settings are for, when the shop has several: '' otherwise. */
    private function scopeLabel(): string
    {
        if (!Shop::isFeatureActive()) {
            return '';
        }
        $t = $this->services()->translator();
        switch (Shop::getContext()) {
            case Shop::CONTEXT_SHOP:
                return $t->trans('These settings are for the shop %name%.', ['%name%' => (string) $this->context->shop->name], 'Modules.Wajub.Admin');
            case Shop::CONTEXT_GROUP:
                $group = Shop::getContextShopGroup();

                return $t->trans('These settings are for the shops of the group %name%, unless a shop has its own.', ['%name%' => (string) $group->name], 'Modules.Wajub.Admin');
            default:
                return $t->trans('These settings are for all shops, unless a group or a shop has its own.', [], 'Modules.Wajub.Admin');
        }
    }
}

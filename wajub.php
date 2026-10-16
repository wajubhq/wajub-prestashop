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

// This file only is read by PHP versions older than the module supports, so
// that PrestaShop can say why it cannot be installed: it keeps to syntax
// PHP 7.2 reads, and loads the rest of the module on PHP 8.1 and later only.
if (PHP_VERSION_ID >= 80100) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Wajub\PrestaShop\Admin\AdminToken;
use Wajub\PrestaShop\Admin\Notices;
use Wajub\PrestaShop\Admin\OrderPanel;
use Wajub\PrestaShop\Checkout\CheckoutStrings;
use Wajub\PrestaShop\Checkout\PrestaShopCarts;
use Wajub\PrestaShop\Connection\KeyReminders;
use Wajub\PrestaShop\Install\OrderStates;
use Wajub\PrestaShop\Install\Schema;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Order\PrestaShopOrders;
use Wajub\PrestaShop\Payment\LogoStrip;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Privacy\CustomerData;
use Wajub\PrestaShop\Refund\PendingSlips;
use Wajub\PrestaShop\Refund\RefundSync;
use Wajub\PrestaShop\Refund\RefusedRefund;
use Wajub\PrestaShop\Refund\SlipRequest;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\CheckoutLocale;
use Wajub\PrestaShop\Support\Translator;
use Wajub\PrestaShop\Webhook\DisputeEventHandler;
use Wajub\PrestaShop\Webhook\PaymentEventHandler;
use Wajub\PrestaShop\Webhook\RefundEventHandler;

/**
 * Wajub payments for PrestaShop: Mobile Money and cards with Wajub's payment
 * form inside the checkout, other payment methods on Wajub's hosted page,
 * "Connect with Wajub", and the webhook that settles orders.
 *
 * The order is created when the customer places it, in "Awaiting Wajub
 * payment"; each Wajub payment that counts becomes one of its payments.
 * Sandbox and Live never mix: each environment pays with its own key.
 */
class Wajub extends PaymentModule
{
    /**
     * The module's version, in one place: config.xml says the same
     * (bin/build-zip.sh and bin/lint-module.php check it), as do the top of
     * CHANGELOG.md and, for a version that changes the database or the
     * hooks, upgrade/upgrade-<version>.php.
     */
    public const VERSION = '1.0.1';

    /** The PHP version the module's code needs. */
    public const MIN_PHP_VERSION_ID = 80100;

    /** The oldest PrestaShop the module runs on. */
    public const MIN_PS_VERSION = '8.1.0';

    /**
     * Every hook the module uses, from every lot, in one place: install()
     * registers them, and an upgrade that adds one registers this list
     * again. Each has its method below, which does nothing until the lot
     * that needs it.
     *
     * - paymentOptions: Mobile Money, Card and Other payment methods at checkout;
     * - displayPaymentReturn: the order confirmation page, while a payment settles;
     * - displayOrderDetail: the customer's order page, to pay what is left;
     * - actionFrontControllerSetMedia: the payment form's script and style on
     *   the checkout, the status panel's on the order confirmation page, the
     *   checkout style on the customer's order page and, on every page while a
     *   payment that could not count is due to be read again, the script that
     *   asks the shop to read it (Order\Recounts);
     * - displayAdminOrderMain: the Wajub panel of the back office's order page;
     * - actionAdminControllerSetMedia: the order page's panel's script and
     *   style; the style of the back office's notices and "Wajub · Sandbox",
     *   and their Copy button's script, on a page that shows them; on every
     *   page of the back office, while a payment that could not count is due
     *   to be read again, the script that asks the shop to read it
     *   (Order\Recounts);
     * - actionBeforeUpdateCancelProductFormHandler: a refund of the order page
     *   (Partial refund, Standard refund, Return products), refunded through
     *   Wajub before PrestaShop makes its credit slip, or refused;
     * - actionObjectOrderSlipAddAfter: the credit slip of such a refund as
     *   PrestaShop adds it, which records what Wajub refunded then, before
     *   another module's hook can stop PrestaShop (Refund\PendingSlips);
     * - actionOrderSlipAdd: the credit slip made, which records those refunds,
     *   or one made another way, refunded through Wajub then;
     * - actionOrderStatusPostUpdate: an order paid (its confirmation e-mail, held
     *   until then), cancelled or refunded in the back office;
     * - actionEmailSendBefore: the confirmation e-mail of an order being placed,
     *   held until it is paid (Order\HeldConfirmations);
     * - actionCronJob: PrestaShop's Cron tasks manager runs the module's
     *   scheduled task (Order\Cleanup, Connection\KeyReminders) every hour,
     *   when it is installed;
     * - displayAdminAfterHeader: the back office's notices (adminNotices()):
     *   the shop takes no payment, a key that expires within two weeks or
     *   has (Connection\KeyReminders), a shop the public uses still in
     *   Sandbox, Wajub to set up, or not offered at checkout, the scheduled
     *   task that does not run;
     * - actionAdminControllerInitAfter: the same, on PrestaShop 9's legacy
     *   pages (the dashboard among them), drawn without displayAdminAfterHeader;
     * - displayBackOfficeTop: "Wajub · Sandbox" in the header of every page of
     *   the back office while the shop takes test payments;
     * - actionExportGDPRData, actionDeleteGDPRCustomer: PrestaShop's GDPR
     *   module exports, or erases, what the module keeps of a customer
     *   (Privacy\CustomerData);
     * - actionModuleRegisterHookAfter: a module that hooks the refund forms
     *   after the module was installed runs before it there all the same
     *   (keepLastOnRefundForms()).
     */
    public const HOOKS = [
        'paymentOptions',
        'displayPaymentReturn',
        'displayOrderDetail',
        'actionFrontControllerSetMedia',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        'actionBeforeUpdateCancelProductFormHandler',
        'actionObjectOrderSlipAddAfter',
        'actionOrderSlipAdd',
        'actionOrderStatusPostUpdate',
        'actionEmailSendBefore',
        'actionCronJob',
        'displayAdminAfterHeader',
        'actionAdminControllerInitAfter',
        'displayBackOfficeTop',
        'actionExportGDPRData',
        'actionDeleteGDPRCustomer',
        'actionModuleRegisterHookAfter',
    ];

    /** The hook of the order page's refund forms, where the module runs last (keepLastOnRefundForms()). */
    public const REFUND_FORMS_HOOK = 'actionBeforeUpdateCancelProductFormHandler';

    /** The back office page of the module's settings (controllers/admin). */
    public const ADMIN_CONTROLLER = 'AdminWajubConfiguration';

    /** Wajub.js, the payment form's SDK, from Wajub's own CDN. */
    public const WAJUB_JS = 'https://js.wajub.com';

    /** How long the order confirmation page follows a payment before it says it is still waiting. */
    public const WAIT_SECONDS = 300;

    /**
     * True while controllers/front/validation.php places an order
     * (validateOrder()): the confirmation e-mail PrestaShop sends then is
     * held until the order is paid.
     *
     * @var bool
     */
    public $placingOrder = false;

    /**
     * The refunds of the order page's forms this request
     * (hookActionBeforeUpdateCancelProductFormHandler()), until the credit
     * slip PrestaShop makes next records them
     * (hookActionObjectOrderSlipAddAfter(), hookActionOrderSlipAdd()), or
     * the end of the request does.
     *
     * @var PendingSlips|null
     */
    private $pendingSlips;

    /** @var Services|null */
    private $services;

    /**
     * The back office's notices of this request (adminNotices()), worked out
     * once for the hooks that show them, their style and their script.
     *
     * @var array{notices: list<array<string, mixed>>, badge: bool}|null
     */
    private $adminNotices;

    /** @var array<string, Services> by the slug of their scope */
    private $servicesByScope = [];

    public function __construct()
    {
        $this->name = 'wajub';
        $this->tab = 'payments_gateways';
        $this->version = self::VERSION;
        $this->author = 'Wajub';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // Every PrestaShop 9, written out as PrestaShop Addons asks: never
        // _PS_VERSION_, which would say the module runs on any version to
        // come, and which its validator rejects (bin/lint-module.php checks).
        $this->ps_versions_compliancy = ['min' => self::MIN_PS_VERSION, 'max' => '9.99.99'];
        $this->controllers = ['webhook', 'prepare', 'validation', 'status', 'return', 'release', 'pay', 'cron', 'recount'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->tabs = [
            [
                'class_name' => self::ADMIN_CONTROLLER,
                'name' => 'Wajub',
                // Reached from the module's Configure button, not from the
                // menu. PrestaShop gives the page to the SuperAdmin and to
                // the profile of the employee who installs the module; Team >
                // Permissions gives it to others, under Payment > Wajub (or
                // with Payment, whose pages follow it). getContent() says so
                // to an employee whose profile may not open it.
                'visible' => false,
                'parent_class_name' => 'AdminParentPayment',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Wajub', [], 'Modules.Wajub.Admin');
        $this->description = $this->trans('Accept Mobile Money, cards and other payment methods with Wajub.', [], 'Modules.Wajub.Admin');
        $this->confirmUninstall = $this->trans('Uninstalling removes the Wajub settings, keys and signing secrets. It keeps your orders, their payments, and the record of their Wajub payments, refunds and notes (the tables wajub_payment, wajub_refund and wajub_order_note), which a new install finds again. Continue?', [], 'Modules.Wajub.Admin');

        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            $this->warning = $this->phpVersionMessage();
        }
    }

    /**
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            $this->_errors[] = $this->phpVersionMessage();

            return false;
        }
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->trans('Wajub needs the PHP cURL extension, which this server does not have. Ask your web host to turn it on.', [], 'Modules.Wajub.Admin');

            return false;
        }

        if (!Schema::create()) {
            $this->_errors[] = $this->trans('The Wajub tables could not be created.', [], 'Modules.Wajub.Admin');

            return false;
        }

        if (!parent::install()) {
            return false;
        }

        // PrestaShop lists the module as installed from here on: a step that
        // fails now is undone, or the merchant would have a module that
        // half works, and that a new install refuses until it is uninstalled.
        $failure = '';
        if (!$this->registerHook(self::HOOKS)) {
            $failure = $this->trans('The Wajub hooks could not be registered.', [], 'Modules.Wajub.Admin');
        } elseif (!OrderStates::install($this->name, $this->getLocalPath())) {
            $failure = $this->trans('The Wajub order states could not be created.', [], 'Modules.Wajub.Admin');
        }
        if ($failure !== '') {
            $this->_errors[] = $failure;
            $this->uninstall();

            return false;
        }
        $this->keepLastOnRefundForms();

        // For all shops, as a first install is: each shop, group or "All
        // shops" then changes what it needs from the module's page.
        foreach (Settings::defaults() as $key => $value) {
            // Kept when a value is there already: an earlier install's,
            // which a reinstall must not undo.
            if ((string) Configuration::getGlobalValue($key) === '') {
                Configuration::updateGlobalValue($key, $value);
            }
        }
        // The back office says so once the scheduled task has not run for
        // a while from now (Admin\Notices).
        $this->services()->taskLog()->watch(time());

        return true;
    }

    /**
     * Removes the settings, the keys and the module's working tables. Kept,
     * as the WordPress plugin keeps what it wrote on orders: the record of
     * each order's Wajub payments and refunds (Schema::KEPT), which is the
     * merchant's order history, and which PrestaShop's "Reset" (an
     * uninstall, then an install) would otherwise lose; and an order state
     * an order went through, with its id (OrderStates::uninstall()). The
     * confirmation PrestaShop asks before says what is kept, tables named.
     * Never a key in Wajub: the keys "Connect with Wajub" created stay in
     * the Wajub Dashboard, where the merchant deletes them.
     */
    public function uninstall()
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return parent::uninstall();
        }

        $done = OrderStates::uninstall();
        foreach (array_diff(Settings::allKeys(), OrderStates::configKeys()) as $key) {
            Configuration::deleteByName($key);
        }
        // What PaymentModule::install() saves for every payment module.
        foreach (['FIXED', 'VAR', 'FIXED_FOREIGN', 'VAR_FOREIGN'] as $suffix) {
            Configuration::deleteByName('CONF_WAJUB_' . $suffix);
        }
        $done = Schema::drop() && $done;
        LogoStrip::forget(_PS_TMP_IMG_DIR_);

        return parent::uninstall() && $done;
    }

    /**
     * The module's Configure button opens its own page in the back office.
     *
     * @return string
     */
    public function getContent()
    {
        // PrestaShop shows the Configure button to the profiles that may
        // configure the module, and the page to the profiles given it in
        // Team > Permissions: an employee with the first and not the second
        // is told why, rather than sent to a page that refuses them.
        if (!$this->settingsPageAllowed($this->context->employee)) {
            $message = $this->trans('Your profile cannot open the Wajub settings. An administrator can give it access in Advanced Parameters > Team > Permissions, under Payment > Wajub.', [], 'Modules.Wajub.Admin');

            // PrestaShop 8 escapes what Module::trans() returns, PrestaShop 9
            // does not: escaped once, whichever it is.
            return $this->displayError(htmlspecialchars(html_entity_decode($message, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'));
        }

        Tools::redirectAdmin($this->context->link->getAdminLink(self::ADMIN_CONTROLLER));

        return '';
    }

    /**
     * Whether the employee's profile may open the module's settings page:
     * the page's own permission, as PrestaShop checks it.
     *
     * @param Employee|null $employee
     */
    public function settingsPageAllowed($employee): bool
    {
        if (!$employee instanceof Employee) {
            return false;
        }

        return (bool) Access::isGranted('ROLE_MOD_TAB_' . strtoupper(self::ADMIN_CONTROLLER) . '_READ', (int) $employee->id_profile);
    }

    /**
     * Whether the employee's profile may change the module's settings: who
     * is told what to fix, as the plugin tells those who can manage options.
     * A profile that may only read them is not handed a notice it can do
     * nothing about, nor the scheduled task's secret address.
     *
     * @param Employee|null $employee
     */
    public function settingsEditable($employee): bool
    {
        if (!$employee instanceof Employee) {
            return false;
        }

        return (bool) Access::isGranted('ROLE_MOD_TAB_' . strtoupper(self::ADMIN_CONTROLLER) . '_UPDATE', (int) $employee->id_profile);
    }

    /**
     * The module's objects for the scope PrestaShop's context is in: the
     * shop of a storefront page, or what the back office's shop selector
     * says.
     */
    public function services(): Services
    {
        if ($this->services === null) {
            $this->services = $this->withHandlers(new Services(Scope::fromContext(), (string) $this->version));
        }

        return $this->services;
    }

    /**
     * The module's objects for one shop, whatever the context is: a webhook
     * acts on the shop of the order a payment is for (wajub_payment.id_shop),
     * and the back office's order page on the order's shop, while its shop
     * selector may say another one, or all shops. Its key, environment and
     * settings are that shop's.
     */
    public function servicesFor(int $idShop): Services
    {
        return $this->servicesOf(Scope::ofShop($idShop));
    }

    /**
     * The module's objects for one scope (a shop, a group, or all shops),
     * whatever the context is.
     */
    public function servicesOf(Scope $scope): Services
    {
        $slug = $scope->slug();
        if (!isset($this->servicesByScope[$slug])) {
            $this->servicesByScope[$slug] = $this->withHandlers(new Services($scope, (string) $this->version));
        }

        return $this->servicesByScope[$slug];
    }

    /**
     * The services the webhook verifies a delivery with: those of the scope
     * its address names (`scope`, PrestaShopShopInfo::webhookUrl()), when
     * the shop or group is one of this PrestaShop's; else those of the shop
     * whose address it reached. Without multistore, there is one scope.
     */
    public function webhookServices(string $slug): Services
    {
        $scope = Shop::isFeatureActive() ? Scope::fromSlug($slug) : null;
        if ($scope === null || !self::scopeExists($scope)) {
            return $this->services();
        }

        return $this->servicesOf($scope);
    }

    private static function scopeExists(Scope $scope): bool
    {
        if ($scope->idShop !== null) {
            return Shop::getShop($scope->idShop) !== false;
        }
        if ($scope->idShopGroup !== null) {
            return Validate::isLoadedObject(new ShopGroup($scope->idShopGroup));
        }

        return true;
    }

    /**
     * The webhook's handlers of payment, refund and dispute events, which act
     * on an order with the services of the order's own shop; and that shop's
     * environment, which the webhook judges an event by (Webhook\EventShops).
     */
    private function withHandlers(Services $services): Services
    {
        $servicesFor = function (int $idShop): Services {
            return $this->servicesFor($idShop);
        };
        $services->addEventHandler(new PaymentEventHandler($services->payments(), $servicesFor, $services->logger()));
        $services->addEventHandler(new RefundEventHandler($services->payments(), $servicesFor, $services->logger()));
        $services->addEventHandler(new DisputeEventHandler($services->payments(), $servicesFor, $services->logger()));
        $services->useShopServices($servicesFor);

        return $services;
    }

    /**
     * The services of the scope a slug names ('all', 'g2', 's3'), for what
     * looks at every scope of the PrestaShop at once (the reminders of keys
     * that expire): null for a shop or group the PrestaShop no longer has,
     * or a shop's or group's slug once multistore is off.
     */
    public function servicesOfSlug(string $slug): ?Services
    {
        $scope = Scope::fromSlug($slug);
        if ($scope === null || (!$scope->isAllShops() && !Shop::isFeatureActive()) || !self::scopeExists($scope)) {
            return null;
        }

        return $this->servicesOf($scope);
    }

    /**
     * Gives each webhook endpoint a scope of this PrestaShop registered the
     * events of WebhookRegistration::EVENTS it lacks, for a version that
     * listens to more (upgrade scripts). By scope slug, then environment:
     * what WebhookRegistration::addMissingEvents() says.
     *
     * @return array<string, array<string, string>>
     */
    public function subscribeWebhookEvents(): array
    {
        $done = [];
        foreach ($this->services()->settings()->webhookEndpointsEverywhere() as $slug) {
            $services = $this->servicesOfSlug($slug);
            if ($services !== null) {
                $done[$slug] = $services->webhookRegistration()->addMissingEvents();
            }
        }

        return $done;
    }

    /**
     * The e-mails of keys that expire within two weeks, or have, one per
     * key (Connection\KeyReminders). Returns how many went.
     */
    public function runReminders(): int
    {
        return $this->keyReminders()->remind();
    }

    /**
     * The reminders of keys that expire (Connection\KeyReminders), which, in
     * a multistore, look at every shop for the ones that pay with each key,
     * and name in the e-mail the scope the key is saved for.
     */
    private function keyReminders(): KeyReminders
    {
        $shops = [];
        if (Shop::isFeatureActive()) {
            foreach ((array) Shop::getShops(true, null, true) as $idShop) {
                $shops[] = 's' . (int) $idShop;
            }
        }

        return $this->services()->keyReminders(function (string $slug): ?Services {
            return $this->servicesOfSlug($slug);
        }, $shops, function (string $slug, Translator $t): string {
            return Shop::isFeatureActive() ? $this->scopeName($slug, $t) : '';
        });
    }

    /**
     * The module's scheduled task, as the Cron tasks manager or the host's
     * cron (controllers/front/cron.php) runs it: its run noted first, for
     * the back office to say when it does not run (Order\TaskLog,
     * Admin\Notices), then runCleanup() and runReminders().
     *
     * @return array{recounted: int, cancelled: list<int>, kept: list<int>, reminded: int}
     */
    public function runScheduledTask(): array
    {
        $this->services()->taskLog()->ran(time());
        $done = $this->runCleanup();
        $done['reminded'] = $this->runReminders();

        return $done;
    }

    /**
     * One run of the module's scheduled task (Order\Cleanup): the payments
     * that could not count read again, the orders their payers left
     * cancelled after the configured delay, each with the services of its
     * own shop.
     *
     * @return array{recounted: int, cancelled: list<int>, kept: list<int>}
     */
    public function runCleanup(): array
    {
        return $this->services()->cleanup(function (int $idShop): Services {
            return $this->servicesFor($idShop);
        })->run();
    }

    // --- Hooks ---------------------------------------------------------------------

    /**
     * The checkout's Wajub options (Checkout\CheckoutOptions): Mobile Money
     * and Card with Wajub's payment form inside the checkout, each when the
     * account offers it for the cart, and "Other payment methods" on Wajub's
     * hosted page when the account has something besides them.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, PaymentOption>
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return [];
        }
        $cart = $params['cart'] ?? $this->context->cart;
        if (!$cart instanceof Cart || !Validate::isLoadedObject($cart) || !$this->checkCurrency($cart)) {
            return [];
        }

        try {
            $services = $this->services();
            $view = PrestaShopCarts::view($cart);
            if ($view === null) {
                return [];
            }
            $options = $services->checkoutOptions()->forCart($view, (int) $this->context->language->id, $view->currency);
        } catch (Throwable $e) {
            // The checkout's other payment methods still show.
            PrestaShopLogger::addLog('Wajub: the payment options could not be built: ' . $e->getMessage(), 3, null, 'Cart', (int) $cart->id, true);

            return [];
        }

        $environment = $services->environment();
        $validation = $this->context->link->getModuleLink($this->name, 'validation', [], true);
        $token = $services->tokens()->checkout((int) $cart->id, (int) $cart->id_customer);
        $common = [
            'test_mode' => $environment->isSandbox(),
            'test_badge' => CheckoutStrings::testMode($services->translator()),
            'secured' => $services->settings()->securedLine() ? CheckoutStrings::secured($services->translator()) : '',
        ];

        $paymentOptions = [];
        foreach ($options as $option) {
            $kind = $option['kind'];
            $words = CheckoutStrings::fields(Methods::isFields($kind) ? $kind : '', $services->translator());
            $this->context->smarty->assign('wajub', $common + [
                'kind' => $kind,
                'component' => Methods::COMPONENTS[$kind] ?? '',
                'description_lines' => $option['description'] !== '' ? explode("\n", $option['description']) : [],
                'action' => $validation,
                'token' => $token,
                'cart' => (int) $cart->id,
                // The invoice country as the options are drawn: a checkout
                // module that changes the address draws them again.
                'country' => $view->country,
                'words' => $words,
                'logo_alt' => LogoStrip::alt($option['logos']),
            ]);

            $paymentOption = new PaymentOption();
            $paymentOption->setModuleName($this->name)
                ->setCallToActionText($option['title'])
                ->setAdditionalInformation($this->fetch('module:wajub/views/templates/front/payment-info.tpl'));
            $logo = $this->logoUrl($option['logos']);
            if ($logo !== '') {
                $paymentOption->setLogo($logo);
            }
            if ($option['mode'] === 'fields') {
                $paymentOption->setForm($this->fetch('module:wajub/views/templates/front/payment-fields.tpl'));
            } else {
                $paymentOption->setAction($validation)->setInputs([
                    'kind' => ['name' => 'kind', 'type' => 'hidden', 'value' => $kind],
                    'token' => ['name' => 'token', 'type' => 'hidden', 'value' => $token],
                    'cart' => ['name' => 'cart', 'type' => 'hidden', 'value' => (string) (int) $cart->id],
                ]);
            }
            $paymentOptions[] = $paymentOption;
        }

        return $paymentOptions;
    }

    /**
     * The address of the picture of an option's logos (Payment\LogoStrip),
     * written once in PrestaShop's folder of the pictures it makes (img/tmp);
     * the first logo's own file, should that folder take no file.
     *
     * @param list<string> $keys
     */
    private function logoUrl(array $keys): string
    {
        $file = LogoStrip::file($keys, $this->getLocalPath() . 'views/img/methods', _PS_TMP_IMG_DIR_);
        if ($file !== '') {
            return $this->context->link->getMediaLink(_PS_TMP_IMG_ . basename($file));
        }
        foreach ($keys as $key) {
            if (Methods::logoFile($key) !== '') {
                return $this->context->link->getMediaLink($this->getPathUri() . 'views/img/methods/' . Methods::logoFile($key));
            }
        }

        return '';
    }

    /**
     * The order confirmation page of a Wajub order: while its payment is
     * being confirmed (a Mobile Money approval on the payer's phone), a panel
     * that follows it and says what to do (order-status.js); once part of it
     * is paid, what is left; and the Wajub payment ids, which the payer
     * quotes to the shop.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayPaymentReturn($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }

        $services = $this->servicesFor((int) $order->id_shop);
        $view = $services->orders()->find((int) $order->id);
        if ($view === null) {
            return '';
        }
        $t = $services->translator();
        $records = $services->payments()->forOrder($view->id);
        $current = $records === [] ? null : $records[count($records) - 1];

        $states = [$services->settings()->awaitingState(), $services->settings()->partialState(), (int) Configuration::get('PS_OS_ERROR')];
        $status = $services->statusAnswer()->confirmationPanel($view, $states);
        $panel = $status['panel'];
        if ($panel !== '') {
            $this->context->smarty->assign('wajub_status', $status + [
                'status_url' => $services->storefront()->status($view),
                'release_url' => $services->storefront()->release($view),
                // Where the payer pays what is left (the script offers it
                // once the order is found part paid, the partial panel now).
                'pay_url' => $services->storefront()->pay($view),
                'token' => $services->storefront()->orderToken($view),
                'timeout' => self::WAIT_SECONDS,
                'order_id' => $view->id,
            ]);
        }

        $ids = [];
        foreach ($records as $record) {
            if ($record->isCounted() || $record === $current) {
                $ids[] = $record->paymentId;
            }
        }
        $this->context->smarty->assign('wajub_return', [
            'panel' => $panel,
            'ids' => $ids,
            'label' => count($ids) > 1 ? $t->trans('Payment IDs:', [], 'Modules.Wajub.Shop') : $t->trans('Payment ID:', [], 'Modules.Wajub.Shop'),
        ]);

        return $this->fetch('module:wajub/views/templates/hook/payment-return.tpl');
    }

    /**
     * The customer's order page, in their account: an order part of which
     * is paid says what was received and what is left, with "Pay the rest";
     * one whose payment did not go through, "Pay for this order". Either
     * opens a payment on Wajub's hosted page for what is left
     * (controllers/front/pay.php). Nothing while a payment is under way.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'] ?? null;
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }

        try {
            $services = $this->servicesFor((int) $order->id_shop);
            $view = $services->orders()->find((int) $order->id);
            if ($view === null || !$services->environment()->isConfigured() || !$services->statusAnswer()->payable($view)) {
                return '';
            }
            $environment = $services->environment()->isLive() ? 'live' : 'sandbox';
            $line = $services->statusAnswer()->payerLine($view, $environment);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Wajub: the order page could not offer to pay the rest: ' . $e->getMessage(), 2, null, 'Order', (int) $order->id, true);

            return '';
        }

        $this->context->smarty->assign('wajub_pay', [
            'line' => $line,
            'rest' => $line !== '',
            'url' => $services->storefront()->pay($view),
            'token' => $services->storefront()->orderToken($view),
        ]);

        return $this->fetch('module:wajub/views/templates/hook/order-detail.tpl');
    }

    /**
     * Wajub.js and the payment form's scripts on the checkout, when Mobile
     * Money or Card can take a payment there; the status panel's script on
     * the order confirmation page. On any page, while a payment that could
     * not count is due to be read again, the script that asks the shop to
     * read it (views/js/recount.js, which posts to controllers/front/recount.php).
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return;
        }
        $controller = $this->context->controller;
        if ($controller instanceof FrontController && $this->recountDue()) {
            $controller->registerJavascript('wajub-recount', 'modules/' . $this->name . '/views/js/recount.js', ['position' => 'bottom', 'priority' => 300]);
            Media::addJsDef(['wajubRecount' => ['url' => $this->recountUrl()]]);
        }
        $page = $controller instanceof FrontController ? (string) $controller->php_self : '';
        if ($page !== 'order' && $page !== 'order-confirmation' && $page !== 'order-detail') {
            return;
        }

        $services = $this->services();
        if (!$services->environment()->isConfigured()) {
            return;
        }
        $base = 'modules/' . $this->name . '/views/';
        $controller->registerStylesheet('wajub-checkout', $base . 'css/checkout.css', ['media' => 'all', 'priority' => 150]);

        if ($page === 'order-detail') {
            // The order's "Pay the rest" box: a plain form, no script.
            return;
        }
        if ($page === 'order-confirmation') {
            $controller->registerJavascript('wajub-order-status', $base . 'js/order-status.js', ['position' => 'bottom', 'priority' => 200]);
            Media::addJsDef(['wajubOrderStatus' => ['i18n' => CheckoutStrings::orderStatus($services->translator())]]);

            return;
        }

        // Every Wajub option: its logo's words, whatever the theme draws.
        $controller->registerJavascript('wajub-checkout-options', $base . 'js/checkout-options.js', ['position' => 'bottom', 'priority' => 199]);

        $kinds = $services->offer()->separateKinds();
        $cart = $this->context->cart;
        if ($kinds === [] || !$cart instanceof Cart || !Validate::isLoadedObject($cart)) {
            return;
        }

        $language = CheckoutLocale::of((string) $this->context->language->iso_code);
        $view = PrestaShopCarts::view($cart);
        $methods = [];
        foreach ($kinds as $kind) {
            $methods[$kind] = $services->checkoutOptions()->clientConfig($kind, $language);
        }
        $controller->registerJavascript('wajub-js', $this->wajubJsUrl(), ['server' => 'remote', 'position' => 'bottom', 'priority' => 200]);
        $controller->registerJavascript('wajub-fields-core', $base . 'js/fields-core.js', ['position' => 'bottom', 'priority' => 201]);
        $controller->registerJavascript('wajub-checkout-fields', $base . 'js/checkout-fields.js', ['position' => 'bottom', 'priority' => 202]);
        Media::addJsDef(['wajubFields' => [
            'methods' => $methods,
            'prepareUrl' => $this->context->link->getModuleLink($this->name, 'prepare', [], true),
            'validationUrl' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
            'cart' => (int) $cart->id,
            'token' => $services->tokens()->checkout((int) $cart->id, (int) $cart->id_customer),
            'country' => $view !== null ? $view->country : '',
        ]]);
    }

    /**
     * The "Wajub payment" panel of the back office's order page
     * (Admin\OrderPanel), for an order with a Wajub payment, read with the
     * services of the order's shop.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderMain($params)
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || $idOrder <= 0) {
            return '';
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        $view = (new OrderPanel($this->servicesFor((int) $order->id_shop)))->view($idOrder);
        if ($view === null) {
            return '';
        }

        $employee = $this->context->employee;
        $canCheck = $employee instanceof Employee
            && Access::isGranted('ROLE_MOD_TAB_' . strtoupper(self::ADMIN_CONTROLLER) . '_UPDATE', (int) $employee->id_profile);
        $this->context->smarty->assign('wajub_order', $view + [
            'can_check' => $canCheck,
            'ajax_url' => $canCheck ? $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], ['ajax' => 1]) : '',
            'token' => $canCheck ? AdminToken::ofContext() : '',
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin-order.tpl');
    }

    /**
     * The back office's scripts and styles, through PrestaShop's own lists
     * (in the page's head on PrestaShop 8 and 9, legacy and Symfony pages):
     * on the orders' pages, those of the order page's Wajub panel
     * (hookDisplayAdminOrderMain()); on a page that shows the module's
     * notices or "Wajub · Sandbox", their style and the notices' Copy
     * button; and, while a payment that could not count is due to be read
     * again, the script that asks the shop to read it, as on the
     * storefront's (hookActionFrontControllerSetMedia()).
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionAdminControllerSetMedia($params)
    {
        // A legacy page's AdminController, or, on PrestaShop 9's Symfony
        // pages, the LegacyControllerContext that stands for it (which
        // dispatches this hook from its head tag): both take scripts.
        $controller = $this->context->controller;
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || $controller === null || $controller instanceof FrontController
            || (isset($controller->ajax) && $controller->ajax)) {
            return;
        }
        $base = $this->getPathUri() . 'views/';
        $suffix = '?v=' . $this->version;
        if ($this->adminPage() === 'AdminOrders') {
            $controller->addCSS($base . 'css/admin-order.css' . $suffix);
            $controller->addJS($base . 'js/admin-order.js' . $suffix);
        }
        $shown = $this->adminNotices();
        if ($shown['notices'] !== [] || $shown['badge']) {
            $controller->addCSS($base . 'css/admin-notices.css' . $suffix);
            $controller->addJS($base . 'js/admin-notices.js' . $suffix);
        }
        if ($this->recountDue()) {
            $controller->addJS($base . 'js/recount.js' . $suffix);
            Media::addJsDef(['wajubRecount' => ['url' => $this->recountUrl()]]);
        }
    }

    /**
     * The back office page being drawn, by its controller ("AdminOrders",
     * "AdminDashboard"…), which PrestaShop 8 gives its Symfony pages too,
     * and PrestaShop 9 their LegacyControllerContext; '' when unknown.
     */
    private function adminPage(): string
    {
        $controller = $this->context->controller;

        return is_object($controller) && isset($controller->controller_name) ? (string) $controller->controller_name : '';
    }

    /**
     * Whether a payment that could not count is due to be read again
     * (Order\Recounts): a setting PrestaShop has read already, no query.
     */
    private function recountDue(): bool
    {
        try {
            return $this->services()->recounts(function (int $idShop): Services {
                return $this->servicesFor($idShop);
            })->due(time());
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Where the pages ask the shop to read again the payments due (controllers/front/recount.php). */
    private function recountUrl(): string
    {
        return $this->context->link->getModuleLink($this->name, 'recount', [], true);
    }

    /**
     * Reads again the payments that could not count and are due to be
     * (Order\Recounts), each with the services of its own shop: how many
     * count now. Nothing while none is due.
     */
    public function runRecounts(): int
    {
        return $this->services()->recounts(function (int $idShop): Services {
            return $this->servicesFor($idShop);
        })->run(time());
    }

    /**
     * A refund of the back office's order page (Partial refund, Standard
     * refund, Return products), before PrestaShop makes its credit slip:
     * refunded through Wajub first (Refund\RefundSync), so that a refund
     * Wajub refuses (more than its payments can refund, an order paid in the
     * other environment or never paid in full, Wajub's own refusal) makes no
     * slip, and the merchant reads why on the order page. Refused after part
     * of it went through, what Wajub refunded is recorded on a slip of its
     * own. A refund given as a voucher, with no credit slip, or of nothing
     * (an amount of 0 or less), gives no money back: nothing for Wajub.
     *
     * The refusal goes to PrestaShop in the form's data (Refund\RefusedRefund),
     * never as an exception of the hook, which PrestaShop 8 swallows once
     * debug mode is off, and then makes the slip: on 8 as on 9, in debug
     * mode or not, PrestaShop makes nothing and shows the module's message.
     *
     * Every refund of the page posts the same form (`cancel_product`), whose
     * route says which refund it is (Refund\SlipRequest), read under the
     * order's lock, which the request keeps until its end once Wajub was
     * asked (Refund\PendingSlips): the same refund posted twice reads what
     * the first refunded. A refund of any of the orders a cart that ships in
     * several packages became is refunded from their payments, under their
     * first order's lock.
     *
     * @param array<string, mixed> $params ['id' => the order's id, 'form_data' => the form's data, by reference]
     *
     * @return void
     */
    public function hookActionBeforeUpdateCancelProductFormHandler($params)
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return;
        }
        $asked = (int) ($params['id'] ?? 0);
        $action = SlipRequest::action();
        if ($asked <= 0 || $action === '' || !is_array($params['form_data'] ?? null) || RefusedRefund::in($params['form_data'])) {
            return;
        }
        $order = new Order($asked);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $services = $this->servicesFor((int) $order->id_shop);
        $t = $services->translator();
        // The orders a cart became go by the first's id: their payments, their lock.
        $idOrder = $services->orders()->leadId($asked);
        if ($order->module !== $this->name && $services->payments()->forOrder($idOrder) === []) {
            return;
        }

        $pending = $this->pendingSlips();
        if (!$pending->lock($idOrder, $services, 10)) {
            $this->refuseRefund($params, $pending, $idOrder, $t->trans('The order is being updated. Try again in a moment.', [], 'Modules.Wajub.Admin'));

            return;
        }
        try {
            // Read under the lock: what an earlier request refunded of the
            // same order is written by now.
            $order = new Order($asked);
            $request = SlipRequest::of($action, $order, $params['form_data']);
            $view = $services->orders()->find($idOrder);
            if ($request === null || $view === null) {
                // PrestaShop refuses it itself, and says why.
                $pending->unlock($idOrder);

                return;
            }
            if (!$request['slip'] || $request['voucher']) {
                $pending->expect($idOrder, $services, []);
                $pending->unlock($idOrder);

                return;
            }
            $answer = $services->refundSync()->refundForSlip($view, $request['amount']);
            if ($answer['status'] === RefundSync::PARTIAL) {
                $services->refundSync()->recordParts($view, $answer['keys']);
            }
        } catch (Throwable $e) {
            // What Wajub made of it is not known: no slip, rather than one
            // a second request might refund again. A part Wajub made is
            // recorded once its webhook says so.
            $pending->unlock($idOrder);
            $services->logger()->error('A refund of the order page could not be made through Wajub', ['order' => $asked, 'error' => $e->getMessage()]);
            $this->refuseRefund($params, $pending, $idOrder, $t->trans('The refund could not be made through Wajub, and no credit slip was made. Try again in a moment. Should Wajub have refunded part of it meanwhile, that part is recorded on the order as a credit slip once Wajub confirms it.', [], 'Modules.Wajub.Admin'));

            return;
        }
        if (in_array($answer['status'], [RefundSync::REFUSED, RefundSync::PARTIAL], true)) {
            $pending->unlock($idOrder);
            $this->refuseRefund($params, $pending, $idOrder, $answer['message']);

            return;
        }
        $pending->expect($idOrder, $services, $answer['keys']);
        if ($answer['keys'] === []) {
            // Nothing Wajub made waits for the slip.
            $pending->unlock($idOrder);
        }
    }

    /**
     * PrestaShop makes no slip of the form's refund, and shows `$message`
     * (Refund\RefusedRefund); a slip it would make all the same is never
     * refunded through Wajub (hookActionOrderSlipAdd()).
     *
     * @param array<string, mixed> $params the hook's
     */
    private function refuseRefund(array &$params, PendingSlips $pending, int $idOrder, string $message): void
    {
        $pending->refuse($idOrder, $message);
        RefusedRefund::refuse($params, $message);
    }

    /**
     * Puts the module last, in every shop, among the modules on the order
     * page's refund forms' hook (REFUND_FORMS_HOOK), as it is once installed
     * after them. There it refunds through Wajub once every other module has
     * had its say (one that refuses the refund refuses it before Wajub gives
     * any money back), and the refusal it hands PrestaShop in the form's
     * data (Refund\RefusedRefund) reaches PrestaShop's handler alone: a
     * module that ran after it would find, instead of the products to
     * refund, an object that throws, or that its array functions refuse (a
     * PHP error, a blank page instead of the module's message).
     */
    public function keepLastOnRefundForms(): void
    {
        $idHook = (int) Hook::getIdByName(self::REFUND_FORMS_HOOK);
        if ($idHook <= 0 || (int) $this->id <= 0) {
            return;
        }
        $db = Db::getInstance();
        $table = '`' . _DB_PREFIX_ . 'hook_module`';
        $rows = $db->executeS('SELECT `id_shop`, `position` FROM ' . $table . ' WHERE `id_hook` = ' . $idHook . ' AND `id_module` = ' . (int) $this->id, true, false);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $shop = (int) $row['id_shop'];
            $mine = (int) $row['position'];
            $last = (int) $db->getValue('SELECT MAX(`position`) FROM ' . $table . ' WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $shop, false);
            if ($mine >= $last) {
                continue;
            }
            // As Module::updatePosition() moves a module to a position.
            $db->execute('UPDATE ' . $table . ' SET `position` = `position` - 1 WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $shop . ' AND `position` > ' . $mine, false);
            $db->execute('UPDATE ' . $table . ' SET `position` = ' . $last . ' WHERE `id_hook` = ' . $idHook . ' AND `id_shop` = ' . $shop . ' AND `id_module` = ' . (int) $this->id, false);
        }
    }

    /**
     * Another module hooks the order page's refund forms: the module goes
     * back to the last place there (keepLastOnRefundForms()).
     *
     * @param array<string, mixed> $params ['object' => the module, 'hook_name' => the hook]
     *
     * @return void
     */
    public function hookActionModuleRegisterHookAfter($params)
    {
        $module = $params['object'] ?? null;
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || strcasecmp((string) ($params['hook_name'] ?? ''), self::REFUND_FORMS_HOOK) !== 0
            || ($module instanceof Module && $module->name === $this->name)) {
            return;
        }
        try {
            $this->keepLastOnRefundForms();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Wajub: the module could not be put last on the refund forms\' hook: ' . $e->getMessage(), 2, null, 'Wajub', 0, true);
        }
    }

    /**
     * PrestaShop adds a credit slip (ObjectModel::add(), before any module
     * hears it made one): the slip of the order page's refund whose money
     * Wajub gave back this request records it now
     * (Refund\PendingSlips::slipAdded()). Another module's actionOrderSlipAdd
     * that fails with a PHP error ends the request before the module's own
     * hook, and before any end of request: the refund is on its slip by
     * then, with a note for the merchant to check what PrestaShop did not
     * write. Nothing for any other slip, nor in a request that sent no
     * refund form.
     *
     * @param array<string, mixed> $params ['object' => the slip]
     *
     * @return void
     */
    public function hookActionObjectOrderSlipAddAfter($params)
    {
        $slip = $params['object'] ?? null;
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || $this->pendingSlips === null || !$slip instanceof OrderSlip || (int) $slip->id <= 0) {
            return;
        }
        $order = new Order((int) $slip->id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $services = $this->servicesFor((int) $order->id_shop);
        try {
            // Under the first of the orders its cart became, as the form's refund.
            $this->pendingSlips->slipAdded($services->orders()->leadId((int) $order->id), (int) $slip->id);
        } catch (Throwable $e) {
            // The slip is being saved: nothing here may stop PrestaShop.
            // actionOrderSlipAdd, or the end of the request, records it then.
            $services->logger()->error('A credit slip could not record the refund Wajub made for it as PrestaShop added it', ['order' => (int) $order->id, 'slip' => (int) $slip->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A credit slip made: the refunds Wajub made for it before
     * (hookActionBeforeUpdateCancelProductFormHandler()) are this slip's
     * since PrestaShop added it (hookActionObjectOrderSlipAddAfter()), and
     * PrestaShop went on with the refund: the note that it may have stopped
     * there is taken back (Refund\PendingSlips). One made another way (a
     * module, PrestaShop's API) is refunded through Wajub now, as
     * PrestaShop's payment modules refund a slip; the slip is made already,
     * so what Wajub refuses is noted for the merchant, never a failure of
     * the slip. One of a refund Wajub refused this request, which PrestaShop
     * should never have made, is noted, never refunded: Wajub may have
     * refunded part of it on a slip of its own already.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderSlipAdd($params)
    {
        $order = $params['order'] ?? null;
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$order instanceof Order || !Validate::isLoadedObject($order)) {
            return;
        }
        $services = $this->servicesFor((int) $order->id_shop);
        try {
            // The slip's order, as one with the other orders its cart became.
            $view = $services->orders()->find((int) $order->id);
            if ($view === null) {
                return;
            }
            $idOrder = $view->id;
            // PrestaShop 9 hands the slip; PrestaShop 8 has just added it.
            $slip = $params['orderSlipCreated'] ?? null;
            $idSlip = $slip instanceof OrderSlip ? (int) $slip->id : $services->orders()->lastSlip($view);
            if ($this->pendingSlips !== null && $this->pendingSlips->expects($idOrder)) {
                $this->pendingSlips->slipMade($idOrder, $idSlip);

                return;
            }
            if ($this->pendingSlips !== null && $this->pendingSlips->refused($idOrder) !== '') {
                $services->refundSync()->noteRefusedSlip($view, $idSlip, $this->pendingSlips->refused($idOrder));

                return;
            }
            if ($order->module !== $this->name && $services->payments()->forOrder($idOrder) === []) {
                return;
            }
            // What the slip gives back, as PrestaShop computed the refund
            // (the order's vouchers taken off when the merchant chose so):
            // a slip of products, their prices; one of an amount alone, that
            // amount.
            $amount = $idSlip > 0 ? $services->orders()->slipGivesBack($idSlip) : 0.0;
            if ($amount <= 0) {
                return;
            }
            $lock = $services->lock();
            $name = 'order:' . $idOrder;
            if (!$lock->acquire($name, 10)) {
                $services->orders()->note($view, 'slip_not_refunded_' . $idSlip, $services->translator()->trans(
                    'Credit slip %slip% (%amount%) was made while the order was being updated: Wajub did not refund it. Refund the customer from the Wajub Dashboard.',
                    ['%slip%' => (string) $idSlip, '%amount%' => $services->money()->format($amount, $view->currency)],
                    'Modules.Wajub.Admin'
                ));

                return;
            }
            try {
                $services->refundSync()->refundSlipMade($services->orders()->find($idOrder) ?? $view, $idSlip, $amount);
            } finally {
                $lock->release($name);
            }
        } catch (Throwable $e) {
            // The slip is made: nothing here may break what PrestaShop does next.
            $services->logger()->error('A credit slip could not be refunded through Wajub', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * An order placed with Wajub is paid: its confirmation e-mail, held as
     * it was placed, goes to the customer now. One the merchant cancels has
     * its payments still open cancelled with Wajub (cancelledByMerchant()).
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $state = $params['newOrderStatus'] ?? null;
        $idOrder = (int) ($params['id_order'] ?? 0);
        if (!$state instanceof OrderState || $idOrder <= 0) {
            return;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }
        if (!$state->paid) {
            if (!PrestaShopOrders::settingState() && (int) $state->id === (int) Configuration::get('PS_OS_CANCELED')) {
                $this->cancelledByMerchant($order);
            }

            return;
        }
        try {
            $this->servicesFor((int) $order->id_shop)->heldConfirmations()->release($idOrder, (string) $order->payment);
        } catch (Throwable $e) {
            $this->servicesFor((int) $order->id_shop)->logger()->error('The order confirmation e-mail could not be sent', ['order' => $idOrder, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The merchant (or another module) cancelled an order placed with Wajub:
     * its payments still open are cancelled with Wajub, so that nobody pays
     * a cancelled order (Order\OpenPayments::cancelWithOrder()), under the
     * order's lock. The order says what became of each. The merchant's state
     * stands, whatever Wajub answers; nothing here may break the change.
     */
    private function cancelledByMerchant(Order $order): void
    {
        $services = $this->servicesFor((int) $order->id_shop);
        try {
            $orders = $services->orders();
            $name = 'order:' . $orders->leadId((int) $order->id);
            $lock = $services->lock();
            if (!$lock->acquire($name, 10)) {
                $services->logger()->info('An order the merchant cancelled is held by another request: its payments are left to it', ['order' => (int) $order->id]);

                return;
            }
            try {
                $view = $orders->find((int) $order->id);
                if ($view === null) {
                    return;
                }
                $done = $services->openPayments()->cancelWithOrder($view);
                $t = $services->translator();
                foreach ($done['cancelled'] as $paymentId) {
                    $orders->note($view, 'merchant_cancelled_' . md5($paymentId), $t->trans('The order was cancelled: Wajub payment %payment%, which nobody had paid, is cancelled with Wajub too, so that it can no longer go through.', ['%payment%' => $paymentId], 'Modules.Wajub.Admin'));
                }
                foreach ($done['refused'] as $paymentId) {
                    $orders->note($view, 'merchant_cancelled_' . md5($paymentId), $t->trans('The order was cancelled, but Wajub would not cancel payment %payment%: it went through, or collected instalments. Its notification says what became of it; money it took for this cancelled order is refunded from the order page or the Wajub Dashboard.', ['%payment%' => $paymentId], 'Modules.Wajub.Admin'));
                }
                foreach ($done['unanswered'] as $paymentId) {
                    $orders->note($view, 'merchant_cancelled_' . md5($paymentId), $t->trans('The order was cancelled, but Wajub did not answer when the shop cancelled payment %payment%: cancel it in the Wajub Dashboard, so that it cannot go through.', ['%payment%' => $paymentId], 'Modules.Wajub.Admin'));
                }
            } finally {
                $lock->release($name);
            }
        } catch (Throwable $e) {
            $services->logger()->error('The payments of an order the merchant cancelled could not be cancelled with Wajub', ['order' => (int) $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Holds the confirmation e-mail of the order validation.php is placing,
     * until the order is paid: false tells PrestaShop not to send it now.
     *
     * @param array<string, mixed> $params
     *
     * @return bool
     */
    public function hookActionEmailSendBefore($params)
    {
        if (!$this->placingOrder || ($params['template'] ?? '') !== HeldConfirmations::TEMPLATE) {
            return true;
        }

        try {
            return !$this->services()->heldConfirmations()->hold($params);
        } catch (Throwable $e) {
            // Sent now, rather than lost.
            return true;
        }
    }

    /**
     * PrestaShop's Cron tasks manager (the cronjobs module) runs the module's
     * scheduled task (Order\Cleanup) at the frequency getCronFrequency() asks.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionCronJob($params)
    {
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return;
        }
        try {
            $this->runScheduledTask();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Wajub: the scheduled task failed: ' . $e->getMessage(), 3, null, 'Wajub', 0, true);
        }
    }

    /**
     * How often the Cron tasks manager runs hookActionCronJob(): every hour,
     * the most often it runs a module's task (-1: every hour, day, month and
     * day of the week).
     *
     * @return array{hour: int, day: int, month: int, day_of_week: int}
     */
    public function getCronFrequency()
    {
        return ['hour' => -1, 'day' => -1, 'month' => -1, 'day_of_week' => -1];
    }

    /**
     * On every page of the back office, for an employee who may open the
     * module's settings: the module's notices (adminNotices()), as the
     * WordPress plugin's. The module's own settings page shows them itself,
     * and PrestaShop 9's legacy pages, drawn without this hook, among their
     * warnings (hookActionAdminControllerInitAfter()).
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayAdminAfterHeader($params)
    {
        if ($this->adminPage() === self::ADMIN_CONTROLLER) {
            return '';
        }
        $notices = $this->adminNotices()['notices'];
        if ($notices === []) {
            return '';
        }
        $this->context->smarty->assign('wajub_notices', $notices);

        return $this->display(__FILE__, 'views/templates/hook/admin-notices.tpl');
    }

    /**
     * PrestaShop 9 draws its legacy pages (the dashboard, carriers, cart
     * rules, statistics…) with a layout that has no displayAdminAfterHeader:
     * there, the module's notices go with the page's own warnings (and its
     * information, for a notice that only informs), which it shows at the
     * top of its content. PrestaShop 8 draws the hook on every page, and its
     * Symfony pages run no legacy controller.
     *
     * @param array<string, mixed> $params ['controller' => the page's AdminController]
     *
     * @return void
     */
    public function hookActionAdminControllerInitAfter($params)
    {
        $controller = $params['controller'] ?? null;
        if (
            !$controller instanceof AdminController
            || $controller->ajax
            || $controller->controller_name === self::ADMIN_CONTROLLER
            || version_compare(_PS_VERSION_, '9.0.0', '<')
        ) {
            return;
        }
        foreach ($this->adminNotices()['notices'] as $notice) {
            $this->context->smarty->assign('wajub_notice', $notice);
            $body = $this->display(__FILE__, 'views/templates/hook/admin-notice.tpl');
            if ($notice['type'] === 'info') {
                $controller->informations[] = $body;
            } else {
                $controller->warnings[] = $body;
            }
        }
    }

    /**
     * "Wajub · Sandbox" in the header of every page of the back office
     * (legacy and Symfony pages, PrestaShop 8 and 9) while the shop takes
     * test payments, for an employee who may open the module's settings, as
     * the plugin's toolbar says it: a merchant trying their own checkout
     * sees whether it takes real money. Live, the normal state, shows
     * nothing. It opens the settings, where Live is chosen.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayBackOfficeTop($params)
    {
        if (!$this->adminNotices()['badge']) {
            return '';
        }
        $this->context->smarty->assign('wajub_badge', [
            'url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], ['wajub_tab' => 'connection']),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin-badge.tpl');
    }

    /**
     * The back office's notices on this page, for this employee, most
     * serious first, and whether "Wajub · Sandbox" shows: worked out once a
     * request, from what is saved (never from Wajub). The notices for an
     * employee who may change the module's settings, the badge for one who
     * may open them, and nothing for anyone else, nor in a page drawn small
     * inside another (`liteDisplaying`: the forms Orders > Add new order
     * opens in a frame). The shop that takes no payment, the keys "Connect
     * with Wajub" created that expire (keyNotices()), and those of
     * Admin\Notices, with the links that fix them, in the scope the back
     * office's shop selector is on.
     *
     * @return array{notices: list<array<string, mixed>>, badge: bool}
     */
    public function adminNotices(): array
    {
        if ($this->adminNotices !== null) {
            return $this->adminNotices;
        }
        $this->adminNotices = ['notices' => [], 'badge' => false];
        $employee = $this->context->employee;
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$employee instanceof Employee || !$this->settingsPageAllowed($employee) || Tools::getIsset('liteDisplaying')) {
            return $this->adminNotices;
        }
        if (!$this->settingsEditable($employee)) {
            try {
                $this->adminNotices['badge'] = (new Notices($this->services()))->sandboxBadge();
            } catch (Throwable $e) {
                // A badge is never worth a broken back office.
            }

            return $this->adminNotices;
        }

        try {
            $services = $this->services();
            $t = $services->translator();
            $notices = new Notices($services);
            $page = $this->adminPage();
            $due = $notices->due($page, (int) $employee->id, static function (): bool {
                return Module::isInstalled('cronjobs') && Module::isEnabled('cronjobs');
            }, time());

            $links = [
                'connection' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], ['wajub_tab' => 'connection']),
                'methods' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], ['wajub_tab' => 'methods']),
                'preferences' => $this->context->link->getAdminLink('AdminPaymentPreferences'),
            ];
            $shown = [];
            foreach ($due as $notice) {
                $shown[] = [
                    'id' => $notice['id'],
                    'type' => $notice['type'],
                    'key' => false,
                    'title' => $notice['title'],
                    'scope' => '',
                    'text' => $notice['text'],
                    'links' => isset($links[$notice['action']]) ? [['url' => $links[$notice['action']], 'label' => $notice['label']]] : [],
                    'hide_url' => $notice['hideable'] ? $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], ['wajub_hide' => $notice['id'], 'wajub_token' => AdminToken::ofContext()]) : '',
                    'copy' => $notice['copy'],
                ];
            }
            foreach ($this->keyNotices() as $key) {
                $shown[] = [
                    'id' => $key['expired'] ? 'key_expired' : 'key_expiring',
                    'type' => $key['expired'] ? 'error' : 'warning',
                    'key' => true,
                    'title' => $key['expired'] ? $t->trans('Your Wajub key has expired.', [], 'Modules.Wajub.Admin') : $t->trans('Your Wajub key expires soon.', [], 'Modules.Wajub.Admin'),
                    'scope' => $key['scope'],
                    'text' => $key['text'],
                    'links' => [['url' => $key['url'], 'label' => $t->trans('Connect with Wajub again', [], 'Modules.Wajub.Admin')]],
                    'hide_url' => '',
                    'copy' => '',
                ];
            }
            // Most serious first, as the plugin: the shop takes no payment,
            // a key that has expired, then the rest in their order, a key
            // that expires soon after a shop the public uses in Sandbox.
            $rank = ['no_payments' => 0, 'key_expired' => 1, 'sandbox_live_shop' => 2, 'key_expiring' => 3];
            $order = array_keys($shown);
            usort($order, static function (int $a, int $b) use ($shown, $rank): int {
                return [$rank[$shown[$a]['id']] ?? 4, $a] <=> [$rank[$shown[$b]['id']] ?? 4, $b];
            });
            $this->adminNotices = [
                'notices' => array_map(static function (int $index) use ($shown): array {
                    return $shown[$index];
                }, $order),
                'badge' => $notices->sandboxBadge(),
            ];
        } catch (Throwable $e) {
            // A notice is never worth a broken back office.
            $this->adminNotices = ['notices' => [], 'badge' => false];
        }

        return $this->adminNotices;
    }

    /**
     * Hides one of the back office's notices from the context's employee
     * for a week (Admin\Notices::hide()): false for one that cannot be.
     */
    public function hideAdminNotice(string $id): bool
    {
        $employee = $this->context->employee;
        if (!$employee instanceof Employee) {
            return false;
        }

        return (new Notices($this->services()))->hide((int) $employee->id, $id, time());
    }

    /**
     * The notices of keys that expire within two weeks, or have, that a shop
     * pays with (Connection\KeyReminders), for an employee who may change the
     * module's settings, and, in a multistore, works on such a shop: empty
     * for anyone else. The e-mail of each key goes from here too, for a shop
     * whose scheduled task does not run: once a key, whichever page sends
     * it.
     *
     * @return list<array{expired: bool, scope: string, text: string, url: string}>
     */
    public function keyNotices(): array
    {
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$this->settingsEditable($this->context->employee)) {
            return [];
        }

        try {
            $reminders = $this->keyReminders();
            $notices = [];
            $due = $reminders->due();
            $employee = $this->context->employee;
            $worksOn = static function (int $idShop) use ($employee): bool {
                return $employee instanceof Employee && $employee->hasAuthOnShop($idShop);
            };
            foreach (KeyReminders::shownTo($due, $worksOn) as $key) {
                $notices[] = [
                    'expired' => $key['state'] === 'expired',
                    'scope' => Shop::isFeatureActive() ? $this->scopeName($key['scope']) : '',
                    'text' => $key['warning'],
                    'url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER, true, [], Shop::isFeatureActive() ? ['setShopContext' => self::shopContextOf($key['scope'])] : []),
                ];
            }
            if ($due !== []) {
                $reminders->remind();
            }
        } catch (Throwable $e) {
            // A notice is never worth a broken back office.
            return [];
        }

        return $notices;
    }

    /**
     * PrestaShop's GDPR module exports what the module keeps of a customer:
     * the Wajub payments of their orders (Privacy\CustomerData), as JSON.
     *
     * @param array<string, mixed> $params the customer, as the GDPR module hands it
     *
     * @return string
     */
    public function hookActionExportGDPRData($params)
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return (string) json_encode([]);
        }

        return (string) json_encode($this->services()->customerData()->export(self::gdprCustomerIds($params)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * PrestaShop's GDPR module erases a customer: the confirmation e-mails
     * held for their orders go, and their payments are unlinked from them
     * (Privacy\CustomerData). What Wajub keeps of a payer is erased from
     * the Wajub Dashboard, or by Wajub.
     *
     * @param array<string, mixed> $params the customer, as the GDPR module hands it
     *
     * @return string
     */
    public function hookActionDeleteGDPRCustomer($params)
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return (string) json_encode(true);
        }
        $customerIds = self::gdprCustomerIds($params);
        $orderIds = [];
        foreach ($customerIds as $idCustomer) {
            foreach ((array) Order::getCustomerOrders($idCustomer, true) as $order) {
                $orderIds[] = (int) ($order['id_order'] ?? 0);
            }
        }
        try {
            $this->services()->customerData()->erase($customerIds, $orderIds);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Wajub: the customer\'s data could not be erased: ' . $e->getMessage(), 3, null, 'Customer', $customerIds[0] ?? 0, true);

            return (string) json_encode($this->trans('Wajub could not erase this customer’s data. Try again.', [], 'Modules.Wajub.Admin'));
        }

        return (string) json_encode(true);
    }

    /**
     * The customer's ids from what the GDPR module hands
     * (Privacy\CustomerData::customerIds()), its customers looked up by
     * e-mail address with PrestaShop's, guests included.
     *
     * @param mixed $params
     *
     * @return list<int>
     */
    private static function gdprCustomerIds($params): array
    {
        return CustomerData::customerIds($params, static function (string $email): array {
            if (!Validate::isEmail($email)) {
                return [];
            }
            $ids = [];
            foreach ((array) Customer::getCustomersByEmail($email) as $customer) {
                $ids[] = (int) ($customer['id_customer'] ?? 0);
            }

            return $ids;
        });
    }

    /**
     * What the back office calls a scope: "All shops", a group's name, a
     * shop's name; "All shops" in the language of `t` when given (an
     * e-mail), else the page's.
     */
    private function scopeName(string $slug, ?Translator $t = null): string
    {
        $scope = Scope::fromSlug($slug);
        if ($scope === null || $scope->isAllShops()) {
            return $t !== null ? $t->trans('All shops', [], 'Modules.Wajub.Admin') : $this->trans('All shops', [], 'Modules.Wajub.Admin');
        }
        if ($scope->idShop !== null) {
            $shop = Shop::getShop($scope->idShop);

            return is_array($shop) ? (string) $shop['name'] : $slug;
        }
        $group = new ShopGroup((int) $scope->idShopGroup);

        return Validate::isLoadedObject($group) ? (string) $group->name : $slug;
    }

    /** The back office's shop selector's value for a scope: '', 'g-2', 's-3'. */
    private static function shopContextOf(string $slug): string
    {
        $scope = Scope::fromSlug($slug);
        if ($scope === null || $scope->isAllShops()) {
            return '';
        }

        return $scope->idShop !== null ? 's-' . $scope->idShop : 'g-' . (int) $scope->idShopGroup;
    }

    /**
     * Whether the module takes the cart's currency (Payment > Preferences).
     */
    public function checkCurrency(Cart $cart): bool
    {
        $currencies = $this->getCurrency((int) $cart->id_currency);
        if (!is_array($currencies)) {
            return false;
        }
        foreach ($currencies as $currency) {
            if ((int) $currency['id_currency'] === (int) $cart->id_currency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wajub.js, or, on a shop in debug mode whose configuration defines
     * _WAJUB_JS_URL_ (config/defines_custom.inc.php), a stand-in a developer
     * serves to test the checkout without Wajub, as Services::apiUrl() does
     * for the API. Never on a shop the public uses.
     */
    public function wajubJsUrl(): string
    {
        if (defined('_WAJUB_JS_URL_') && defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            $url = (string) constant('_WAJUB_JS_URL_');
            if (preg_match('#^https?://[^/\s]+#', $url) === 1) {
                return $url;
            }
        }

        return self::WAJUB_JS;
    }

    /**
     * The refunds of the order page's forms of this request, finished at its
     * end (finishRefundForms()).
     */
    private function pendingSlips(): PendingSlips
    {
        if ($this->pendingSlips === null) {
            $this->pendingSlips = new PendingSlips();
        }

        return $this->pendingSlips;
    }

    /**
     * The end of a request of the back office (Symfony's kernel.terminate,
     * Refund\RefundFormsEnd, once the answer is sent): the refunds of the
     * order page's forms that no credit slip of PrestaShop's records are
     * recorded, and the orders' locks let go (PendingSlips::finish()), with
     * whether the request answered without an error (`$answered`). Nothing
     * when no refund form was sent.
     */
    public function finishRefundForms(bool $answered = true): void
    {
        if ($this->pendingSlips !== null) {
            $this->pendingSlips->finish($answered);
        }
    }

    private function phpVersionMessage(): string
    {
        return $this->trans('Wajub needs PHP 8.1 or later. This server runs PHP %version%: ask your web host to update it.', ['%version%' => PHP_VERSION], 'Modules.Wajub.Admin');
    }
}

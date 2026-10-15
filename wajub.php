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

use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Wajub\PrestaShop\Admin\AdminToken;
use Wajub\PrestaShop\Admin\OrderPanel;
use Wajub\PrestaShop\Checkout\CheckoutStrings;
use Wajub\PrestaShop\Checkout\PrestaShopCarts;
use Wajub\PrestaShop\Connection\KeyReminders;
use Wajub\PrestaShop\Install\OrderStates;
use Wajub\PrestaShop\Install\Schema;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Payment\ChannelLabels;
use Wajub\PrestaShop\Payment\LogoStrip;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Privacy\CustomerData;
use Wajub\PrestaShop\Refund\PendingSlips;
use Wajub\PrestaShop\Refund\RefundSync;
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
    public const VERSION = '1.0.0';

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
     * - actionFrontControllerSetMedia: the payment form's script and style, on the checkout only;
     * - displayAdminOrderMain: the Wajub panel of the back office's order page;
     * - actionAdminControllerSetMedia: that panel's script and style, on the order page only;
     * - actionBeforeUpdateCancelProductFormHandler: a refund of the order page
     *   (Partial refund, Standard refund, Return products), refunded through
     *   Wajub before PrestaShop makes its credit slip, or refused;
     * - actionOrderSlipAdd: the credit slip made, which records those refunds,
     *   or one made another way, refunded through Wajub then;
     * - actionOrderStatusPostUpdate: an order paid (its confirmation e-mail, held
     *   until then), cancelled or refunded in the back office;
     * - actionEmailSendBefore: the confirmation e-mail of an order being placed,
     *   held until it is paid (Order\HeldConfirmations);
     * - actionCronJob: PrestaShop's Cron tasks manager runs the module's
     *   scheduled task (Order\Cleanup, Connection\KeyReminders) every hour,
     *   when it is installed;
     * - displayAdminAfterHeader: on every page of the back office, a key that
     *   expires within two weeks, or has (Connection\KeyReminders);
     * - actionAdminControllerInitAfter: the same, on PrestaShop 9's legacy
     *   pages (the dashboard among them), drawn without displayAdminAfterHeader;
     * - actionExportGDPRData, actionDeleteGDPRCustomer: PrestaShop's GDPR
     *   module exports, or erases, what the module keeps of a customer
     *   (Privacy\CustomerData).
     */
    public const HOOKS = [
        'paymentOptions',
        'displayPaymentReturn',
        'displayOrderDetail',
        'actionFrontControllerSetMedia',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        'actionBeforeUpdateCancelProductFormHandler',
        'actionOrderSlipAdd',
        'actionOrderStatusPostUpdate',
        'actionEmailSendBefore',
        'actionCronJob',
        'displayAdminAfterHeader',
        'actionAdminControllerInitAfter',
        'actionExportGDPRData',
        'actionDeleteGDPRCustomer',
    ];

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
     * slip PrestaShop makes next records them (hookActionOrderSlipAdd()),
     * or the end of the request does.
     *
     * @var PendingSlips|null
     */
    private $pendingSlips;

    /** @var Services|null */
    private $services;

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
        $this->ps_versions_compliancy = ['min' => self::MIN_PS_VERSION, 'max' => _PS_VERSION_];
        $this->controllers = ['webhook', 'prepare', 'validation', 'status', 'return', 'release', 'pay', 'cron'];
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
        $this->confirmUninstall = $this->trans('Uninstalling removes the Wajub settings and keys. Orders, their payments and the record of their Wajub payments stay. Continue?', [], 'Modules.Wajub.Admin');

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

        // For all shops, as a first install is: each shop, group or "All
        // shops" then changes what it needs from the module's page.
        foreach (Settings::defaults() as $key => $value) {
            // Kept when a value is there already: an earlier install's,
            // which a reinstall must not undo.
            if ((string) Configuration::getGlobalValue($key) === '') {
                Configuration::updateGlobalValue($key, $value);
            }
        }

        return true;
    }

    /**
     * Removes the settings, the keys and the module's working tables. Kept,
     * as the WordPress plugin keeps what it wrote on orders: the record of
     * each order's Wajub payments and refunds (Schema::KEPT), which is the
     * merchant's order history, and which PrestaShop's "Reset" (an
     * uninstall, then an install) would otherwise lose; and an order state
     * an order went through, with its id (OrderStates::uninstall()). Never
     * a key in Wajub: the keys "Connect with Wajub" created stay in the
     * Wajub Dashboard, where the merchant deletes them.
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
        $logos = $this->getLocalPath() . 'views/img/methods';
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
            $logo = LogoStrip::dataUri($option['logos'], $logos);
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

        $panel = '';
        $states = [$services->settings()->awaitingState(), $services->settings()->partialState(), (int) Configuration::get('PS_OS_ERROR')];
        if (!$view->paid && in_array($view->stateId, $states, true) && $current !== null) {
            $line = $services->statusAnswer()->payerLine($view, $current->environment);
            $waiting = $line === '' || !$current->isCounted();
            // A payment the payer may still approve on their phone: Mobile
            // Money's form, or Wajub's page with Mobile Money or a method
            // not known yet. Never a card's.
            $phone = $current->method === Methods::MOBILE_MONEY
                || ($current->mode === PaymentRecord::MODE_HOSTED && $current->method !== Methods::CARD && !ChannelLabels::isCard($current->channel));
            $panel = $waiting ? 'waiting' : 'partial';
            $this->context->smarty->assign('wajub_status', [
                'panel' => $panel,
                'phone' => $phone,
                'paid_so_far' => $line,
                'status_url' => $services->storefront()->status($view),
                'release_url' => $services->storefront()->release($view),
                // Where the payer pays what is left (the script offers it
                // once the order is found part paid, the partial panel now).
                'pay_url' => $services->storefront()->pay($view),
                'can_pay' => !$waiting && $services->statusAnswer()->payable($view),
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
     * the order confirmation page. Nowhere else.
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
            // Linked from the panel itself: the order page is a Symfony page
            // on PrestaShop 8 and 9, whose assets differ between them.
            'css' => $this->getPathUri() . 'views/css/admin-order.css?v=' . $this->version,
            'js' => $this->getPathUri() . 'views/js/admin-order.js?v=' . $this->version,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin-order.tpl');
    }

    /**
     * Nothing yet: the order page's Wajub panel links its own style and
     * script (hookDisplayAdminOrderMain()).
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionAdminControllerSetMedia($params)
    {
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
     * Every refund of the page posts the same form (`cancel_product`), whose
     * route says which refund it is (Refund\SlipRequest), read under the
     * order's lock, which the request keeps until its end once Wajub was
     * asked (Refund\PendingSlips): the same refund posted twice reads what
     * the first refunded.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     *
     * @throws ModuleErrorException the refund refused: PrestaShop shows its message, and makes no slip
     */
    public function hookActionBeforeUpdateCancelProductFormHandler($params)
    {
        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return;
        }
        $idOrder = (int) ($params['id'] ?? 0);
        $action = SlipRequest::action();
        if ($idOrder <= 0 || $action === '') {
            return;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $services = $this->servicesFor((int) $order->id_shop);
        if ($order->module !== $this->name && $services->payments()->forOrder($idOrder) === []) {
            return;
        }

        $pending = $this->pendingSlips();
        if (!$pending->lock($idOrder, $services, 10)) {
            throw new ModuleErrorException($services->translator()->trans('The order is being updated. Try again in a moment.', [], 'Modules.Wajub.Admin'));
        }
        try {
            // Read under the lock: what an earlier request refunded of the
            // same order is written by now.
            $order = new Order($idOrder);
            $request = SlipRequest::of($action, $order, is_array($params['form_data'] ?? null) ? $params['form_data'] : []);
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
            $pending->unlock($idOrder);

            throw $e;
        }
        if (in_array($answer['status'], [RefundSync::REFUSED, RefundSync::PARTIAL], true)) {
            $pending->unlock($idOrder);

            throw new ModuleErrorException($answer['message']);
        }
        $pending->expect($idOrder, $services, $answer['keys']);
        if ($answer['keys'] === []) {
            // Nothing Wajub made waits for the slip.
            $pending->unlock($idOrder);
        }
    }

    /**
     * A credit slip made: the refunds Wajub made for it before
     * (hookActionBeforeUpdateCancelProductFormHandler()) are recorded as
     * this slip's (Refund\PendingSlips). One made another way (a module, PrestaShop's API) is
     * refunded through Wajub now, as PrestaShop's payment modules refund a
     * slip; the slip is made already, so what Wajub refuses is noted for the
     * merchant, never a failure of the slip.
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
        $idOrder = (int) $order->id;
        $services = $this->servicesFor((int) $order->id_shop);
        try {
            $view = $services->orders()->find($idOrder);
            if ($view === null) {
                return;
            }
            // PrestaShop 9 hands the slip; PrestaShop 8 has just added it.
            $slip = $params['orderSlipCreated'] ?? null;
            $idSlip = $slip instanceof OrderSlip ? (int) $slip->id : $services->orders()->lastSlip($view);
            if ($this->pendingSlips !== null && $this->pendingSlips->expects($idOrder)) {
                $this->pendingSlips->slipMade($idOrder, $idSlip);

                return;
            }
            if ($order->module !== $this->name && $services->payments()->forOrder($idOrder) === []) {
                return;
            }
            $slip = $slip instanceof OrderSlip ? $slip : new OrderSlip($idSlip);
            if (!Validate::isLoadedObject($slip)) {
                return;
            }
            // A slip of products refunds their prices; one of an amount alone, that amount.
            $amount = ((float) $slip->total_products_tax_incl > 0 ? (float) $slip->total_products_tax_incl : (float) $slip->amount) + (float) $slip->total_shipping_tax_incl;
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
            $services->logger()->error('A credit slip could not be refunded through Wajub', ['order' => $idOrder, 'error' => $e->getMessage()]);
        }
    }

    /**
     * An order placed with Wajub is paid: its confirmation e-mail, held as
     * it was placed, goes to the customer now.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $state = $params['newOrderStatus'] ?? null;
        $idOrder = (int) ($params['id_order'] ?? 0);
        if (!$state instanceof OrderState || !$state->paid || $idOrder <= 0) {
            return;
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return;
        }
        try {
            $this->servicesFor((int) $order->id_shop)->heldConfirmations()->release($idOrder, (string) $order->payment);
        } catch (Throwable $e) {
            $this->servicesFor((int) $order->id_shop)->logger()->error('The order confirmation e-mail could not be sent', ['order' => $idOrder, 'error' => $e->getMessage()]);
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
            $this->runCleanup();
            $this->runReminders();
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
     * module's settings: each key "Connect with Wajub" created that a shop
     * pays with and that expires within two weeks, or has, with the link
     * that connects again (keyNotices(), as the WordPress plugin's notices).
     * The module's own settings page shows them itself, and PrestaShop 9's
     * legacy pages, drawn without this hook, among their warnings
     * (hookActionAdminControllerInitAfter()).
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function hookDisplayAdminAfterHeader($params)
    {
        $controller = $this->context->controller;
        if ($controller instanceof AdminController && $controller->controller_name === self::ADMIN_CONTROLLER) {
            return '';
        }
        $notices = $this->keyNotices();
        if ($notices === []) {
            return '';
        }
        $this->context->smarty->assign('wajub_key_notices', $notices);

        return $this->display(__FILE__, 'views/templates/hook/admin-key-notice.tpl');
    }

    /**
     * PrestaShop 9 draws its legacy pages (the dashboard, carriers, cart
     * rules, statistics…) with a layout that has no displayAdminAfterHeader:
     * there, the notices of keys that expire go with the page's own warnings,
     * which it shows at the top of its content. PrestaShop 8 draws the hook
     * on every page, and its Symfony pages run no legacy controller.
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
        foreach ($this->keyNotices() as $notice) {
            $this->context->smarty->assign('wajub_key_notice', $notice);
            $controller->warnings[] = $this->display(__FILE__, 'views/templates/hook/admin-key-warning.tpl');
        }
    }

    /**
     * The notices of keys that expire within two weeks, or have, that a shop
     * pays with (Connection\KeyReminders), for an employee who may open the
     * module's settings, and, in a multistore, works on such a shop: empty
     * for anyone else. The e-mail of each key goes from here too, for a shop
     * whose scheduled task does not run: once a key, whichever page sends
     * it.
     *
     * @return list<array{expired: bool, scope: string, text: string, url: string}>
     */
    public function keyNotices(): array
    {
        if (!$this->active || PHP_VERSION_ID < self::MIN_PHP_VERSION_ID || !$this->settingsPageAllowed($this->context->employee)) {
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
     * end (PendingSlips::finish()).
     */
    private function pendingSlips(): PendingSlips
    {
        if ($this->pendingSlips === null) {
            $this->pendingSlips = new PendingSlips();
            register_shutdown_function([$this->pendingSlips, 'finish']);
        }

        return $this->pendingSlips;
    }

    private function phpVersionMessage(): string
    {
        return $this->trans('Wajub needs PHP 8.1 or later. This server runs PHP %version%: ask your web host to update it.', ['%version%' => PHP_VERSION], 'Modules.Wajub.Admin');
    }
}

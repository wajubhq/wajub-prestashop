<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

/*
 * Checks run inside a local shop (tests/integration/run.sh copies this file
 * there and runs it from the shop's root, as www-data):
 *
 *     php checks.php installed [name]  the module as install() left it, and its
 *                                    adapters on the shop's own database,
 *                                    Configuration, encryption and translator;
 *                                    `name`: the "Awaiting" state's name the
 *                                    merchant gave it, kept
 *     php checks.php webhook-secret  saves signing secrets (sandbox, then live
 *                                    if given) and a sandbox key, and prints
 *                                    the webhook address
 *     php checks.php event <id>      prints what the shop noted of a webhook
 *                                    event: its status, or "none"
 *     php checks.php saved-forms     what the forms of tests/integration/run.sh
 *                                    saved, read back through Settings
 *     php checks.php multistore-on   turns multistore on with a second shop
 *                                    (the module enabled in it), prints its id
 *     php checks.php multistore-check <id>  the settings saved in that shop's
 *                                    context are its own, the others' unchanged
 *     php checks.php multistore-off <id>    removes that shop and its settings,
 *                                    and turns multistore off again
 *     php checks.php order-history   pretends an order went through the
 *                                    "Awaiting" state (add | remove)
 *     php checks.php rename-state <name> [iso]  renames the "Awaiting" state, in
 *                                    one language or all, as a merchant may
 *     php checks.php forget-state-id forgets the id kept for the "Awaiting"
 *                                    state, as an uninstall of an older version did
 *     php checks.php state-named <name> <iso> <id>  the "Awaiting" state has
 *                                    that name, and is state <id>, not a second one
 *     php checks.php payment-record  a Wajub payment of an order (add | check | remove)
 *     php checks.php uninstalled     what uninstall() left: the working tables
 *                                    and the settings gone, the record of payments
 *                                    and refunds kept, the order states an order
 *                                    used kept, marked deleted, with their ids,
 *                                    the others gone
 *     php checks.php install-fails   an install whose hooks cannot be registered
 *                                    is undone: nothing left installed
 *     php checks.php page-access     who may open the settings page, and what the
 *                                    Configure button says to the others
 *     php checks.php payment-setup <secret>  a shop ready to take payments:
 *                                    Sandbox, a sandbox key and signing secret,
 *                                    every method on; prints the webhook address
 *     php checks.php order <id>      the order as JSON: its state, its payments,
 *                                    its Wajub payments and notes
 *     php checks.php orders-of-cart <id>  the ids of the orders of a cart
 *     php checks.php cart <id>       the cart as JSON: its products, its order
 *     php checks.php states          the ids of the states the module moves orders to
 *     php checks.php held <id_order> whether the order's confirmation e-mail is
 *                                    held until it is paid: "held" or "none"
 *     php checks.php returns <0|1>   product returns off or on (PS_ORDER_RETURN)
 *     php checks.php deliver <id_order>  the order marked delivered, as the merchant does
 *     php checks.php failing-hook <write|first|remove>  a module whose
 *                                    actionOrderSlipAdd fails: its files written,
 *                                    run before Wajub's once installed (by
 *                                    bin/console), its files removed
 *     php checks.php multistore-url-on <domain>  multistore on with a second
 *                                    shop at an address of its own (`domain`,
 *                                    such as 127.0.0.1:8082), the first shop's
 *                                    data copied to it; prints its id
 *     php checks.php scope-connection <slug> <key> <secret>  a sandbox key and
 *                                    signing secret saved for that scope
 *                                    ('all', 's2'); prints its webhook address
 *     php checks.php environment <slug> <sandbox|live> [live key|none]  the
 *                                    environment saved for that scope, and a
 *                                    live key for it when given (none: removed)
 *     php checks.php webhook-url <slug>  the webhook address of that scope
 *     php checks.php health <slug>   the state of that scope's webhook
 *     php checks.php order-to-shop <id_order> <id_shop>  the order, and its
 *                                    Wajub payments, moved to that shop
 *     php checks.php expiring-key <days> [lang iso]  the key all shops pay
 *                                    with, as "Connect with Wajub" gave it,
 *                                    expiring in that many days; with a
 *                                    language, the shop's default language set
 *     php checks.php reminded        when the e-mail of that key went, or 0
 *     php checks.php mail <on port|off>  PrestaShop's e-mails sent to an SMTP
 *                                    server on the host at that port, or off
 *     php checks.php gdpr <email>    what PrestaShop's GDPR module gets of
 *                                    that customer, exported then erased
 *
 * Prints one line per check, and exits 1 when one failed.
 */

$mode = $argv[1] ?? 'installed';
$argument = $argv[2] ?? '';

define('_PS_ADMIN_DIR_', getcwd() . '/admin-dev');
require getcwd() . '/config/config.inc.php';
// The module's classes, installed or not.
require_once getcwd() . '/modules/wajub/wajub.php';

use Wajub\PrestaShop\Install\OrderStates;
use Wajub\PrestaShop\Install\Schema;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\PhpEncryptionCipher;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Storage\DbStateStore;
use Wajub\PrestaShop\Support\ShopTranslator;
use Wajub\PrestaShop\Webhook\DbEventStore;
use Wajub\PrestaShop\Webhook\EventStore;

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function tableExists(string $table): bool
{
    return (bool) Db::getInstance()->getValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \'' . pSQL(_DB_PREFIX_ . $table) . '\'', false);
}

/** The ids of the module's order states, kept or not in the configuration. */
function stateIds(): array
{
    return array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_order_state FROM `' . _DB_PREFIX_ . 'order_state` WHERE module_name = \'wajub\' ORDER BY id_order_state', true, false) ?: [], 'id_order_state'));
}

switch ($mode) {
    case 'installed':
        $module = Module::getInstanceByName('wajub');
        check($module instanceof Wajub && Module::isInstalled('wajub') && Module::isEnabled('wajub'), 'module installed and enabled (' . _PS_VERSION_ . ', PHP ' . PHP_VERSION . ')');
        foreach (Wajub::HOOKS as $hook) {
            check($module->isRegisteredInHook($hook), "hook {$hook} registered");
        }
        $tab = (int) Tab::getIdFromClassName(Wajub::ADMIN_CONTROLLER);
        $tabObject = new Tab($tab);
        check($tab > 0 && $tabObject->module === 'wajub' && !$tabObject->active, 'back office tab ' . Wajub::ADMIN_CONTROLLER . ', hidden from the menu');
        foreach (Schema::TABLES as $table) {
            check(tableExists($table), "table {$table}");
        }
        foreach (OrderStates::STATES as $key => $definition) {
            $id = (int) Configuration::getGlobalValue($definition['config']);
            $state = new OrderState($id);
            $fr = (int) Language::getIdByIso('fr');
            $en = (int) Language::getIdByIso('en');
            check(Validate::isLoadedObject($state) && $state->module_name === 'wajub' && !$state->deleted && !$state->paid && !$state->logable && !$state->send_email, "order state {$key} (#{$id}): the module's, not paid, no e-mail");
            if ($key === 'awaiting' && $argument !== '') {
                check(($state->name[$en] ?? '') === $argument && ($state->name[$fr] ?? '') === $argument, "order state {$key}: the name the merchant gave it is kept ({$argument})");
            } else {
                check(($state->name[$en] ?? '') === $definition['names']['en'] && ($state->name[$fr] ?? '') === $definition['names']['fr'], "order state {$key}: named in English and French");
            }
            check(is_file(_PS_ORDER_STATE_IMG_DIR_ . $id . '.gif'), "order state {$key}: its icon");
        }
        foreach (Settings::defaults() as $key => $value) {
            check(Configuration::getGlobalValue($key) === $value, "default {$key} = {$value}");
        }

        $services = new Services(Scope::fromContext(), '1.0.0');
        // The state store on the shop's database.
        $store = new DbStateStore();
        $store->set('wajub_it:value', ['a' => 1, 'b' => '<é>'], 60);
        check($store->get('wajub_it:value') === ['a' => 1, 'b' => '<é>'], 'state: a value round trip, as JSON');
        check($store->add('wajub_it:lock', 1, 30) === true && $store->add('wajub_it:lock', 1, 30) === false, 'state: add() takes a name once');
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'wajub_state` SET expires_at = ' . (time() - 1) . ' WHERE name = \'wajub_it:lock\'');
        check($store->get('wajub_it:lock') === null && $store->add('wajub_it:lock', 2, 30) === true, 'state: an expired value is none, and add() takes its place');
        check($store->increment('wajub_it:count', 60) === 1 && $store->increment('wajub_it:count', 60) === 2 && $store->increment('wajub_it:count', 60) === 3, 'state: a counter counts');
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'wajub_state` SET expires_at = ' . (time() - 1) . ' WHERE name = \'wajub_it:count\'');
        check($store->increment('wajub_it:count', 60) === 1, 'state: a counter whose time ran out starts again');
        $long = str_repeat('n', 300);
        $store->set($long, 'long');
        check($store->get($long) === 'long', 'state: a name longer than the key is kept under a hash of itself');
        foreach (['wajub_it:value', 'wajub_it:lock', 'wajub_it:count', $long] as $name) {
            $store->delete($name);
        }
        check($store->get('wajub_it:value') === null, 'state: deleted');

        // The webhook's events.
        $events = new DbEventStore();
        $id = 'evt_test_it_' . bin2hex(random_bytes(4));
        check($events->claim($id, 1, 'sandbox', 'payment.succeeded', 'trx_test_1') === EventStore::CLAIMED, 'events: a new event is claimed');
        check($events->claim($id, 1, 'sandbox', 'payment.succeeded', 'trx_test_1') === EventStore::BUSY, 'events: while it is acted on, another delivery waits');
        $events->finish($id, EventStore::FAILED, 'boom');
        check($events->claim($id, 1, 'sandbox', 'payment.succeeded', 'trx_test_1') === EventStore::CLAIMED, 'events: a failed event is claimed again');
        $events->finish($id, EventStore::PROCESSED);
        check($events->claim($id, 1, 'sandbox', 'payment.succeeded', 'trx_test_1') === EventStore::DUPLICATE, 'events: a processed one is a duplicate');
        $events->release($id);
        check($events->claim($id, 2, 'sandbox', 'payment.succeeded', 'trx_test_1') === EventStore::DUPLICATE, 'events: release() never forgets an event acted on');
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'wajub_event` WHERE event_id = \'' . pSQL($id) . '\'');
        check($events->claim($id, 1, 'live', 'payment.succeeded', 'trx_live_1') === EventStore::CLAIMED, 'events: one a shop does not act on is claimed...');
        $events->release($id);
        check($events->claim($id, 2, 'live', 'payment.succeeded', 'trx_live_1') === EventStore::CLAIMED, 'events: ...then released: another shop claims it, as if it never came');
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'wajub_event` WHERE event_id = \'' . pSQL($id) . '\'');

        // Settings on Configuration, and secrets on PrestaShop's encryption.
        $settings = $services->settings();
        $key = 'sk_test.integration' . str_repeat('k', 80);
        $settings->setSecretKey('sandbox', $key);
        $raw = (string) Db::getInstance()->getValue('SELECT value FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'WAJUB_SECRET_KEY_SANDBOX\'', false);
        check($raw !== '' && str_starts_with($raw, 'v1:') && !str_contains($raw, 'integration'), 'settings: a secret key is kept encrypted');
        Configuration::resetStaticCache();
        check((new Services(Scope::fromContext(), '1.0.0'))->settings()->secretKey('sandbox') === $key, 'settings: and read back with the shop\'s key, from the database');
        check((new PhpEncryptionCipher())->decrypt('v1:' . base64_encode('nothing')) === null, 'settings: a value the shop did not encrypt reads as none');
        $settings->setSecretKey('sandbox', '');
        $languages = Language::getLanguages(false);
        $ids = array_map('intval', array_column($languages, 'id_lang'));
        $settings->setMethodTexts('card', array_fill_keys($ids, 'Carte'), array_fill_keys($ids, "Ligne 1\nLigne 2 & <b>gras</b>"));
        Configuration::resetStaticCache();
        $reread = (new Services(Scope::fromContext(), '1.0.0'))->settings();
        check($reread->methodTitle('card', $ids[0]) === 'Carte' && $reread->methodDescription('card', $ids[0]) === "Ligne 1\nLigne 2 & <b>gras</b>", 'settings: a description keeps its lines through Configuration');
        $settings->setMethodTexts('card', array_fill_keys($ids, ''), array_fill_keys($ids, null));

        // The shop.
        $url = $services->shop()->webhookUrl();
        check(str_contains($url, 'module/wajub/webhook') || str_contains($url, 'fc=module'), 'shop: the webhook address ' . $url);
        check($services->shop()->isProduction() === !_PS_MODE_DEV_, 'shop: a shop in debug mode is a copy, which never revokes a key');

        // The French catalogue the module ships, as PrestaShop loads it for
        // a page in French.
        $french = Context::getContext()->getTranslatorFromLocale('fr-FR');
        check($french->trans('Card', [], 'Modules.Wajub.Shop') === 'Carte bancaire', 'translations: the shop\'s wordings in French (' . $french->trans('Card', [], 'Modules.Wajub.Shop') . ')');
        check($french->trans('Connect with Wajub', [], 'Modules.Wajub.Admin') === 'Connexion avec Wajub', 'translations: the back office\'s wordings in French');
        check($french->trans('Connected to %business% in %environment%. The key is saved in this shop.', ['%business%' => 'Boutique', '%environment%' => 'Test'], 'Modules.Wajub.Admin') === 'Connectée à Boutique en Test. La clé est enregistrée dans cette boutique.', 'translations: named parameters replaced');
        check(MethodTexts::defaultTitle('card', new ShopTranslator()) === 'Card', 'translations: English stays the source');
        break;

    case 'webhook-secret':
        $settings = (new Services(Scope::fromContext(), '1.0.0'))->settings();
        $settings->setWebhookSecret('sandbox', $argument !== '' ? $argument : 'whsec_test_integration');
        if (($argv[3] ?? '') !== '') {
            $settings->setWebhookSecret('live', $argv[3]);
        }
        $settings->setSecretKey('sandbox', 'sk_test.integration' . str_repeat('k', 80));
        echo (new Services(Scope::fromContext(), '1.0.0'))->shop()->webhookUrl() . "\n";
        exit(0);

    case 'payment-setup':
        $services = new Services(Scope::fromContext(), '1.0.0');
        $settings = $services->settings();
        $settings->setEnvironment('sandbox');
        $settings->setSecretKey('sandbox', 'sk_test.integration' . str_repeat('k', 80));
        $settings->setWebhookSecret('sandbox', $argument !== '' ? $argument : 'whsec_test_integration');
        foreach (['mobile_money', 'card', 'other'] as $kind) {
            $settings->setMethodEnabled($kind, true);
        }
        echo $services->shop()->webhookUrl() . "\n";
        exit(0);

    case 'order':
        $order = new Order((int) $argument);
        $db = Db::getInstance();
        $payments = $db->executeS('SELECT amount, transaction_id, payment_method FROM `' . _DB_PREFIX_ . 'order_payment` WHERE order_reference = \'' . pSQL((string) $order->reference) . '\' ORDER BY id_order_payment', true, false) ?: [];
        $wajub = $db->executeS('SELECT payment_id, reference, id_cart, method, mode, state, status, amount, counted, id_order_payment, environment, metadata FROM `' . _DB_PREFIX_ . 'wajub_payment` WHERE id_order = ' . (int) $order->id . ' ORDER BY id_wajub_payment', true, false) ?: [];
        $notes = $db->executeS('SELECT note_key, message FROM `' . _DB_PREFIX_ . 'wajub_order_note` WHERE id_order = ' . (int) $order->id . ' ORDER BY id_wajub_order_note', true, false) ?: [];
        $history = array_map('intval', array_column($db->executeS('SELECT id_order_state FROM `' . _DB_PREFIX_ . 'order_history` WHERE id_order = ' . (int) $order->id . ' ORDER BY id_order_history', true, false) ?: [], 'id_order_state'));
        echo json_encode([
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
            'module' => (string) $order->module,
            'payment' => (string) $order->payment,
            'state' => (int) $order->current_state,
            'history' => $history,
            'total' => (float) $order->total_paid_tax_incl,
            'total_paid_real' => (float) $order->total_paid_real,
            'id_cart' => (int) $order->id_cart,
            'payments' => $payments,
            'wajub' => $wajub,
            'notes' => $notes,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);

    case 'held':
        echo ((new Services(Scope::fromContext(), '1.0.0'))->heldConfirmations()->holds((int) $argument) ? 'held' : 'none') . "\n";
        exit(0);

    case 'orders-of-cart':
        echo implode(',', array_column(Db::getInstance()->executeS('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE id_cart = ' . (int) $argument, true, false) ?: [], 'id_order')) . "\n";
        exit(0);

    case 'cart':
        $cart = new Cart((int) $argument);
        $rows = Db::getInstance()->executeS('SELECT id_product, quantity FROM `' . _DB_PREFIX_ . 'cart_product` WHERE id_cart = ' . (int) $cart->id . ' ORDER BY date_add', true, false) ?: [];
        echo json_encode([
            'id' => (int) $cart->id,
            'customer' => (int) $cart->id_customer,
            'products' => array_map(static function (array $p): array {
                return ['id' => (int) $p['id_product'], 'quantity' => (int) $p['quantity']];
            }, $rows),
            'ordered' => (bool) $cart->orderExists(),
        ]) . "\n";
        exit(0);

    case 'states':
        $services = new Services(Scope::fromContext(), '1.0.0');
        echo json_encode([
            'awaiting' => $services->settings()->awaitingState(),
            'partial' => $services->settings()->partialState(),
            'paid' => (int) Configuration::get('PS_OS_PAYMENT'),
            'error' => (int) Configuration::get('PS_OS_ERROR'),
            'canceled' => (int) Configuration::get('PS_OS_CANCELED'),
            'refunded' => (int) Configuration::get('PS_OS_REFUND'),
        ]) . "\n";
        exit(0);

    case 'refunds':
        // The order's Wajub refunds and its credit slips.
        $db = Db::getInstance();
        echo json_encode([
            'refunds' => $db->executeS('SELECT refund_id, request_key, payment_id, id_order_slip, environment, origin, status, amount, order_unpaid FROM `' . _DB_PREFIX_ . 'wajub_refund` WHERE id_order = ' . (int) $argument . ' ORDER BY id_wajub_refund', true, false) ?: [],
            'slips' => $db->executeS('SELECT id_order_slip, amount, total_products_tax_incl, total_shipping_tax_incl, order_slip_type FROM `' . _DB_PREFIX_ . 'order_slip` WHERE id_order = ' . (int) $argument . ' ORDER BY id_order_slip', true, false) ?: [],
        ], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);

    case 'returns':
        // Product returns, which "Standard refund" and "Return products" need.
        Configuration::updateValue('PS_ORDER_RETURN', (int) $argument > 0 ? 1 : 0);
        echo (int) Configuration::get('PS_ORDER_RETURN') . "\n";
        exit(0);

    case 'deliver':
        // The order delivered, as the merchant marks it: "Return products"
        // takes delivered orders only, "Standard refund" the others.
        $order = new Order((int) $argument);
        // By an employee: PrestaShop 9 writes the stock movements it makes
        // in their name, and refuses none.
        Context::getContext()->employee = new Employee((int) Db::getInstance()->getValue('SELECT MIN(id_employee) FROM `' . _DB_PREFIX_ . 'employee` WHERE active = 1', false));
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState((int) Configuration::get('PS_OS_DELIVERED'), $order, true);
        $history->add();
        echo ((new Order((int) $argument))->hasBeenDelivered() ? 'delivered' : 'not delivered') . "\n";
        exit(0);

    case 'failing-hook':
        // Another module whose actionOrderSlipAdd fails, run before the
        // module's: PrestaShop adds the credit slip, then stops at it.
        $dir = _PS_MODULE_DIR_ . 'wajubfailinghook';
        if ($argument === 'write') {
            @mkdir($dir);
            file_put_contents($dir . '/wajubfailinghook.php', <<<'MODULE'
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
class WajubFailingHook extends Module
{
    public function __construct()
    {
        $this->name = 'wajubfailinghook';
        $this->tab = 'others';
        $this->version = '1.0.0';
        $this->author = 'Wajub tests';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'Wajub tests: a failing hook';
        $this->description = 'Fails in actionOrderSlipAdd.';
    }

    public function install()
    {
        return parent::install() && $this->registerHook('actionOrderSlipAdd');
    }

    public function hookActionOrderSlipAdd($params)
    {
        throw new RuntimeException('A module whose actionOrderSlipAdd fails');
    }
}
MODULE);
            echo "written\n";
            exit(0);
        }
        if ($argument === 'first') {
            $module = Module::getInstanceByName('wajubfailinghook');
            if (!$module instanceof Module || !Module::isInstalled('wajubfailinghook')) {
                echo "not installed\n";
                exit(1);
            }
            $hook = (int) Hook::getIdByName('actionOrderSlipAdd');
            Db::getInstance()->update('hook_module', ['position' => 0], 'id_hook = ' . $hook . ' AND id_module = ' . (int) $module->id);
            $first = (int) Db::getInstance()->getValue('SELECT id_module FROM `' . _DB_PREFIX_ . 'hook_module` WHERE id_hook = ' . $hook . ' AND id_shop = ' . (int) Context::getContext()->shop->id . ' ORDER BY position, id_module', false);
            echo ($first === (int) $module->id ? 'first' : 'not first') . "\n";
            exit(0);
        }
        @unlink($dir . '/wajubfailinghook.php');
        @rmdir($dir);
        echo (is_dir($dir) || Module::isInstalled('wajubfailinghook') ? 'still there' : 'removed') . "\n";
        exit(0);

    case 'age-order':
        // The order placed `$argv[3]` seconds earlier than it was, its
        // payments started as much earlier, as an order the payer left.
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'orders` SET date_add = DATE_SUB(date_add, INTERVAL ' . (int) ($argv[3] ?? 0) . ' SECOND) WHERE id_order = ' . (int) $argument);
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'wajub_payment` SET date_add = DATE_SUB(date_add, INTERVAL ' . (int) ($argv[3] ?? 0) . ' SECOND) WHERE id_order = ' . (int) $argument);
        echo "aged\n";
        exit(0);

    case 'cron-url':
        $services = new Services(Scope::fromContext(), '1.0.0');
        echo $services->shop()->cronUrl($services->tokens()->cron()) . "\n";
        exit(0);

    case 'order-detail-ids':
        // The order's first product line, as the refund forms name it.
        echo (int) Db::getInstance()->getValue('SELECT id_order_detail FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . (int) $argument . ' ORDER BY id_order_detail', false) . "\n";
        exit(0);

    case 'hooked':
        // Whether the module is on a hook, by its name.
        echo (Hook::isModuleRegisteredOnHook(Module::getInstanceByName('wajub'), $argument, (int) Context::getContext()->shop->id) ? 'yes' : 'no') . "\n";
        exit(0);

    case 'event':
        $status = Db::getInstance()->getValue('SELECT status FROM `' . _DB_PREFIX_ . 'wajub_event` WHERE event_id = \'' . pSQL($argument) . '\'', false);
        echo ($status !== false && $status !== null ? (string) $status : 'none') . "\n";
        exit(0);

    case 'saved-forms':
        // The values run.sh posts, form by form.
        $settings = (new Services(Scope::fromContext(), '1.0.0'))->settings();
        $en = (int) Language::getIdByIso('en');
        $fr = (int) Language::getIdByIso('fr');
        check($settings->environment() === 'sandbox', 'connection: Sandbox kept (Live refused without a live key)');
        check(str_starts_with($settings->secretKey('sandbox'), 'sk_test.formsave'), 'connection: the sandbox key pasted is saved');
        $raw = (string) Db::getInstance()->getValue('SELECT value FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'WAJUB_SECRET_KEY_SANDBOX\'', false);
        check(str_starts_with($raw, 'v1:') && !str_contains($raw, 'formsave'), 'connection: and encrypted in the database');
        check($settings->secretKey('live') === '', 'connection: a live key pasted in the Sandbox field is not saved anywhere');
        check($settings->debugLog() === true, 'connection: the debug log is on');
        check($settings->methodEnabled('mobile_money') && !$settings->methodEnabled('card') && $settings->methodEnabled('other'), 'methods: Card off, the others on');
        check($settings->methodTitle('mobile_money', $en) === 'Pay by phone' && $settings->methodTitle('mobile_money', $fr) === 'Payer par téléphone', 'methods: a title in each language');
        check($settings->methodTitle('card', $en) === '', 'methods: an empty title keeps the default');
        check($settings->methodDescription('other', $en) === "First line\nSecond line & more", 'methods: a description keeps its lines and its ampersand');
        check($settings->methodDescription('other', $fr) === null, 'methods: an empty description keeps the default');
        check($settings->methodDescription('card', $en) === '' && $settings->methodDescription('card', $fr) === '', 'methods: "no description" shows none');
        check($settings->methodLogos('mobile_money') === ['orange'] && $settings->methodLogos('card') === ['visa'], 'methods: the logos ticked, a logo of another method ignored');
        check($settings->operatorFromNumber() === true, 'methods: the operator is found from the number');
        check($settings->formTheme() === 'dark', 'appearance: the dark payment form');
        check($settings->securedLine() === false, 'appearance: "Secured by Wajub" off');
        check(str_starts_with($settings->webhookSecret('sandbox'), 'whsec_test_formsave'), 'webhooks: the sandbox signing secret pasted is saved');
        $raw = (string) Db::getInstance()->getValue('SELECT value FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'WAJUB_WEBHOOK_SECRET_SANDBOX\'', false);
        check(str_starts_with($raw, 'v1:') && !str_contains($raw, 'formsave'), 'webhooks: and encrypted in the database');
        check(!str_starts_with($settings->webhookSecret('live'), 'whsec_test_'), 'webhooks: a sandbox secret pasted in the Live field is not saved');
        break;

    case 'multistore-on':
        $group = (int) Shop::getGroupFromShop((int) Configuration::get('PS_SHOP_DEFAULT'));
        $shop = new Shop();
        $shop->name = 'Wajub second shop';
        $shop->id_shop_group = $group;
        $shop->id_category = (int) Configuration::get('PS_HOME_CATEGORY');
        $shop->theme_name = (string) Context::getContext()->shop->theme_name;
        $shop->active = true;
        if (!$shop->add()) {
            echo "the second shop could not be added\n";
            exit(1);
        }
        // As copying a shop's data does: the module is on in the new shop.
        Db::getInstance()->insert('module_shop', ['id_module' => (int) Module::getModuleIdByName('wajub'), 'id_shop' => (int) $shop->id, 'enable_device' => 7]);
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        echo $shop->id . "\n";
        exit(0);

    case 'multistore-check':
        $second = (int) $argument;
        $first = (int) Configuration::get('PS_SHOP_DEFAULT');
        $group = (int) Shop::getGroupFromShop($second);
        Shop::resetStaticCache();
        Configuration::resetStaticCache();
        check(Shop::isFeatureActive(), 'multistore: on, with two shops');
        $row = static function (int $idShop): ?string {
            $value = Db::getInstance()->getValue('SELECT value FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'WAJUB_FORM_THEME\' AND ' . ($idShop > 0 ? 'id_shop = ' . $idShop : 'id_shop IS NULL AND id_shop_group IS NULL'), false);

            return $value === false || $value === null ? null : (string) $value;
        };
        check($row($second) === 'dark', 'multistore: the theme saved in the second shop\'s context is kept for that shop (' . var_export($row($second), true) . ')');
        check($row(0) === 'light', 'multistore: the theme saved for all shops is the shared value (' . var_export($row(0), true) . ')');
        check($row($first) === null, 'multistore: the first shop got no value of its own');
        $keysSaved = (new Services(Scope::allShops(), '1.0.0'))->configStore()->savedAt('WAJUB_SECRET_KEY_SANDBOX');
        check(isset($keysSaved['s' . $second], $keysSaved['all']) && !isset($keysSaved['s' . $first]), 'multistore: savedAt() reads each scope\'s own value from the table (' . implode(', ', array_keys($keysSaved)) . ')');
        check(Scope::ofShop($second)->idShop === $second && Scope::ofShop($second)->idShopGroup === $group, 'multistore: the scope of a shop, with its group, whatever the context');
        $module = Module::getInstanceByName('wajub');
        check($module instanceof Wajub && $module->servicesFor($second)->environment()->activeKey() === (new Services(new Scope($second, $group), '1.0.0'))->settings()->secretKey('sandbox'), 'multistore: servicesFor() a shop pays with that shop\'s key, whatever the context');
        $inSecond = (new Services(new Scope($second, $group), '1.0.0'))->settings();
        $inFirst = (new Services(new Scope($first, $group), '1.0.0'))->settings();
        $everywhere = (new Services(Scope::allShops(), '1.0.0'))->settings();
        check($inSecond->formTheme() === 'dark' && $inFirst->formTheme() === 'light' && $everywhere->formTheme() === 'light', 'multistore: each shop reads its own theme, or the shared one');
        check(str_starts_with($inSecond->secretKey('sandbox'), 'sk_test.secondshop'), 'multistore: the sandbox key pasted in the second shop is its own');
        check($everywhere->secretKey('sandbox') !== '' && $everywhere->secretKeySavedAnywhere('sandbox', $everywhere->secretKey('sandbox')) && $everywhere->secretKeySavedAnywhere('sandbox', $inSecond->secretKey('sandbox')), 'multistore: both keys are saved somewhere: neither may be revoked');
        check(!str_starts_with($inFirst->secretKey('sandbox'), 'sk_test.secondshop') && !str_starts_with($everywhere->secretKey('sandbox'), 'sk_test.secondshop'), 'multistore: and not the first shop\'s, nor the shared one');
        $keyRow = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'WAJUB_SECRET_KEY_SANDBOX\' AND id_shop = ' . $second . ' AND value LIKE \'v1:%\'', false);
        check($keyRow === 1, 'multistore: kept encrypted, under the second shop');
        check((new Services(new Scope($second, $group), '1.0.0'))->environment()->activeKey() === $inSecond->secretKey('sandbox'), 'multistore: the second shop pays with its own key');
        check($inSecond->savedFor(Settings::ENVIRONMENT) === 's' . $second && $inSecond->keysSavedFor() === 's' . $second && $inSecond->secretKey('live') === '', 'multistore: its environment is its own too, and it never pays with a live key of all shops');
        break;

    case 'multistore-off':
        $second = (int) $argument;
        if ($second > 0 && $second !== (int) Configuration::get('PS_SHOP_DEFAULT')) {
            $db = Db::getInstance();
            $ids = array_map('intval', array_column($db->executeS('SELECT id_configuration FROM `' . _DB_PREFIX_ . 'configuration` WHERE id_shop = ' . $second, true, false) ?: [], 'id_configuration'));
            if ($ids !== []) {
                $db->delete('configuration_lang', 'id_configuration IN (' . implode(', ', $ids) . ')');
                $db->delete('configuration', 'id_configuration IN (' . implode(', ', $ids) . ')');
            }
            $db->delete('module_shop', 'id_shop = ' . $second);
            $shop = new Shop($second);
            if (Validate::isLoadedObject($shop)) {
                $shop->delete();
            }
        }
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 0);
        Shop::resetStaticCache();
        // The friendly URLs of one shop again, without the second one's address.
        Tools::generateHtaccess();
        echo 'one shop: ' . ((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'shop`', false) === 1 ? 'yes' : 'no') . "\n";
        exit(0);

    case 'order-history':
        $awaiting = (int) Configuration::getGlobalValue(Settings::STATE_AWAITING);
        if ($argument === 'add') {
            Db::getInstance()->insert('order_history', ['id_employee' => 0, 'id_order' => 999999, 'id_order_state' => $awaiting, 'date_add' => date('Y-m-d H:i:s')]);
            echo $awaiting . "\n";
        } else {
            Db::getInstance()->delete('order_history', 'id_order = 999999');
        }
        exit(0);

    case 'rename-state':
        $id = (int) Configuration::getGlobalValue(Settings::STATE_AWAITING);
        $iso = $argv[3] ?? '';
        Db::getInstance()->update('order_state_lang', ['name' => pSQL($argument)], 'id_order_state = ' . $id . ($iso !== '' ? ' AND id_lang = ' . (int) Language::getIdByIso($iso) : ''));
        echo $id . "\n";
        exit(0);

    case 'forget-state-id':
        Configuration::deleteByName(Settings::STATE_AWAITING);
        exit(0);

    case 'state-named':
        $id = (int) Configuration::getGlobalValue(Settings::STATE_AWAITING);
        $iso = $argv[3] ?? 'en';
        $used = (int) ($argv[4] ?? 0);
        $state = new OrderState($id);
        check(($state->name[(int) Language::getIdByIso($iso)] ?? '') === $argument, "the state #{$id} is named \"{$argument}\" in {$iso} (" . ($state->name[(int) Language::getIdByIso($iso)] ?? '') . ')');
        // The states of this run: from the one an order used on (an earlier
        // run may have left others behind).
        $ours = array_values(array_filter(stateIds(), static fn (int $each): bool => $each >= $used));
        check($used > 0 && $id === $used && count($ours) === count(OrderStates::STATES), "it is state #{$used}, and no state was made twice (" . implode(', ', $ours) . ')');
        break;

    case 'payment-record':
        $db = Db::getInstance();
        if ($argument === 'add') {
            $db->insert('wajub_payment', ['payment_id' => 'trx_test_kept', 'id_shop' => 1, 'id_order' => 999999, 'environment' => 'sandbox', 'method' => 'card', 'currency' => 'XAF', 'amount' => 5000, 'amount_paid' => 5000, 'counted' => 5000, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')]);
            $db->insert('wajub_refund', ['refund_id' => 'rfd_test_kept', 'payment_id' => 'trx_test_kept', 'id_shop' => 1, 'id_order' => 999999, 'environment' => 'sandbox', 'origin' => 'wajub', 'currency' => 'XAF', 'amount' => 1000, 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s')]);
            exit(0);
        }
        if ($argument === 'remove') {
            $db->delete('wajub_payment', 'payment_id = \'trx_test_kept\'');
            $db->delete('wajub_refund', 'refund_id = \'rfd_test_kept\'');
            exit(0);
        }
        check((string) $db->getValue('SELECT counted FROM `' . _DB_PREFIX_ . 'wajub_payment` WHERE payment_id = \'trx_test_kept\' AND id_order = 999999', false) === '5000.000000', 'the Wajub payment of an order outlives an uninstall and a reinstall (PrestaShop\'s "Reset")');
        check((string) $db->getValue('SELECT amount FROM `' . _DB_PREFIX_ . 'wajub_refund` WHERE refund_id = \'rfd_test_kept\'', false) === '1000.000000', 'and so does its refund');
        break;

    case 'install-fails':
        // Module::install() reads the module's translations through
        // Symfony's container, which bin/console boots: so is it here.
        global $kernel;
        if (is_file(getcwd() . '/.env') && class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
            (new Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->loadEnv(getcwd() . '/.env');
        }
        $kernelClass = class_exists('AdminKernel') ? 'AdminKernel' : 'AppKernel';
        $kernel = new $kernelClass(_PS_ENV_, false);
        $kernel->boot();
        $module = new class extends Wajub {
            public function registerHook($hook_name, $shop_list = null)
            {
                return false;
            }
        };
        check(!Module::isInstalled('wajub'), 'before: not installed');
        check($module->install() === false, 'an install whose hooks cannot be registered fails');
        check(in_array('The Wajub hooks could not be registered.', $module->getErrors(), true), 'and says why: ' . implode(' ', $module->getErrors()));
        Cache::clean('Module::isInstalled*');
        check(!(bool) Db::getInstance()->getValue('SELECT id_module FROM `' . _DB_PREFIX_ . 'module` WHERE name = \'wajub\'', false), 'and is undone: PrestaShop does not list the module as installed, so a new install runs');
        check(stateIds() === [] || array_filter(stateIds(), static fn (int $id): bool => !(new OrderState($id))->deleted) === [], 'no order state of it left offered');
        break;

    case 'page-access':
        $module = Module::getInstanceByName('wajub');
        $profile = new Profile();
        $profile->name = array_fill_keys(Language::getIDs(false), 'Wajub integration profile');
        $profile->add();
        $other = new Employee();
        $other->id_profile = (int) $profile->id;
        Context::getContext()->employee = $other;
        // A redirect ends the script: said, rather than taken for a pass.
        $answered = false;
        register_shutdown_function(static function () use (&$answered, $profile): void {
            if (!$answered) {
                $profile->delete();
                echo "  FAIL  page access: the Configure button sent an employee whose profile may not open the page to it\n";
                exit(1);
            }
        });
        $said = (string) $module->getContent();
        $answered = true;
        check(str_contains($said, 'Team &gt; Permissions') && str_contains($said, 'Payment &gt; Wajub') && !str_contains($said, '&amp;'), 'page access: the Configure button tells an employee whose profile may not open the page who gives access, and where, escaped once');
        $superAdmin = new Employee();
        $superAdmin->id_profile = _PS_ADMIN_PROFILE_;
        check($module instanceof Wajub && $module->settingsPageAllowed($superAdmin), 'page access: the SuperAdmin opens the settings page');
        check(!$module->settingsPageAllowed($other) && !$module->settingsPageAllowed(null), 'page access: a profile not given the page does not');
        (new Access())->updateLgcAccess((int) $profile->id, (int) Tab::getIdFromClassName('AdminParentPayment'), 'view', true);
        check($module->settingsPageAllowed($other), 'page access: a profile given the Payment menu in Team > Permissions opens it');
        $profile->delete();
        break;

    case 'uninstalled':
        check(!Module::isInstalled('wajub'), 'module uninstalled');
        foreach (Schema::TABLES as $table) {
            if (in_array($table, Schema::KEPT, true)) {
                check(tableExists($table), "table {$table} kept: the record of each order's Wajub payments");
            } else {
                check(!tableExists($table), "table {$table} dropped");
            }
        }
        $used = (int) $argument;
        // The module's own keys only: the local shops keep a marker of their
        // own under the same prefix (WAJUB_ENV_*). The id of a state an order
        // went through stays, for a reinstall to bring that state back.
        $names = array_merge(Settings::allKeys(), ['CONF_WAJUB_FIXED', 'CONF_WAJUB_VAR', 'CONF_WAJUB_FIXED_FOREIGN', 'CONF_WAJUB_VAR_FOREIGN']);
        $left = array_column(Db::getInstance()->executeS('SELECT name FROM `' . _DB_PREFIX_ . 'configuration` WHERE name IN (\'' . implode('\', \'', array_map('pSQL', $names)) . '\')', true, false) ?: [], 'name');
        $expected = $used > 0 ? [Settings::STATE_AWAITING] : [];
        check($left === $expected, 'no setting left' . ($used > 0 ? ' but the id of the state an order used' : '') . ($left !== $expected ? ': ' . implode(', ', $left) : ''));
        check($used === 0 || (int) Configuration::getGlobalValue(Settings::STATE_AWAITING) === $used, 'the id kept is that state\'s');
        foreach (stateIds() as $id) {
            $state = new OrderState($id);
            check($id === $used && (bool) $state->deleted, "order state #{$id} " . ($id === $used ? 'an order went through: kept, marked deleted' : 'left behind'));
        }
        check($used === 0 || in_array($used, stateIds(), true), 'the order state an order went through is still there');
        check(!Tab::getIdFromClassName(Wajub::ADMIN_CONTROLLER), 'back office tab removed');
        break;

    case 'multistore-url-on':
        $first = (int) Configuration::get('PS_SHOP_DEFAULT');
        $group = (int) Shop::getGroupFromShop($first);
        $shop = new Shop();
        $shop->name = 'Wajub second shop';
        $shop->id_shop_group = $group;
        $shop->id_category = (int) Configuration::get('PS_HOME_CATEGORY');
        $shop->theme_name = (string) Context::getContext()->shop->theme_name;
        $shop->active = true;
        if (!$shop->add()) {
            echo "the second shop could not be added\n";
            exit(1);
        }
        try {
            // Part of what "Import data from another shop" copies: what its
            // pages and the module's controllers need, with the module on and
            // on its hooks. No products: nothing is bought in it.
            $shop->copyShopData($first, array_fill_keys(['lang', 'currency', 'country', 'zone', 'group', 'module', 'hook_module', 'category', 'carrier', 'tax_rules_group', 'contact', 'cms', 'meta_lang', 'employee'], true));
            $main = new ShopUrl((int) Db::getInstance()->getValue('SELECT id_shop_url FROM `' . _DB_PREFIX_ . 'shop_url` WHERE id_shop = ' . $first . ' AND main = 1', false));
            $url = new ShopUrl();
            $url->id_shop = (int) $shop->id;
            $url->domain = $argument;
            $url->domain_ssl = $argument;
            $url->physical_uri = Validate::isLoadedObject($main) ? (string) $main->physical_uri : '/';
            $url->virtual_uri = '';
            $url->main = true;
            $url->active = true;
            if (!$url->add()) {
                throw new RuntimeException('the second shop\'s address could not be added');
            }
        } catch (Throwable $e) {
            $shop->delete();
            echo $e->getMessage() . "\n";
            exit(1);
        }
        if (!(int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE id_shop = ' . (int) $shop->id . ' AND id_module = ' . (int) Module::getModuleIdByName('wajub'), false)) {
            Db::getInstance()->insert('module_shop', ['id_module' => (int) Module::getModuleIdByName('wajub'), 'id_shop' => (int) $shop->id, 'enable_device' => 7]);
        }
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        // Friendly URLs, as the shops have them, for the new one too.
        Tools::generateHtaccess();
        echo $shop->id . "\n";
        exit(0);

    case 'scope-connection':
        $scope = Scope::fromSlug($argument);
        if ($scope === null) {
            echo "no such scope\n";
            exit(2);
        }
        $services = new Services($scope, '1.0.0');
        $services->settings()->setEnvironment('sandbox');
        $services->settings()->setSecretKey('sandbox', (string) ($argv[3] ?? ''));
        $services->settings()->setWebhookSecret('sandbox', (string) ($argv[4] ?? ''));
        echo $services->shop()->webhookUrl() . "\n";
        exit(0);

    case 'environment':
        $scope = Scope::fromSlug($argument);
        if ($scope === null) {
            echo "no such scope\n";
            exit(2);
        }
        $settings = (new Services($scope, '1.0.0'))->settings();
        if (($argv[4] ?? '') !== '') {
            $settings->setSecretKey('live', $argv[4] === 'none' ? '' : (string) $argv[4]);
        }
        $settings->setEnvironment((string) ($argv[3] ?? 'sandbox'));
        echo $settings->environment() . "\n";
        exit(0);

    case 'webhook-url':
        $scope = Scope::fromSlug($argument);
        echo $scope !== null ? (new Services($scope, '1.0.0'))->shop()->webhookUrl() . "\n" : "no such scope\n";
        exit(0);

    case 'health':
        $scope = Scope::fromSlug($argument);
        echo $scope !== null ? (new Services($scope, '1.0.0'))->webhookHealth()->state()['state'] . "\n" : "no such scope\n";
        exit(0);

    case 'order-to-shop':
        $idShop = (int) ($argv[3] ?? 0);
        $db = Db::getInstance();
        $db->update('orders', ['id_shop' => $idShop, 'id_shop_group' => (int) Shop::getGroupFromShop($idShop)], 'id_order = ' . (int) $argument);
        $db->update('wajub_payment', ['id_shop' => $idShop], 'id_order = ' . (int) $argument);
        echo "moved\n";
        exit(0);

    case 'expiring-key':
        $services = new Services(Scope::allShops(), '1.0.0');
        $settings = $services->settings();
        $key = $settings->secretKey('sandbox');
        $settings->setEnvironment('sandbox');
        $settings->setKeyOrigin('sandbox', [
            'via' => 'pairing',
            'business' => 'Stub Business',
            'fp' => Wajub\PrestaShop\Settings\Keys::fingerprint($key),
            'at' => time() - 80 * 86400,
            'environment' => 'sandbox',
            'key_type' => 'private',
            'home' => Wajub\PrestaShop\Pairing\Pairing::addressOf($services->shop()->baseUrl()),
            'site_type' => 'development',
            'expires_at' => time() + (int) $argument * 86400,
        ]);
        if (($argv[3] ?? '') !== '') {
            Configuration::updateValue('PS_LANG_DEFAULT', (int) Language::getIdByIso((string) $argv[3]));
        }
        echo $key !== '' ? "expiring\n" : "no key\n";
        exit(0);

    case 'reminded':
        echo (int) ((new Services(Scope::allShops(), '1.0.0'))->keyOrigins()->origin('sandbox')['reminded_at'] ?? 0) . "\n";
        exit(0);

    case 'mail':
        if ($argument === 'on') {
            Configuration::updateValue('PS_MAIL_METHOD', 2);
            Configuration::updateValue('PS_MAIL_SERVER', 'host.containers.internal');
            Configuration::updateValue('PS_MAIL_SMTP_PORT', (int) ($argv[3] ?? 25));
            Configuration::updateValue('PS_MAIL_SMTP_ENCRYPTION', 'off');
            Configuration::updateValue('PS_MAIL_USER', '');
            Configuration::updateValue('PS_MAIL_PASSWD', '');
            Configuration::updateValue('PS_MAIL_TYPE', 3);
            Configuration::updateValue('PS_SHOP_EMAIL', 'owner@wajub-shop.test');
        } else {
            Configuration::updateValue('PS_MAIL_METHOD', 3);
        }
        echo "mail {$argument}\n";
        exit(0);

    case 'gdpr':
        // An account opened with a guest's e-mail address, which PrestaShop
        // neither verifies nor refuses: the guest's payments are not its own.
        // In a transaction, rolled back.
        $db = Db::getInstance();
        $module = Module::getInstanceByName('wajub');
        $db->execute('START TRANSACTION');
        try {
            $email = 'guest-' . bin2hex(random_bytes(3)) . '@wajub-gdpr.test';
            $guest = new Customer();
            $guest->firstname = 'Guest';
            $guest->lastname = 'Checkout';
            $guest->email = $email;
            $guest->passwd = Tools::hash(Tools::passwdGen(16));
            $guest->is_guest = true;
            $guest->add();
            $account = new Customer();
            $account->firstname = 'Same';
            $account->lastname = 'Address';
            $account->email = $email;
            $account->passwd = Tools::hash(Tools::passwdGen(16));
            $account->is_guest = false;
            $account->add();
            $db->insert('wajub_payment', [
                'payment_id' => 'trx_test_gdpr_guest_' . bin2hex(random_bytes(3)), 'reference' => 'gdpr', 'id_shop' => 1, 'id_cart' => 999999,
                'id_order' => 999999, 'id_customer' => (int) $guest->id, 'environment' => 'sandbox', 'method' => 'mobile_money',
                'mode' => 'fields', 'state' => 'final', 'status' => 'succeeded', 'currency' => 'XAF', 'amount' => 15000, 'amount_paid' => 15000,
                'channel' => 'cm.mtn', 'date_add' => date('Y-m-d H:i:s'), 'date_upd' => date('Y-m-d H:i:s'),
            ]);
            $theirs = json_decode((string) Hook::exec('actionExportGDPRData', (array) new Customer((int) $account->id), (int) $module->id), true);
            check($theirs === [], 'GDPR export of an account opened with a guest\'s e-mail address: none of the guest\'s payments (' . json_encode($theirs) . ')');
            $byEmail = json_decode((string) Hook::exec('actionExportGDPRData', ['email' => $email], (int) $module->id), true);
            check(is_array($byEmail) && count($byEmail) === 1 && ($byEmail[0]['Payment method'] ?? '') === 'MTN Mobile Money' && ($byEmail[0]['Status'] ?? '') === 'Paid', 'an e-mail address alone (the back office\'s request): its customers\' payments, guests included, in words');
        } finally {
            $db->execute('ROLLBACK');
        }

        $customers = Customer::getCustomersByEmail($argument);
        $customer = new Customer((int) ($customers[0]['id_customer'] ?? 0));
        $module = Module::getInstanceByName('wajub');
        $idModule = (int) $module->id;
        $export = json_decode((string) Hook::exec('actionExportGDPRData', (array) $customer, $idModule), true);
        $rows = is_array($export) ? $export : [];
        $ids = array_column($rows, 'Wajub payment ID');
        $mine = array_column(Db::getInstance()->executeS('SELECT payment_id FROM `' . _DB_PREFIX_ . 'wajub_payment` WHERE id_customer = ' . (int) $customer->id . ' AND id_order > 0', true, false) ?: [], 'payment_id');
        check($mine !== [] && $ids === $mine, 'GDPR export: the customer\'s Wajub payments, one row each (' . implode(', ', $ids) . ')');
        check(!str_contains((string) json_encode($rows), '"token"') && isset($rows[0]['Amount paid'], $rows[0]['Environment']), 'with named columns, never a session token');
        if (Module::isInstalled('psgdpr')) {
            // The GDPR module asks every module in turn: one of PrestaShop's
            // own (productcomments) fails outside a page, which is not ours.
            try {
                $psgdpr = Module::getInstanceByName('psgdpr');
                $data = method_exists($psgdpr, 'getCustomerDataFromModules') ? $psgdpr->getCustomerDataFromModules($customer) : [];
                check(isset($data[$module->displayName]) && count((array) $data[$module->displayName]) === count($rows), 'PrestaShop\'s GDPR module gets the same rows, under the module\'s name');
            } catch (Throwable $e) {
                echo '  (PrestaShop\'s GDPR module could not ask every module here: ' . $e->getMessage() . ")\n";
            }
        }
        $orders = array_map('intval', array_column(Db::getInstance()->executeS('SELECT id_order FROM `' . _DB_PREFIX_ . 'orders` WHERE id_customer = ' . (int) $customer->id, true, false) ?: [], 'id_order'));
        $state = new DbStateStore();
        $held = 0;
        foreach ($orders as $idOrder) {
            $state->set('order_conf:' . $idOrder, ['templateVars' => ['{email}' => $argument]], 3600);
            ++$held;
        }
        $answer = json_decode((string) Hook::exec('actionDeleteGDPRCustomer', (array) $customer, $idModule), true);
        check($answer === true, 'GDPR erasure: the module answers it is done');
        $left = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'wajub_payment` WHERE id_customer = ' . (int) $customer->id, false);
        $kept = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'wajub_payment` WHERE payment_id IN (\'' . implode('\', \'', array_map('pSQL', $mine)) . '\')', false);
        check($left === 0 && $kept === count($mine), 'the payments no longer name the customer, and stay, the record of their orders\' money');
        $stillHeld = 0;
        foreach ($orders as $idOrder) {
            $stillHeld += $state->get('order_conf:' . $idOrder) !== null ? 1 : 0;
        }
        check($held > 0 && $stillHeld === 0, 'the confirmation e-mails held for their orders, with their address, are gone');
        break;

    default:
        echo "unknown mode {$mode}\n";
        exit(2);
}

echo $failures === 0 ? "all checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);

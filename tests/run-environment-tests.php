<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

/*
 * The settings and the environment: what a setting nobody saved reads as,
 * secrets kept encrypted, a scope of a multistore shop falling back from the
 * shop to its group to all shops (on a stand-in of PrestaShop's
 * Configuration, with its plain-text save), each environment paying with its
 * own key only, and what a save does with a key and the environment
 * (Connection\KeyManager): the same checks for a pasted key as for a paired
 * one, Live never chosen without its key, the live key never removed while
 * it takes the payments. And the database's named locks the module takes
 * (Support\DbLock), taken again at once by the request that holds them.
 *
 *     php tests/run-environment-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/prestashop.php';

use Wajub\PrestaShop\Install\OrderStates;
use Wajub\PrestaShop\Install\Schema;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\PhpEncryptionCipher;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Settings\ShopConfigStore;
use Wajub\PrestaShop\Support\DbLock;

define('_NEW_COOKIE_KEY_', 'def00000test-cookie-key');

const API = 'https://api.wajub.com';

$sandbox = key_for('sandbox', 'S');
$live = key_for('live', 'L');

// === Defaults ==================================================================

$s = services();
$settings = $s->settings();
check($settings->environment() === 'sandbox', 'a new shop is in Sandbox');
check($settings->methodEnabled(Methods::MOBILE_MONEY) === true && $settings->methodEnabled(Methods::CARD) && $settings->methodEnabled(Methods::OTHER), 'every method reads as on until turned off (each shows only once the account offers it)');
check($settings->formTheme() === 'system' && $settings->securedLine() && !$settings->operatorFromNumber() && !$settings->debugLog(), 'the form in the shop\'s look, "Secured by Wajub" shown, the operator list, no debug log');
check($settings->methodTitle(Methods::CARD, 1) === '' && $settings->methodDescription(Methods::CARD, 1) === null && $settings->methodLogos(Methods::CARD) === null, 'titles, descriptions and logos read as unsaved: the defaults of the page\'s language and the shop\'s currency show');
check(Settings::defaults()[Settings::ENVIRONMENT] === 'sandbox' && Settings::defaults()['WAJUB_MM_ENABLED'] === '1', 'what install() saves: Sandbox, every method on');
foreach (Settings::allKeys() as $key) {
    check(preg_match('/^WAJUB_[A-Z0-9_]+$/', $key) === 1 && strlen($key) <= 64, "{$key}: a configuration key PrestaShop takes, under the module's prefix");
}
check(count(Settings::allKeys()) === count(array_unique(Settings::allKeys())), 'every key is listed once, for uninstalling');
foreach (array_keys(Settings::defaults()) as $key) {
    check(in_array($key, Settings::allKeys(), true), "{$key}, saved at install, is removed at uninstall");
}
check(OrderStates::configKeys() === [Settings::STATE_AWAITING, Settings::STATE_PARTIAL] && array_diff(OrderStates::configKeys(), Settings::allKeys()) === [], 'the order states\' ids are settings OrderStates::uninstall() keeps while an order has the state');
check(Schema::KEPT === ['wajub_payment', 'wajub_refund', 'wajub_order_note'] && array_diff(Schema::KEPT, Schema::TABLES) === [], 'uninstalling keeps the record of each order\'s Wajub payments, refunds and notes, as the plugin keeps what it wrote on orders');

// === Secrets at rest ===========================================================

$settings->setSecretKey('sandbox', "  {$sandbox}\n");
check($settings->secretKey('sandbox') === $sandbox, 'a secret key reads back trimmed');
check(!leaked($sandbox, ArrayConfigStore::dump()), 'and is kept encrypted, never as it is');
$settings->setWebhookSecret('live', 'whsec_livesecret123');
check($settings->webhookSecret('live') === 'whsec_livesecret123' && !leaked('whsec_livesecret123', ArrayConfigStore::dump()), 'a signing secret too');
ArrayConfigStore::$values['all']['WAJUB_SECRET_KEY_LIVE'][0] = 'enc:not-base64!!';
check($settings->secretKey('live') === '' && $settings->secretKeyUnreadable('live'), 'a secret the shop can no longer decrypt reads as none, and says it cannot be read');
check(!$settings->secretKeyUnreadable('sandbox'), 'a readable one does not');
$settings->setSecretKey('live', '');
check(!$settings->secretKeyUnreadable('live') && $settings->secretKey('live') === '', 'removed, it is no longer unreadable');

$cipher = new PhpEncryptionCipher();
$stored = $cipher->encrypt($live);
check(str_starts_with($stored, 'v1:') && !str_contains($stored, $live), 'PhpEncryptionCipher keeps a value under its version, encrypted');
check($cipher->decrypt($stored) === $live, 'and reads it back with the shop\'s key');
check($cipher->decrypt('v1:' . base64_encode('another key:' . $live)) === null && $cipher->decrypt($live) === null, 'and reads nothing it did not write');

// === Scopes ====================================================================

Shop::$multistore = true;
Shop::$context = Shop::CONTEXT_SHOP;
Shop::$shop = 3;
Shop::$group = 2;
$scope = Scope::fromContext();
check($scope->idShop === 3 && $scope->idShopGroup === 2 && $scope->slug() === 's3', 'the shop selector on a shop: that shop');
Shop::$context = Shop::CONTEXT_GROUP;
check(Scope::fromContext()->idShop === null && Scope::fromContext()->idShopGroup === 2 && Scope::fromContext()->slug() === 'g2', 'on a group: that group');
Shop::$context = Shop::CONTEXT_ALL;
check(Scope::fromContext()->isAllShops(), 'on all shops: all of them');
Shop::$multistore = false;
Shop::$context = Shop::CONTEXT_SHOP;
check(Scope::fromContext()->isAllShops(), 'without multistore, the one value for all shops');

Shop::$multistore = true;
Configuration::$values = [];
$all = new Settings(new ShopConfigStore(Scope::allShops()), new FakeCipher());
$group = new Settings(new ShopConfigStore(new Scope(null, 2)), new FakeCipher());
$shop3 = new Settings(new ShopConfigStore(new Scope(3, 2)), new FakeCipher());
$shop4 = new Settings(new ShopConfigStore(new Scope(4, 2)), new FakeCipher());
$all->setEnvironment('live');
$all->setSecretKey('live', $live);
check($shop3->environment() === 'live' && $shop3->secretKey('live') === $live, 'a shop with nothing of its own reads what all shops have');
$group->setFormTheme('dark');
$shop3->setEnvironment('sandbox');
$shop3->setSecretKey('live', '');
check($shop3->environment() === 'sandbox' && $shop4->environment() === 'live', 'a shop\'s own value is its own');
check($shop3->formTheme() === 'dark' && $shop4->formTheme() === 'dark' && $all->formTheme() === 'system', 'a group\'s value is its shops\', not all shops\'');
check($shop3->secretKey('live') === '' && $shop4->secretKey('live') === $live, 'a key removed from one shop is removed from it alone: it stays for the others');

$shop3->setMethodTexts(Methods::CARD, [1 => 'Carte <b>bancaire</b>', 2 => ''], [1 => "Visa ou Mastercard.\nSans frais.", 2 => null]);
check($shop3->methodTitle(Methods::CARD, 1) === 'Carte bancaire' && $shop3->methodTitle(Methods::CARD, 2) === '', 'titles by language, plain text');
check($shop3->methodDescription(Methods::CARD, 1) === "Visa ou Mastercard.\nSans frais.", 'a description keeps its line breaks through Configuration\'s plain-text save');
check($shop3->methodDescription(Methods::CARD, 2) === null, 'a description left to the default reads as unsaved');
$shop3->setMethodTexts(Methods::CARD, [1 => ''], [1 => '']);
check($shop3->methodDescription(Methods::CARD, 1) === '', 'a description cleared reads as none, not as the default');
$shop3->setMethodTexts(Methods::OTHER, [1 => ''], [1 => 'Tom & Jerry\'s "<shop>"']);
check($shop3->methodDescription(Methods::OTHER, 1) === 'Tom & Jerry\'s "<shop>"', 'quotes, ampersands and brackets survive too');
$shop3->setKeyOrigin('live', ['via' => 'pairing', 'business' => 'Boutique <Douala> & Co', 'fp' => 'abc']);
check(($shop3->keyOrigin('live')['business'] ?? '') === 'Boutique <Douala> & Co', 'JSON kept in Configuration comes back whole');
$shop3->setMethodLogos(Methods::MOBILE_MONEY, ['orange', 'visa', 'orange', 'nope', 'mtn']);
check($shop3->methodLogos(Methods::MOBILE_MONEY) === ['orange', 'mtn'], 'logos: the known ones of the method\'s kind, once each, in the order chosen');
$shop3->setMethodLogos(Methods::CARD, []);
check($shop3->methodLogos(Methods::CARD) === [], 'no logo chosen is a choice, not the default');

// What each scope saved itself, read from Configuration's table.
Shop::$groups = [3 => 2, 4 => 2];
$store3 = new ShopConfigStore(new Scope(3, 2));
$saved = $store3->savedAt('WAJUB_SECRET_KEY_LIVE');
ksort($saved);
check(array_keys($saved) === ['all', 's3'], 'savedAt: the value of all shops and the shop\'s own, by scope');
check($saved['s3'] === '' && (new FakeCipher())->decrypt($saved['all']) === $live, 'savedAt: as saved: the shop\'s empty value, all shops\' encrypted key');
check(array_keys($store3->savedAt('WAJUB_FORM_THEME')) === ['g2'] && $store3->savedAt('WAJUB_NOTHING') === [], 'savedAt: a group\'s value under the group; a key never saved, nothing');
check($shop4->secretKeySavedAnywhere('live', $live) && !$shop4->secretKeySavedAnywhere('live', $sandbox) && !$shop4->secretKeySavedAnywhere('live', ''), 'secretKeySavedAnywhere: the key of all shops, read by shop 4, is saved; another is not');
$all->setWebhookSecret('sandbox', 'whsec_test_all');
$all->setWebhookEndpoint('sandbox', ['id' => 'we_all', 'url' => 'https://boutique.cm/w', 'at' => 1]);
check($shop3->webhookSecret('sandbox') === 'whsec_test_all' && !$shop3->webhookSecretSavedHere('sandbox') && $shop3->webhookEndpointSavedHere('sandbox') === null, 'a shop that inherits a signing secret and an endpoint has none of its own');
check($all->webhookSecretSavedHere('sandbox') && $all->webhookEndpointSavedHere('sandbox')['id'] === 'we_all', 'all shops have theirs');
$shop3->setWebhookSecret('sandbox', 'whsec_test_3');
$shop3->setWebhookEndpoint('sandbox', ['id' => 'we_3', 'url' => 'https://trois.cm/w', 'at' => 2]);
check($shop3->webhookSecretSavedHere('sandbox') && $shop3->webhookEndpointSavedHere('sandbox')['id'] === 'we_3' && $group->webhookEndpointSavedHere('sandbox') === null, 'once saved for the shop, they are its own, and not its group\'s');

check(Scope::ofShop(4)->idShop === 4 && Scope::ofShop(4)->idShopGroup === 2 && Scope::ofShop(4)->slug() === 's4', 'ofShop: the scope of a shop and its group, whatever the context');
Shop::$multistore = false;
check(Scope::ofShop(4)->isAllShops(), 'ofShop: without multistore, the one value for all shops');
Shop::$groups = [];

// === Environment ===============================================================

$s = services();
$env = $s->environment();
check(!$env->isConfigured() && $env->activeKey() === '' && !$env->sandboxKeyMissing(), 'no key: not configured');
$s->settings()->setSecretKey('live', $live);
check($env->sandboxSelected() && $env->activeKey() === '' && !$env->isConfigured(), 'Sandbox with only a live key has no key: the live key is never used in its place');
check($env->sandboxKeyMissing() && !$env->isLive(), 'and says so (Sandbox — no payments), and is not Live');
check(!$s->client()->isConfigured() && $s->client()->isSandbox(), 'its client has no key either');
$e = thrown(static fn () => $s->client()->paymentOptions('XAF', 1000.0));
check($e !== null && $GLOBALS['http']->sent === [], 'and sends nothing to Wajub, with the live key or any other');
$s->settings()->setSecretKey('sandbox', $sandbox);
check($env->activeKey() === $sandbox && !$env->sandboxKeyMissing() && $env->isSandbox(), 'Sandbox with its key uses it');
$s->settings()->setEnvironment('live');
check($env->activeKey() === $live && $env->isLive(), 'Live with its key uses it');
$s->settings()->setSecretKey('live', '');
check($env->activeKey() === '' && !$env->isConfigured(), 'Live without its key has none: the sandbox key is never used in its place');
check($env->isLive(), 'and still reads as Live: a page never calls it test');
$s->settings()->setSecretKey('live', $sandbox);
check($env->isSandbox(), 'a sandbox key saved as the live key reads as Sandbox: payments are named by the key that takes them');

// === Keys ======================================================================

$tr = new TestTranslator();
check(Keys::environmentOf('sk_test.x') === 'sandbox' && Keys::environmentOf('rk_test.x') === 'sandbox' && Keys::environmentOf('sk.x') === 'live' && Keys::environmentOf('rk.x') === 'live' && Keys::environmentOf('pk_test.x') === 'publishable' && Keys::environmentOf('whatever') === '', 'environmentOf reads the prefix');
check(Keys::normalize("  Bearer \t" . $sandbox . "\n ") === $sandbox, 'normalize takes off "Bearer " and spaces');
check(Keys::formatProblem($sandbox, 'sandbox', $tr) === '' && Keys::formatProblem($live, 'live', $tr) === '', 'a key of its field\'s environment has no problem');
check(str_contains(Keys::formatProblem($live, 'sandbox', $tr), 'live key'), 'a live key in the Sandbox field is told');
check(str_contains(Keys::formatProblem($sandbox, 'live', $tr), 'sandbox key'), 'a sandbox key in the Live field is told');
check(str_contains(Keys::formatProblem('pk_test.abc', 'sandbox', $tr), 'publishable key'), 'a publishable key is refused');
check(str_contains(Keys::formatProblem('nonsense', 'live', $tr), '“sk.”'), 'anything else names the prefix to look for');
check(Keys::webhookSecretProblem('whsec_test_a', 'sandbox', $tr) === '' && Keys::webhookSecretProblem('whsec_a', 'live', $tr) === '', 'signing secrets of their environment pass');
check(Keys::webhookSecretProblem('whsec_test_a', 'live', $tr) !== '' && Keys::webhookSecretProblem('whsec_a', 'sandbox', $tr) !== '' && Keys::webhookSecretProblem('sk_test.a', 'sandbox', $tr) !== '', 'the others are told');
$masked = Keys::mask($sandbox);
check($masked === "sk_test.\u{2022}\u{2022}\u{2022}\u{2022}" . substr($sandbox, -4) && !str_contains($masked, substr($sandbox, 8, 20)), 'mask shows the prefix and the last four characters only: ' . $masked);
check(Keys::mask('sk_test.short') === "sk_test.\u{2022}\u{2022}\u{2022}\u{2022}", 'a key too short to hide anything shows dots only');
check(Keys::fingerprint($sandbox) !== Keys::fingerprint($live) && strlen(Keys::fingerprint($sandbox)) === 16 && !str_contains($sandbox, Keys::fingerprint($sandbox)), 'a fingerprint tells two keys apart, and is not the key');
check(Keys::typeOf('rk_test.x') === 'restricted' && Keys::typeOf('sk.x') === 'private', 'typeOf reads a restricted key\'s prefix');

// === Saving keys (KeyManager) ==================================================

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [[200, ['business' => 'Boutique', 'env' => 'sandbox']]];
$saved = $s->keyManager()->saveKey('sandbox', ' Bearer ' . $sandbox . ' ');
check($saved['saved'] && $s->settings()->secretKey('sandbox') === $sandbox, 'a sandbox key Wajub accepts is saved');
check($saved['notices'][0]['type'] === 'success' && str_contains($saved['notices'][0]['text'], 'Sandbox key saved.') && str_contains($saved['notices'][0]['text'], 'Boutique'), 'and the merchant is told, with the business: ' . $saved['notices'][0]['text']);
check(($GLOBALS['http']->sent[0]['headers']['Authorization'] ?? '') === $sandbox, 'Wajub was asked with that key');
check($s->connectionStatus()->cached()['status'] === 'connected', 'the answer is kept as the connection\'s, the key being the one in use');
$count = count($GLOBALS['http']->sent);
check($s->keyManager()->saveKey('sandbox', $sandbox)['saved'] === false && count($GLOBALS['http']->sent) === $count, 'the key saved already: nothing to do, nothing asked');
check($s->keyManager()->saveKey('sandbox', '   ')['notices'] === [], 'an empty field keeps the saved key');

$s = services();
$result = $s->keyManager()->saveKey('sandbox', $live);
check(!$result['saved'] && $result['notices'][0]['type'] === 'error' && $GLOBALS['http']->sent === [], 'a live key in the Sandbox field is refused before Wajub is asked');
check($s->settings()->secretKey('sandbox') === '', 'and nothing is saved');

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [[401, ['message' => 'Unauthenticated']]];
$s->settings()->setSecretKey('sandbox', key_for('sandbox', 'OLD'));
$result = $s->keyManager()->saveKey('sandbox', $sandbox);
check(!$result['saved'] && str_contains($result['notices'][0]['text'], 'does not recognise'), 'a key Wajub refuses is not saved, and the merchant is told why');
check($s->settings()->secretKey('sandbox') === key_for('sandbox', 'OLD'), 'the saved key stays');

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [[200, ['business' => 'Boutique', 'env' => 'production']]];
$result = $s->keyManager()->saveKey('sandbox', $sandbox);
check(!$result['saved'] && str_contains($result['notices'][0]['text'], 'real payments'), 'a key Wajub says is live is refused as the sandbox key');

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [network_down()];
$result = $s->keyManager()->saveKey('live', $live);
check($result['saved'] && $result['notices'][0]['type'] === 'warning' && str_contains($result['notices'][0]['text'], 'Test connection'), 'a key saved while Wajub could not be reached is kept, with a warning');
check($GLOBALS['logger']->has('Key check failed') && !leaked($live, $GLOBALS['logger']->dump()), 'the failure is logged, never the key');

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [[403, ['message' => 'Forbidden']]];
check(str_contains($s->keyManager()->saveKey('sandbox', $sandbox)['notices'][0]['text'], 'permissions'), 'a key without the permissions is refused, and said so');

// === Removing keys and switching ===============================================

$s = services();
$keys = $s->keyManager();
$s->settings()->setSecretKey('live', $live);
$s->settings()->setEnvironment('live');
$notices = $keys->removeKey('live', 'live');
check($notices[0]['type'] === 'error' && $s->settings()->secretKey('live') === $live, 'the live key is not removed while Live takes payments with it');
check(str_contains($notices[0]['text'], 'choose Sandbox'), 'without a sandbox key, the merchant is told the one save that works');
$s->settings()->setSecretKey('sandbox', $sandbox);
check(str_contains($keys->removeKey('live', 'live')[0]['text'], 'Switch to Sandbox first'), 'with one, to switch to Sandbox first');
$notices = $keys->removeKey('live', 'sandbox');
check($notices[0]['type'] === 'info' && $s->settings()->secretKey('live') === '', 'removed in the same save that chooses Sandbox');
check($keys->removeKey('live', 'sandbox') === [], 'a key that is not there is not removed twice');
check($keys->removeKey('sandbox', 'sandbox')[0]['type'] === 'info' && $s->settings()->secretKey('sandbox') === '', 'the sandbox key may always go: Sandbox then takes no payment');

$s = services();
$keys = $s->keyManager();
check($keys->switchEnvironment('live')[0]['type'] === 'error' && $s->settings()->environment() === 'sandbox', 'Live is refused without a live key');
$s->settings()->setSecretKey('live', $live);
$notices = $keys->switchEnvironment('live');
check($notices[0]['type'] === 'success' && $s->settings()->environment() === 'live', 'Live with its key is turned on, and said: ' . $notices[0]['text']);
$notices = $keys->switchEnvironment('sandbox');
check($notices[0]['type'] === 'error' && $s->settings()->environment() === 'live', 'leaving Live for a Sandbox without a key is refused: the shop would stop taking payments');
$s->settings()->setSecretKey('live', '');
check($keys->switchEnvironment('sandbox')[0]['type'] === 'warning' && $s->settings()->environment() === 'sandbox', 'unless the live key went in the same save: the merchant wants no payment at all');
check($keys->switchEnvironment('sandbox') === [] && $keys->switchEnvironment('staging') === [], 'the environment in use, or one that does not exist, changes nothing');

// === Methods ===================================================================

check(Methods::defaultLogos(Methods::MOBILE_MONEY, 'xaf') === ['mtn', 'orange'] && Methods::defaultLogos(Methods::MOBILE_MONEY, 'EUR') === ['mtn', 'orange', 'airtel', 'mpesa'], 'Mobile Money shows the operators of the shop\'s currency zone, or the most widespread');
check(Methods::defaultLogos(Methods::CARD, 'XAF') === ['visa', 'mastercard'] && Methods::defaultLogos(Methods::OTHER, 'XAF') === [], 'cards show Visa and Mastercard; the other methods none until chosen');
foreach (Methods::KINDS as $kind) {
    foreach (Methods::logoChoices($kind) as $key) {
        check(is_file(dirname(__DIR__) . '/views/img/methods/' . Methods::logoFile($key)), "{$key}: its logo ships");
    }
}
check(Methods::ACCEPT_TYPES[Methods::MOBILE_MONEY] === ['mobile_money', 'mobile'], 'Mobile Money takes both of the API\'s types: one alone hides operators');
check(MethodTexts::defaultTitle(Methods::OTHER, $tr) === 'Other payment methods' && end($tr->calls)['domain'] === 'Modules.Wajub.Shop', 'default texts are the shop\'s, in the payer\'s language');

// === Database locks ============================================================

// The refund forms' request holds the order's lock until its end; whatever
// takes the same lock in that request takes it at once, and the lock goes
// with the last release, not before, and not later.
$lock = new DbLock();
Db::$lockQueries = [];
check($lock->acquire('order:42', 10) && $lock->acquire('order:42', 10) && Db::$lockQueries === ['GET_LOCK'], 'a lock this request holds is taken again at once');
$lock->release('order:42');
check(count(Db::$locks) === 1 && Db::$lockQueries === ['GET_LOCK'], 'released once: still held, for the first taker');
$lock->release('order:42');
check(Db::$locks === [] && Db::$lockQueries === ['GET_LOCK', 'RELEASE_LOCK'], 'released by the first taker too: let go');
$lock->release('order:42');
check(Db::$lockQueries === ['GET_LOCK', 'RELEASE_LOCK'], 'a lock not held is not released');

done('environment');

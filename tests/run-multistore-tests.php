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
 * The shops of a multistore, each with its own connection or that of the
 * scope above it (Settings\Scope, Settings::savedFor(), connectionSources(),
 * forgetOwnConnection(), FormHandler `inherit`): a shop with a key of its
 * own keeps its environment and never pays with a key saved above it, and
 * forgets its connection only once its payments ended; the webhook of each
 * scope, verified with that scope's signing secrets and keeping its own
 * health, a delivery judged by the environment of the shop of its order;
 * the reminders of keys that expire (Connection\KeyReminders), for every
 * scope's keys, in use when a shop pays with them, one e-mail each, the
 * notices for the employees of those shops; and what the module keeps of a
 * customer, exported (in words) and erased for PrestaShop's GDPR module
 * (Privacy\CustomerData), for the customer it names alone.
 *
 *     php tests/run-multistore-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';

use Wajub\PrestaShop\Admin\ConfigurationView;
use Wajub\PrestaShop\Admin\FormHandler;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Connection\KeyReminders;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Pairing\Pairing;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Privacy\CustomerData;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\PlainMoneyFormatter;
use Wajub\PrestaShop\Support\Translator;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\EventHandler;
use Wajub\PrestaShop\Webhook\PaymentEventHandler;
use Wajub\PrestaShop\Webhook\Signature;

const ALL_SECRET = 'whsec_test_allshops';
const SHOP2_SECRET = 'whsec_test_shoptwo';
const DAY = 86400;

final class SeenHandler implements EventHandler
{
    /** @var list<string> */
    public array $seen = [];

    public function handles(string $type): bool
    {
        return true;
    }

    public function handle(Event $event): void
    {
        $this->seen[] = $event->id . '@' . $event->environment;
    }
}

/**
 * The services of `scope`, on the settings saved so far, with a shop of
 * their own, and the payments and orders of every shop
 * ($GLOBALS['payments'], ['orders']).
 *
 * @param list<int> $shopIds the shops of a group or of all shops
 */
function scoped(Scope $scope, int $idShop = 1, string $baseUrl = 'https://boutique.cm/', array $shopIds = []): Services
{
    $shop = new FakeShop();
    $shop->id = $idShop;
    $shop->baseUrl = $baseUrl;
    $shop->name = 'Shop ' . $idShop;
    $shop->shopIds = $shopIds;
    $shop->production = $GLOBALS['production'] ?? true;
    $GLOBALS['payments'] = $GLOBALS['payments'] ?? new MemoryPaymentStore();
    $GLOBALS['orders'] = $GLOBALS['orders'] ?? new FakeShopOrders();

    return new Services($scope, '1.0.0', [
        'transport' => $GLOBALS['http'],
        'state' => $GLOBALS['store'],
        'configStore' => new ArrayConfigStore($scope),
        'cipher' => new FakeCipher(),
        'translator' => $GLOBALS['translator'],
        'logger' => $GLOBALS['logger'],
        'shop' => $shop,
        'events' => $GLOBALS['events'],
        'mailer' => $GLOBALS['mailer'],
        'pause' => static function (int $milliseconds): void {
        },
        'payments' => $GLOBALS['payments'],
        'refunds' => $GLOBALS['refunds'] ?? new MemoryRefundStore(),
        'orders' => $GLOBALS['orders'],
        'lock' => new MemoryLock(),
        'money' => new PlainMoneyFormatter(),
        'storefront' => new FakeStorefront(),
        'tokens' => new Tokens('test-secret'),
    ]);
}

/** A key "Connect with Wajub" gave `$s`'s scope for `environment`, expiring at `expiresAt` (null: not said). */
function paired(Services $s, string $environment, ?int $expiresAt, string $tag = 'A', string $home = 'boutique.cm'): string
{
    $key = key_for($environment, $tag);
    $s->settings()->setSecretKey($environment, $key);
    $s->settings()->setKeyOrigin($environment, [
        'via' => 'pairing',
        'business' => 'Boutique',
        'fp' => Keys::fingerprint($key),
        'at' => time() - DAY,
        'environment' => $environment,
        'home' => $home,
    ] + ($expiresAt !== null ? ['expires_at' => $expiresAt] : []));

    return $key;
}

function deliver(Services $s, string $id, string $secret): array
{
    $raw = (string) json_encode(['id' => $id, 'type' => 'payment.succeeded', 'data' => ['id' => 'trx_test_' . $id, 'status' => 'succeeded', 'sandbox' => true]]);
    $timestamp = time();

    return $s->receiver()->receive($raw, Signature::sign($raw, $secret, $timestamp), (string) $timestamp);
}

// === Scopes ====================================================================

check(Scope::fromSlug('all')?->isAllShops() === true, 'a slug names a scope: all shops');
$group = Scope::fromSlug('g3');
check($group !== null && $group->isGroup() && $group->idShopGroup === 3 && $group->slug() === 'g3', 'a group');
foreach (['', 'g', 'g0', 's-1', 'x2', 's1 ', 'all ', 'g1;DROP', 's99999999999'] as $bad) {
    check(Scope::fromSlug($bad) === null, 'nothing else names a scope: ' . json_encode($bad));
}
check((new Scope(4, 2))->chain() === ['s4', 'g2', 'all'] && (new Scope(null, 2))->chain() === ['g2', 'all'] && Scope::allShops()->chain() === ['all'], 'a value is read from the shop, then its group, then all shops');

// === Where each part of a connection comes from ===================================

services();
$all = scoped(Scope::allShops());
$all->settings()->setEnvironment('sandbox');
paired($all, 'sandbox', null, 'ALL');
$all->settings()->setWebhookSecret('sandbox', ALL_SECRET);
$shop2 = scoped(new Scope(2, 1), 2, 'https://two.boutique.cm/');
$shop3 = scoped(new Scope(3, 1), 3, 'https://three.boutique.cm/');

check($shop3->settings()->savedFor(Settings::ENVIRONMENT) === 'all' && !$shop3->settings()->hasOwnConnection(), 'a shop that saved nothing reads all shops\' connection');
check($shop3->environment()->activeKey() === key_for('sandbox', 'ALL'), 'and pays with all shops\' key');

$key2 = paired($shop2, 'sandbox', null, 'TWO', 'two.boutique.cm');
$shop2->settings()->setWebhookSecret('sandbox', SHOP2_SECRET);
$sources = $shop2->settings()->connectionSources();
check($sources['keys']['sandbox'] === 's2' && $sources['secrets']['sandbox'] === 's2' && $sources['environment'] === 's2' && $sources['keys']['live'] === '', 'a shop that connected on its own: its key, its secret and its environment are its own, no live key anywhere: ' . json_encode($sources));
check($shop2->settings()->hasOwnConnection() && $shop2->environment()->activeKey() === $key2, 'it pays with its own key');
check(scoped(new Scope(3, 1), 3)->environment()->activeKey() === key_for('sandbox', 'ALL'), 'the other shops still pay with all shops\' key');

// Removing a key from a shop is an empty value of its own: it does not fall back.
$shop3->settings()->setSecretKey('sandbox', '');
check(scoped(new Scope(3, 1), 3)->environment()->activeKey() === '' && scoped(new Scope(3, 1), 3)->settings()->savedFor('WAJUB_SECRET_KEY_SANDBOX') === 's3', 'a key removed from a shop leaves it without one, rather than with all shops\'');

// `inherit`: the shop forgets what it saved itself, and uses all shops' connection again.
$notices = (new FormHandler($shop2))->handle('inherit', [], [1]);
$again = scoped(new Scope(2, 1), 2);
check(($notices[0]['type'] ?? '') === 'success' && !$again->settings()->hasOwnConnection(), 'inherit: the shop has no connection of its own any more: ' . json_encode($notices));
check($again->environment()->activeKey() === key_for('sandbox', 'ALL') && $again->settings()->webhookSecret('sandbox') === ALL_SECRET && $again->settings()->keyOrigin('sandbox') !== null && $again->settings()->savedFor('WAJUB_KEY_ORIGIN_SANDBOX') === 'all', 'it pays with all shops\' key, and verifies with their signing secret');
check($all->environment()->activeKey() === key_for('sandbox', 'ALL') && scoped(Scope::allShops())->settings()->webhookSecret('sandbox') === ALL_SECRET, 'all shops keep theirs');
$notices = (new FormHandler(scoped(Scope::allShops())))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'info' && scoped(Scope::allShops())->environment()->activeKey() === key_for('sandbox', 'ALL'), 'inherit: nothing for all shops, which have nothing above them');
$notices = (new FormHandler(scoped(new Scope(2, 1), 2)))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'info', 'inherit: nothing for a shop that has nothing of its own');

// A group in between: a shop of the group reads the group's, before all shops'.
$group1 = scoped(new Scope(null, 1));
$group1->settings()->setEnvironment('live');
paired($group1, 'live', null, 'GROUP');
$shop4 = scoped(new Scope(4, 1), 4);
check($shop4->environment()->isLive() && $shop4->environment()->activeKey() === key_for('live', 'GROUP') && $shop4->settings()->savedFor(Settings::ENVIRONMENT) === 'g1', 'a shop of a group that connected reads the group\'s connection');

// === A shop's own connection is its own: environment and keys ========================

// All shops in Sandbox, with both keys of business A; shop 2 connects on its
// own to business B, in Sandbox, from its Connection tab.
services();
$all = scoped(Scope::allShops());
$all->settings()->setEnvironment('sandbox');
$all->settings()->setSecretKey('sandbox', key_for('sandbox', 'A'));
$all->settings()->setSecretKey('live', key_for('live', 'A'));
$GLOBALS['http']->routes['GET https://api.wajub.com/'] = static function (array $request): array {
    $authorization = implode(' ', $request['headers']);

    return [200, ['business' => str_contains($authorization, '.B') ? 'Business B' : 'Business A', 'env' => str_contains($authorization, 'sk_test.') ? 'sandbox' : 'production']];
};
$notices = (new FormHandler(scoped(new Scope(2, 1), 2)))->handle('connection', ['environment' => 'sandbox', 'sandbox_key' => key_for('sandbox', 'B')], [1]);
$shop2 = scoped(new Scope(2, 1), 2);
check($shop2->environment()->activeKey() === key_for('sandbox', 'B') && $shop2->settings()->savedFor(Settings::ENVIRONMENT) === 's2', 'a shop that saves a key of its own keeps its environment for itself: ' . json_encode([$notices, $shop2->settings()->connectionSources()]));
check($shop2->settings()->secretKey('live') === '' && $shop2->settings()->keySavedFor('live') === '' && $shop2->settings()->keysSavedFor() === 's2', 'and never pays with the live key saved for all shops, which may be another business\'s');
(new FormHandler(scoped(Scope::allShops())))->handle('connection', ['environment' => 'live'], [1]);
check(scoped(Scope::allShops())->environment()->isLive(), '(all shops switched to Live)');
$shop2 = scoped(new Scope(2, 1), 2);
check(!$shop2->environment()->isLive() && $shop2->environment()->activeKey() === key_for('sandbox', 'B'), 'all shops switched to Live: the shop that connected on its own stays in Sandbox, with its own key');
$notices = (new FormHandler(scoped(new Scope(2, 1), 2)))->handle('connection', ['environment' => 'live'], [1]);
$shop2 = scoped(new Scope(2, 1), 2);
check(!$shop2->environment()->isLive() && ($notices[0]['type'] ?? '') === 'error', 'Live for that shop is refused until it has a live key of its own: ' . json_encode($notices));
$shop3 = scoped(new Scope(3, 1), 3);
check($shop3->environment()->isLive() && $shop3->environment()->activeKey() === key_for('live', 'A'), 'a shop that saved nothing follows all shops into Live, with their key');

// All shops in Live with business A; shop 4 connects on its own to business B
// in Sandbox, its page showing Live, which it reads from above.
$notices = (new FormHandler(scoped(new Scope(4, 1), 4)))->handle('connection', ['environment' => 'live', 'sandbox_key' => key_for('sandbox', 'B')], [1]);
$shop4 = scoped(new Scope(4, 1), 4);
check($shop4->environment()->activeKey() === '' && !$shop4->environment()->isConfigured() && $shop4->environment()->liveKeyMissing(), 'a shop that connects on its own in Sandbox while it reads Live takes no payment, rather than real payments with all shops\' live key');
check(in_array('Live is selected and this shop has no live key of its own, so it takes no payments: connect Live as well to take real payments, or switch to Sandbox to make test payments.', array_column($notices, 'text'), true), 'and the merchant is told why, and what to do: ' . json_encode($notices));
$notices = (new FormHandler(scoped(new Scope(4, 1), 4)))->handle('connection', ['environment' => 'sandbox'], [1]);
check(scoped(new Scope(4, 1), 4)->environment()->activeKey() === key_for('sandbox', 'B'), 'Sandbox chosen then: it pays with its own sandbox key');

// A key removed from a shop is not a connection of its own: the shop still
// pays with the other key saved above it.
$shop5 = scoped(new Scope(5, 1), 5);
$shop5->settings()->setSecretKey('sandbox', '');
$shop5 = scoped(new Scope(5, 1), 5);
check($shop5->settings()->secretKey('sandbox') === '' && $shop5->environment()->activeKey() === key_for('live', 'A') && $shop5->settings()->savedFor(Settings::ENVIRONMENT) === 'all', 'a shop that only removed its sandbox key still pays in Live with all shops\' key, and follows their environment');

// Pairing in a shop: the paired key is saved as a pasted one, and the shop
// keeps its environment.
$paired = scoped(new Scope(6, 1), 6);
$paired->keyManager()->saveKey('sandbox', key_for('sandbox', 'B'));
check(scoped(new Scope(6, 1), 6)->settings()->savedFor(Settings::ENVIRONMENT) === 's6' && !scoped(new Scope(6, 1), 6)->environment()->isConfigured(), 'a key saved for a shop by "Connect with Wajub" (KeyManager::saveKey()) keeps its environment too');

// A key of its own saved without its environment (by an earlier version):
// the shop's next save keeps the environment it reads for itself.
(new ArrayConfigStore(new Scope(8, 1)))->set('WAJUB_SECRET_KEY_SANDBOX', (new FakeCipher())->encrypt(key_for('sandbox', 'C')));
check(scoped(new Scope(8, 1), 8)->settings()->savedFor(Settings::ENVIRONMENT) === 'all', '(a shop with a key of its own that reads its environment from above)');
(new FormHandler(scoped(new Scope(8, 1), 8)))->handle('connection', ['environment' => scoped(new Scope(8, 1), 8)->settings()->environment()], [1]);
check(scoped(new Scope(8, 1), 8)->settings()->savedFor(Settings::ENVIRONMENT) === 's8', 'its Connection form saved as it is: the environment is saved for the shop itself');

// A shop that only chose its own environment still pays with the keys above.
$shop7 = scoped(new Scope(7, 1), 7);
$shop7->settings()->setEnvironment('sandbox');
check(scoped(new Scope(7, 1), 7)->environment()->activeKey() === key_for('sandbox', 'A'), 'a shop that chose its own environment, and no key, pays with the keys of all shops');

// === Going back to the connection above, with payments under way ======================

services();
$GLOBALS['payments'] = new MemoryPaymentStore();
$all = scoped(Scope::allShops());
$all->settings()->setSecretKey('sandbox', key_for('sandbox', 'A'));
$shop2 = scoped(new Scope(2, 1), 2);
$shop2->settings()->setSecretKey('sandbox', key_for('sandbox', 'B'));
$open = saved_payment(['paymentId' => 'trx_test_paid_to_b', 'idOrder' => 700, 'idShop' => 2, 'state' => PaymentRecord::ACTIVE]);
$elsewhere = saved_payment(['paymentId' => 'trx_test_other_shop', 'idOrder' => 701, 'idShop' => 3, 'state' => PaymentRecord::ACTIVE]);
$GLOBALS['payments']->save($open);
$GLOBALS['payments']->save($elsewhere);
$notices = (new FormHandler(scoped(new Scope(2, 1), 2)))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'error' && str_contains($notices[0]['text'], '700') && !str_contains($notices[0]['text'], '701'), 'inherit refused while an order of the shop holds a payment made with its own key, named: ' . json_encode($notices));
check(scoped(new Scope(2, 1), 2)->environment()->activeKey() === key_for('sandbox', 'B'), 'the shop keeps its own key: the payment is read with it, never with the key above, which Wajub would not know it by');
$settled = $GLOBALS['payments']->find('trx_test_paid_to_b');
$settled->state = PaymentRecord::FINAL;
$GLOBALS['payments']->save($settled);
$notices = (new FormHandler(scoped(new Scope(2, 1), 2)))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'success' && str_contains($notices[0]['text'], 'refunded from there') && scoped(new Scope(2, 1), 2)->environment()->activeKey() === key_for('sandbox', 'A'), 'once it ended: the connection above, the merchant told where its paid orders are refunded: ' . json_encode($notices));
$shop3 = scoped(new Scope(3, 1), 3);
$shop3->settings()->setEnvironment('sandbox');
$notices = (new FormHandler(scoped(new Scope(3, 1), 3)))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'success', 'a shop that saved its environment only paid with the key above: nothing waits for its payments');
$group = scoped(new Scope(null, 1), 1, 'https://boutique.cm/', [2, 3]);
$group->settings()->setSecretKey('sandbox', key_for('sandbox', 'G'));
$notices = (new FormHandler(scoped(new Scope(null, 1), 1, 'https://boutique.cm/', [2, 3])))->handle('inherit', [], [1]);
check(($notices[0]['type'] ?? '') === 'error' && str_contains($notices[0]['text'], '701'), 'a group: the payments of its shops\' orders count');

// What the settings page says of it, scope by scope.
services();
$names = ['all' => 'All shops', 'g1' => 'Group Default'];
$languages = [['id_lang' => 1, 'name' => 'English', 'iso_code' => 'en']];
$all = scoped(Scope::allShops());
$all->settings()->setEnvironment('sandbox');
paired($all, 'sandbox', null, 'ALL');
$all->settings()->setWebhookSecret('sandbox', ALL_SECRET);
$group1 = scoped(new Scope(null, 1));
$group1->settings()->setEnvironment('sandbox');
$shop2 = scoped(new Scope(2, 1), 2);
paired($shop2, 'sandbox', null, 'TWO');
$shop2->settings()->setEnvironment('sandbox');
$view = (new ConfigurationView(scoped(new Scope(2, 1), 2)))->build(7, $languages, 'XAF', 'These settings are for the shop Two.', $names);
check($view['multistore']['on'] && $view['multistore']['own'] && !$view['multistore']['group'], 'a shop with a connection of its own: said so: ' . json_encode($view['multistore']));
check($view['multistore']['parent'] === 'Group Default', 'and the one it would use instead is its group\'s, which saved part of one, even where the shop overrides the same setting');
check($view['multistore']['environment_saved_for'] === 'this shop' && $view['environments']['sandbox']['saved_for'] === 'this shop', 'its environment and key are its own');
check($view['webhook']['environments']['sandbox']['saved_for'] === 'All shops' && $view['webhook']['inherited_from'] === 'All shops', 'its signing secret is all shops\', whose webhook it uses');
$view = (new ConfigurationView(scoped(new Scope(2, 1), 2)))->build(7, $languages, 'XAF', 'These settings are for the shop Two.', $names, ['all' => 'all shops', 'g1' => 'the group Default']);
check($view['multistore']['parent'] === 'Group Default' && $view['multistore']['parent_in_text'] === 'the group Default', 'inside a sentence, the scope above is named as a sentence names it ("the connection saved for the group Default")');
$view = (new ConfigurationView(scoped(new Scope(3, 1), 3)))->build(7, $languages, 'XAF', 'These settings are for the shop Three.', $names);
check(!$view['multistore']['own'] && $view['multistore']['parent'] === 'Group Default' && $view['multistore']['environment_saved_for'] === 'Group Default' && $view['environments']['sandbox']['saved_for'] === 'All shops', 'a shop that saved nothing: the connection of the scopes above, each part named: ' . json_encode([$view['multistore'], $view['environments']['sandbox']['saved_for']]));
$view = (new ConfigurationView(scoped(Scope::allShops())))->build(7, $languages, 'XAF', 'These settings are for all shops.', $names);
check($view['multistore']['on'] && $view['multistore']['own'] && $view['webhook']['inherited_from'] === '' && $view['environments']['sandbox']['saved_for'] === 'All shops', 'all shops: their own connection, nothing inherited');
$view = (new ConfigurationView(scoped(Scope::allShops())))->build(7, $languages, 'XAF', '', []);
check(!$view['multistore']['on'] && $view['environments']['sandbox']['saved_for'] === '' && $view['webhook']['inherited_from'] === '', 'one shop: nothing said of scopes');

// === The webhook of each scope ====================================================

services();
$all = scoped(Scope::allShops());
paired($all, 'sandbox', null, 'ALL');
$all->settings()->setWebhookSecret('sandbox', ALL_SECRET);
$shop2 = scoped(new Scope(2, 1), 2, 'https://two.boutique.cm/');
paired($shop2, 'sandbox', null, 'TWO');
$shop2->settings()->setWebhookSecret('sandbox', SHOP2_SECRET);

$GLOBALS['seen'] = new SeenHandler();
$receivingAll = scoped(Scope::allShops());
$receivingAll->addEventHandler($GLOBALS['seen']);
$receivingShop2 = scoped(new Scope(2, 1), 2);
$receivingShop2->addEventHandler($GLOBALS['seen']);

check(deliver($receivingAll, 'evt_test_all', ALL_SECRET)['status'] === 200, 'all shops\' endpoint: a delivery signed with all shops\' secret is verified');
check(deliver($receivingShop2, 'evt_test_two', SHOP2_SECRET)['status'] === 200, 'a shop\'s own endpoint: a delivery signed with its own secret is verified');
check(deliver($receivingShop2, 'evt_test_cross', ALL_SECRET)['status'] === 403, 'a shop\'s own endpoint does not take all shops\' secret: each endpoint has its own');
check(deliver($receivingAll, 'evt_test_cross2', SHOP2_SECRET)['status'] === 403, 'nor all shops\' endpoint the secret of a shop\'s');
check($GLOBALS['seen']->seen === ['evt_test_all@sandbox', 'evt_test_two@sandbox'], 'each verified event reaches the handlers once: ' . json_encode($GLOBALS['seen']->seen));
check(deliver($receivingShop2, 'evt_test_all', SHOP2_SECRET)['body']['duplicate'] ?? false, 'the same event delivered to another endpoint of the account is a duplicate, for the whole PrestaShop');
$healthAll = $receivingAll->webhookHealth()->state();
$healthTwo = $receivingShop2->webhookHealth()->state();
check($healthAll['state'] === 'receiving' && $healthTwo['state'] === 'receiving' && $healthAll['unverified'] !== null && $healthTwo['unverified'] !== null && $GLOBALS['store']->get('webhook_received:s2') !== null && $GLOBALS['store']->get('webhook_received:all') !== null, 'each scope keeps its own health, under its own name');
check(scoped(new Scope(3, 1), 3)->webhookHealth()->state()['state'] === 'never' && scoped(new Scope(3, 1), 3)->webhookHealth('all')->state()['state'] === 'receiving', 'a shop that uses all shops\' webhook reads that one\'s health');

// A shop that uses the webhook of all shops is not told to register its own.
$view = (new ConfigurationView(scoped(new Scope(3, 1), 3, 'https://three.boutique.cm/')))->build(7, [['id_lang' => 1, 'name' => 'English', 'iso_code' => 'en']], 'XAF', 'These settings are for the shop Three.', ['all' => 'All shops', 'g1' => 'Group Default'], ['all' => 'all shops', 'g1' => 'the group Default']);
check($view['webhook']['inherited_from'] === 'All shops' && $view['webhook']['inherited_in_text'] === 'all shops', '(shop 3 uses the webhook of all shops)');
$all->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_all', 'url' => 'https://boutique.cm/module/wajub/webhook?scope=all', 'at' => time()]);
$three = scoped(new Scope(3, 1), 3);
$three->shop()->webhookUrl = 'https://three.boutique.cm/module/wajub/webhook?scope=s3';
check(!$three->webhookRegistration()->moved('sandbox'), 'its address is not all shops\': it is not told the webhook moved, nor to register a second endpoint');
$allMoved = scoped(Scope::allShops());
$allMoved->shop()->webhookUrl = 'https://www.boutique.cm/module/wajub/webhook?scope=all';
check($allMoved->webhookRegistration()->moved('sandbox'), 'all shops, whose own endpoint has another address than theirs now, are');

// A shop in Sandbox that pays with the sandbox key of all shops, in Live: its
// test payments' deliveries reach all shops' sandbox endpoint, and settle
// its orders.
services();
$GLOBALS['payments'] = new MemoryPaymentStore();
$GLOBALS['orders'] = new FakeShopOrders();
$all = scoped(Scope::allShops());
$all->settings()->setSecretKey('sandbox', key_for('sandbox', 'ALL'));
$all->settings()->setSecretKey('live', key_for('live', 'ALL'));
$all->settings()->setWebhookSecret('sandbox', ALL_SECRET);
$all->settings()->setWebhookSecret('live', 'whsec_allshops_live');
$all->settings()->setEnvironment('live');
(new ArrayConfigStore())->set('WAJUB_OS_AWAITING', (string) FakeShopOrders::AWAITING);
(new ArrayConfigStore())->set('WAJUB_OS_PARTIAL', (string) FakeShopOrders::PARTIAL);
scoped(new Scope(3, 1), 3)->settings()->setEnvironment('sandbox');
$byShop = static function (int $idShop): Services {
    return scoped($idShop === 1 ? Scope::allShops() : new Scope($idShop, 1), $idShop);
};
$GLOBALS['orders']->add(77, 15000.0, 'XAF', ['shop' => 3]);
$GLOBALS['payments']->save(saved_payment(['paymentId' => 'trx_test_shop3', 'idOrder' => 77, 'idShop' => 3, 'idCart' => 177, 'environment' => 'sandbox', 'state' => PaymentRecord::ACTIVE, 'amount' => 15000.0]));
$receivingAll = scoped(Scope::allShops());
$receivingAll->addEventHandler(new PaymentEventHandler($receivingAll->payments(), $byShop, $GLOBALS['logger']));
$receivingAll->useShopServices($byShop);
$raw = (string) json_encode(['id' => 'evt_test_shop3', 'type' => 'payment.succeeded', 'data' => payment_data('trx_test_shop3')]);
$now = time();
$answer = $receivingAll->receiver()->receive($raw, Signature::sign($raw, ALL_SECRET, $now), (string) $now);
check($answer['status'] === 200 && in_array((int) $GLOBALS['orders']->orders[77]['state'], FakeShopOrders::PAID_STATES, true), 'a test payment of a shop in Sandbox, delivered to the endpoint of all shops in Live: the shop\'s order is paid: ' . json_encode([$answer, $GLOBALS['orders']->orders[77]['state']]));
$GLOBALS['orders']->add(78, 15000.0, 'XAF', ['shop' => 1]);
$GLOBALS['payments']->save(saved_payment(['paymentId' => 'trx_test_allshop', 'idOrder' => 78, 'idShop' => 1, 'idCart' => 178, 'environment' => 'sandbox', 'state' => PaymentRecord::ACTIVE, 'amount' => 15000.0]));
$raw = (string) json_encode(['id' => 'evt_test_allshop', 'type' => 'payment.succeeded', 'data' => payment_data('trx_test_allshop')]);
$answer = $receivingAll->receiver()->receive($raw, Signature::sign($raw, ALL_SECRET, $now), (string) $now);
check($answer['status'] === 200 && (int) $GLOBALS['orders']->orders[78]['state'] === FakeShopOrders::AWAITING && $GLOBALS['logger']->has('Webhook from another environment not acted on'), 'one of a shop in Live (an old test payment) still pays nothing');

// A shop in Live that pays with the live key of all shops, in Sandbox, in a
// copy of the PrestaShop (debug mode): its live deliveries are acted on, not
// sent back again and again.
services();
$GLOBALS['payments'] = new MemoryPaymentStore();
$GLOBALS['orders'] = new FakeShopOrders();
$GLOBALS['production'] = false;
$all = scoped(Scope::allShops());
$all->settings()->setSecretKey('sandbox', key_for('sandbox', 'ALL'));
$all->settings()->setSecretKey('live', key_for('live', 'ALL'));
$all->settings()->setWebhookSecret('live', 'whsec_allshops_live');
$all->settings()->setEnvironment('sandbox');
(new ArrayConfigStore())->set('WAJUB_OS_AWAITING', (string) FakeShopOrders::AWAITING);
(new ArrayConfigStore())->set('WAJUB_OS_PARTIAL', (string) FakeShopOrders::PARTIAL);
scoped(new Scope(2, 1), 2)->settings()->setEnvironment('live');
$GLOBALS['orders']->add(79, 15000.0, 'XAF', ['shop' => 2]);
$GLOBALS['payments']->save(saved_payment(['paymentId' => 'trx_live_shop2', 'idOrder' => 79, 'idShop' => 2, 'idCart' => 179, 'environment' => 'live', 'state' => PaymentRecord::ACTIVE, 'amount' => 15000.0]));
$receivingAll = scoped(Scope::allShops());
$receivingAll->addEventHandler(new PaymentEventHandler($receivingAll->payments(), $byShop, $GLOBALS['logger']));
$receivingAll->useShopServices($byShop);
$raw = (string) json_encode(['id' => 'evt_live_shop2', 'type' => 'payment.succeeded', 'data' => payment_data('trx_live_shop2', 'succeeded', 15000.0, ['sandbox' => false])]);
$answer = $receivingAll->receiver()->receive($raw, Signature::sign($raw, 'whsec_allshops_live', $now), (string) $now);
check($answer['status'] === 200 && in_array((int) $GLOBALS['orders']->orders[79]['state'], FakeShopOrders::PAID_STATES, true), 'a live payment of a shop in Live, delivered to all shops in Sandbox in a copy: acted on: ' . json_encode($answer));
$GLOBALS['production'] = true;

// === Reminders of keys that expire ==================================================

$now = time();
services();
$GLOBALS['mailer']->emails = [1 => 'owner@boutique.cm', 2 => 'two@boutique.cm'];
$all = scoped(Scope::allShops());
$byScope = ['all' => $all];
$reminders = static function () use (&$byScope): KeyReminders {
    return new KeyReminders(scoped(Scope::allShops())->settings(), static function (string $slug) use (&$byScope): ?Services {
        return $byScope[$slug] ?? null;
    }, $GLOBALS['mailer'], $GLOBALS['logger']);
};

$liveKey = paired($all, 'live', $now + 10 * DAY);
$all->settings()->setEnvironment('live');
$due = $reminders()->due($now);
check(count($due) === 1 && $due[0]['scope'] === 'all' && $due[0]['environment'] === 'live' && $due[0]['state'] === 'expiring' && $due[0]['in_use'], 'a key that expires in 10 days is due, and in use: ' . json_encode(array_map(static function (array $d): array {
    unset($d['services']);

    return $d;
}, $due)));
check(str_contains($due[0]['warning'], 'The live key Connect with Wajub created for this shop expires on ' . gmdate('Y-m-d', $now + 10 * DAY)), 'its warning says which key stops, and when: ' . $due[0]['warning']);
check($reminders()->remind($now) === 1, 'one e-mail');
$mail = $GLOBALS['mailer']->sent[0] ?? [];
check(($mail['to'] ?? '') === 'owner@boutique.cm' && ($mail['shop'] ?? 0) === 1 && ($mail['template'] ?? '') === KeyReminders::TEMPLATE && ($mail['subject'] ?? '') === 'Connect with Wajub again', 'to the shop\'s e-mail address, with its template and subject: ' . json_encode($mail));
check(str_contains($mail['vars']['message'] ?? '', 'expires on ' . gmdate('Y-m-d', $now + 10 * DAY)) && str_contains($mail['vars']['advice'] ?? '', '“No expiry”') && str_contains($mail['vars']['where'] ?? '', 'Connect with Wajub'), 'it says which key stops, when, where to connect again, and the choice that avoids it next time');
check(!leaked($liveKey, $GLOBALS['mailer']->sent) && !leaked(Keys::fingerprint($liveKey), $GLOBALS['mailer']->sent), 'never the key, nor its fingerprint');
check((scoped(Scope::allShops())->keyOrigins()->origin('live')['reminded_at'] ?? 0) === $now, 'the key\'s origin keeps when it was sent');
check($reminders()->remind($now + DAY) === 0 && $reminders()->remind($now + 20 * DAY) === 0 && count($GLOBALS['mailer']->sent) === 1, 'once a key, expired since included');
check(count($reminders()->due($now + DAY)) === 1, 'the key is still due after its e-mail: the notice keeps saying it');

// Accepted by Wajub after its date: the date was wrong, and is forgotten with its e-mail.
$all->keyOrigins()->accepted('live', $liveKey, $now + 11 * DAY);
$origin = scoped(Scope::allShops())->keyOrigins()->origin('live');
check($origin !== null && $origin['expires_at'] === null && $origin['reminded_at'] === 0, 'a key Wajub accepts after its date: its date and its e-mail are forgotten');

services();
$GLOBALS['mailer']->emails = [1 => 'owner@boutique.cm', 2 => 'two@boutique.cm'];
$all = scoped(Scope::allShops());
$byScope = ['all' => $all];
$all->settings()->setEnvironment('live');
paired($all, 'live', $now + 20 * DAY);
check($reminders()->due($now) === [] && $reminders()->remind($now) === 0, 'nothing more than 14 days ahead');
paired($all, 'live', 0);
check($reminders()->due($now) === [], 'nothing for a key that does not expire');
paired($all, 'live', null);
check($reminders()->due($now) === [], 'nothing for a key whose expiry the Dashboard did not say');
paired($all, 'sandbox', $now - DAY);
$due = $reminders()->due($now);
check(count($due) === 1 && $due[0]['state'] === 'expired' && !$due[0]['in_use'] && str_contains($due[0]['warning'], 'The sandbox key Connect with Wajub created for this shop expired on'), 'a sandbox key found expired is said to be, in its words; not in use while Sandbox is not selected');
check($reminders()->remind($now) === 1, 'it gets its e-mail too: every key, whichever environment the shop uses');
paired($all, 'live', $now + DAY);
$all->settings()->setSecretKey('live', 'sk.PASTED' . str_repeat('q', 92));
check(array_column($reminders()->due($now), 'environment') === ['sandbox'], 'nothing for a key pasted since the pairing');

services();
$GLOBALS['mailer']->emails = [1 => 'owner@boutique.cm', 2 => 'two@boutique.cm'];
$all = scoped(Scope::allShops());
paired($all, 'live', $now + DAY, 'A', 'staging.boutique.cm');
$byScope = ['all' => $all];
check(count($reminders()->due($now)) === 1 && $reminders()->remind($now) === 0 && $GLOBALS['mailer']->sent === [], 'none from a copy of the shop at another address, which the shop that paired the key sends; the copy still says the key expires');

services();
$GLOBALS['mailer']->emails = [];
$all = scoped(Scope::allShops());
paired($all, 'live', $now + DAY);
$byScope = ['all' => $all];
check($reminders()->remind($now) === 0 && (scoped(Scope::allShops())->keyOrigins()->origin('live')['reminded_at'] ?? 1) === 0, 'no e-mail address: nothing sent, and the key is not marked: an address saved later gets it');

// Every scope's keys, each e-mailed from its own shop.
services();
$GLOBALS['mailer']->emails = [1 => 'owner@boutique.cm', 2 => 'two@boutique.cm'];
$all = scoped(Scope::allShops());
$shop2 = scoped(new Scope(2, 1), 2, 'https://two.boutique.cm/');
$byScope = ['all' => $all, 's2' => $shop2];
paired($all, 'sandbox', $now + 3 * DAY, 'ALL');
paired($shop2, 'sandbox', $now + 5 * DAY, 'TWO', 'two.boutique.cm');
$due = $reminders()->due($now);
check(array_column($due, 'scope') === ['all', 's2'] && $due[0]['in_use'] && $due[1]['in_use'], 'each scope\'s key is due, the shop\'s own included: ' . json_encode(array_column($due, 'scope')));
check($reminders()->remind($now) === 2 && array_column($GLOBALS['mailer']->sent, 'to') === ['owner@boutique.cm', 'two@boutique.cm'] && array_column($GLOBALS['mailer']->sent, 'shop') === [1, 2], 'one e-mail each, to its own shop\'s address');
check((scoped(new Scope(2, 1), 2)->keyOrigins()->origin('sandbox')['reminded_at'] ?? 0) === $now && (scoped(Scope::allShops())->keyOrigins()->origin('sandbox')['reminded_at'] ?? 0) === $now, 'each origin keeps its own e-mail, in its own scope');
check(scoped(new Scope(3, 1), 3)->keyOrigins()->origin('sandbox')['reminded_at'] === $now && count($reminders()->due($now)) === 2, 'a shop that inherits all shops\' key is not a key of its own: no third reminder');
$byScope = ['all' => $all];
check(array_column($reminders()->due($now), 'scope') === ['all'], 'a shop the PrestaShop no longer has is left out');

// A key is in use when a shop pays with it, whatever environment the scope
// that saved it uses itself.
$shopReminders = static function () use (&$byScope): KeyReminders {
    return new KeyReminders(scoped(Scope::allShops())->settings(), static function (string $slug) use (&$byScope): ?Services {
        return $byScope[$slug] ?? null;
    }, $GLOBALS['mailer'], $GLOBALS['logger'], ['s2', 's3'], static function (string $slug, Translator $t): string {
        return $slug === 'all' ? $t->trans('All shops', [], 'Modules.Wajub.Admin') : 'Shop ' . substr($slug, 1);
    });
};
services();
$GLOBALS['mailer']->emails = [1 => 'owner@boutique.cm', 2 => 'two@boutique.cm', 3 => 'three@boutique.cm'];
$all = scoped(Scope::allShops());
$all->settings()->setEnvironment('live');
paired($all, 'live', null, 'ALL');
paired($all, 'sandbox', $now + 3 * DAY, 'ALL');
$byScope = ['all' => $all, 's2' => scoped(new Scope(2, 1), 2), 's3' => scoped(new Scope(3, 1), 3)];
$due = $shopReminders()->due($now);
check(count($due) === 1 && !$due[0]['in_use'] && $due[0]['shops'] === [], 'all shops in Live, their sandbox key expiring: no shop pays with it, no notice');
scoped(new Scope(3, 1), 3)->settings()->setEnvironment('sandbox');
$byScope = ['all' => $all, 's2' => scoped(new Scope(2, 1), 2), 's3' => scoped(new Scope(3, 1), 3)];
$due = $shopReminders()->due($now);
check(count($due) === 1 && $due[0]['in_use'] && $due[0]['shops'] === [3], 'shop 3 switched to Sandbox pays with that key: in use, by shop 3: ' . json_encode(array_map(static function (array $d): array {
    unset($d['services']);

    return $d;
}, $due)));
check($shopReminders()->remind($now) === 1 && str_contains($GLOBALS['mailer']->sent[0]['vars']['where'] ?? '', 'choose “All shops” in the shop selector'), 'its e-mail names the scope to choose in the shop selector before connecting again: ' . ($GLOBALS['mailer']->sent[0]['vars']['where'] ?? ''));

services();
$all = scoped(Scope::allShops());
$all->settings()->setEnvironment('sandbox');
paired($all, 'sandbox', null, 'ALL');
paired($all, 'live', $now + 5 * DAY, 'ALL');
scoped(new Scope(2, 1), 2)->settings()->setEnvironment('live');
$byScope = ['all' => $all, 's2' => scoped(new Scope(2, 1), 2), 's3' => scoped(new Scope(3, 1), 3)];
$due = $shopReminders()->due($now);
check(count($due) === 1 && $due[0]['environment'] === 'live' && $due[0]['in_use'] && $due[0]['shops'] === [2], 'all shops in Sandbox, their live key expiring, shop 2 in Live with it: in use, by shop 2');
$own = scoped(new Scope(3, 1), 3);
$own->settings()->setEnvironment('live');
paired($own, 'live', null, 'THREE');
$byScope = ['all' => $all, 's2' => scoped(new Scope(2, 1), 2), 's3' => scoped(new Scope(3, 1), 3)];
$due = $shopReminders()->due($now);
check(count($due) === 1 && $due[0]['shops'] === [2], 'a shop in Live with a live key of its own does not pay with all shops\'');
$worksOn = static function (array $shops): callable {
    return static function (int $idShop) use ($shops): bool {
        return in_array($idShop, $shops, true);
    };
};
check(count(KeyReminders::shownTo($due, $worksOn([2]))) === 1 && KeyReminders::shownTo($due, $worksOn([3])) === [], 'the back office warns an employee of shop 2, which pays with the key, not one who works on shop 3 only');
$notInUse = $due;
$notInUse[0]['in_use'] = false;
$single = $due;
$single[0]['shops'] = [];
check(KeyReminders::shownTo($notInUse, $worksOn([2])) === [] && count(KeyReminders::shownTo($single, $worksOn([]))) === 1, 'never a key no shop pays with; without multistore, every employee who may open the settings');

$GLOBALS['mailer']->fails = true;
services();
$GLOBALS['mailer']->fails = true;
$all = scoped(Scope::allShops());
$byScope = ['all' => $all];
paired($all, 'live', $now + DAY);
check($reminders()->remind($now) === 0 && count($GLOBALS['mailer']->sent) === 1 && $reminders()->remind($now + 60) === 0 && count($GLOBALS['mailer']->sent) === 1, 'a send that fails is not tried again on every page: marked before it is sent');
check(Pairing::addressOf('https://www.Boutique.cm:8443/shop/') === 'boutique.cm:8443/shop' && Pairing::addressOf('') === '', 'an address as a copy of the shop would not share it');

// === What the module keeps of a customer ==============================================

$s = payment_services();
$confirmations = new HeldConfirmations($GLOBALS['store'], static function (array $mail): bool {
    return true;
});
$data = new CustomerData($GLOBALS['payments'], $GLOBALS['refunds'], $confirmations, $GLOBALS['translator']);

$payment = new PaymentRecord();
$payment->paymentId = 'trx_test_gdpr1';
$payment->idOrder = 41;
$payment->idCustomer = 7;
$payment->environment = 'sandbox';
$payment->method = 'mobile_money';
$payment->channel = 'cm.mtn';
$payment->state = PaymentRecord::FINAL;
$payment->status = 'succeeded';
$payment->currency = 'XAF';
$payment->amount = 15000.0;
$payment->amountPaid = 15000.0;
$payment->metadata = ['token' => 'secret-session-token', 'authorization_url' => 'https://pay.wajub.com/x'];
$GLOBALS['payments']->save($payment);
$other = clone $payment;
$other->id = 0;
$other->paymentId = 'trx_test_gdpr2';
$other->idCustomer = 8;
$other->idOrder = 42;
$GLOBALS['payments']->save($other);
$prepared = clone $payment;
$prepared->id = 0;
$prepared->paymentId = 'trx_test_gdpr3';
$prepared->idOrder = 0;
$prepared->state = PaymentRecord::PREPARED;
$GLOBALS['payments']->save($prepared);

$rows = $data->export([7]);
check(count($rows) === 1 && ($rows[0]['Wajub payment ID'] ?? '') === 'trx_test_gdpr1' && ($rows[0]['Amount paid'] ?? '') === '15000' && ($rows[0]['Environment'] ?? '') === 'Sandbox', 'export: the customer\'s payments, with named columns: ' . json_encode($rows));
check(($rows[0]['Payment method'] ?? '') === 'MTN Mobile Money' && ($rows[0]['Status'] ?? '') === 'Paid', 'in words the customer reads, never Wajub\'s codes (cm.mtn, succeeded)');
$unnamed = clone $payment;
$unnamed->id = 0;
$unnamed->paymentId = 'trx_test_gdpr4';
$unnamed->idCustomer = 9;
$unnamed->channel = '';
$unnamed->method = 'card';
$unnamed->status = 'failed';
$GLOBALS['payments']->save($unnamed);
$rows9 = $data->export([9]);
check(($rows9[0]['Payment method'] ?? '') === 'Card' && ($rows9[0]['Status'] ?? '') === 'Failed', 'a payment that never said its channel: its kind, in words: ' . json_encode($rows9));

// Who the GDPR module names: the customer it hands over, alone.
$byEmail = static function (string $email): array {
    return $email === 'amina@example.cm' ? [7, 12] : [];
};
check(CustomerData::customerIds(['id' => 12, 'email' => 'amina@example.cm', 'firstname' => 'Amina'], $byEmail) === [12], 'a customer handed over (an account opened with a guest\'s e-mail address, unverified): that customer alone, never the guest with the same address');
check(CustomerData::customerIds(['email' => 'amina@example.cm'], $byEmail) === [7, 12] && CustomerData::customerIds(['email' => 'nobody@example.cm'], $byEmail) === [], 'an e-mail address alone (the back office\'s request for someone with no account): its customers, guests included');
check(CustomerData::customerIds(['phone' => '+237677123456'], $byEmail) === [] && CustomerData::customerIds('7', $byEmail) === [], 'a phone number alone, or anything else, names nobody');
check(!leaked('secret-session-token', $rows) && !leaked('trx_test_gdpr2', $rows) && !leaked('trx_test_gdpr3', $rows), 'never a session token, another customer\'s payment, or a payment prepared for a cart never ordered');
check($data->export([]) === [] && $data->export([0, -3]) === [], 'no customer, nothing');

$confirmations->hold(['template' => 'order_conf', 'templateVars' => ['{id_order}' => '41', '{email}' => 'amina@example.cm'], 'idLang' => 1, 'subject' => 'Order', 'to' => 'amina@example.cm', 'toName' => 'Amina', 'from' => null, 'fromName' => null, 'templatePath' => '', 'idShop' => 1, 'bcc' => null, 'replyTo' => null, 'replyToName' => null, 'fileAttachment' => null]);
$confirmations->hold(['template' => 'order_conf', 'templateVars' => ['{id_order}' => '42'], 'idLang' => 1, 'subject' => 'Order', 'to' => 'other@example.cm', 'toName' => 'Other', 'from' => null, 'fromName' => null, 'templatePath' => '', 'idShop' => 1, 'bcc' => null, 'replyTo' => null, 'replyToName' => null, 'fileAttachment' => null]);
$held41 = $confirmations->holds(41);
$erased = $data->erase([7], [43]);
check($held41 && !$confirmations->holds(41) && $confirmations->holds(42), 'erase: the confirmation held for the customer\'s order is gone, another customer\'s stays');
check($erased['confirmations'] === 1 && $erased['payments'] === 2, 'erase: what it did: ' . json_encode($erased));
check($GLOBALS['payments']->find('trx_test_gdpr1')?->idCustomer === 0 && $GLOBALS['payments']->find('trx_test_gdpr1')?->idOrder === 41 && $GLOBALS['payments']->find('trx_test_gdpr2')?->idCustomer === 8, 'erase: the payments stay, the record of the order\'s money, but no longer name the customer');
check($data->export([7]) === [], 'erased, the customer has nothing left to export');

done('multistore');

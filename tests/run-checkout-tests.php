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
 * The checkout (Checkout\*): which options show for a cart, from what the
 * account offers (GET /payments/options, asked once, three seconds at most,
 * kept ten minutes, a failure one minute, never from the back office), what
 * each restricts its payments to (`accept.types`), the payment prepared for
 * the form (prepared, reused, brought to the cart's total, renewed, refused,
 * limited per visitor and for the shop), and the order's payment once
 * placed: the form's payment activated (the same body every time, a double
 * submit activating nothing twice, a refusal read), or a payment on Wajub's
 * hosted page. Then the small helpers: the payer's phone, the tokens, the
 * return address Wajub accepts, the logos' picture.
 *
 *     php tests/run-checkout-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Checkout\KeptNotices;
use Wajub\PrestaShop\Checkout\Offer;
use Wajub\PrestaShop\Checkout\PreparedPayments;
use Wajub\PrestaShop\Checkout\PrepareEndpoint;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Payment\LogoStrip;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Support\CheckoutLocale;
use Wajub\PrestaShop\Support\Phone;

const API = 'https://api.wajub.com';

if (!class_exists('Tools')) {
    /** What LogoStrip reads its pictures with. */
    final class Tools
    {
        public static function file_get_contents(string $path): string|false
        {
            return is_file($path) ? (string) file_get_contents($path) : false;
        }
    }
}

/**
 * The channels an account offers, as GET /payments/options lists them.
 *
 * @param list<array{0: string, 1: string}> $channels [slug, type]
 */
function options_answer(array $channels): array
{
    return [200, ['channels' => array_map(static fn (array $c): array => ['id' => $c[0], 'slug' => $c[0], 'name' => $c[0], 'type' => $c[1]], $channels)]];
}

function options_requests(): int
{
    return count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'GET' && str_starts_with($r['url'], API . '/payments/options')));
}

/** A prepared payment, as POST /payments with `deferred: true` answers it. */
function prepared_answer(string $id = 'trx_test_p1', string $session = 'sess_p1'): array
{
    return [201, ['id' => $id, 'reference' => 'psf_x', 'authorization_token' => $session, 'authorization_url' => 'https://pay.wajub.com/' . $id, 'status' => 'pending']];
}

function prepare_request(Services $s, array $request, ?Wajub\PrestaShop\Checkout\CartView $cart = null, string $ip = '10.0.0.1', ?int $now = null): array
{
    $cart = $cart ?? cart_view();
    $token = $s->tokens()->checkout($cart->id, $cart->idCustomer);

    return $s->prepareEndpoint()->answer($request + ['token' => $token], $cart, $token, $ip, PrepareEndpoint::LIMITS, $now);
}

// --- Which options show for a cart (Offer, CheckoutOptions) ------------------------

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['cm.orange', 'mobile'], ['card', 'card'], ['paypal', 'paypal'], ['apple_pay', 'wallet']])];
$options = $s->checkoutOptions()->forCart(cart_view(), 1, 'XAF');
check(array_column($options, 'kind') === ['mobile_money', 'card', 'other'], 'every option shows when the account offers Mobile Money, cards and more');
check(array_column($options, 'mode') === ['fields', 'fields', 'hosted'], 'Mobile Money and Card take the form, Other payment methods the hosted page');
check($options[0]['title'] === 'Mobile Money' && $options[1]['title'] === 'Card' && $options[2]['title'] === 'Other payment methods', 'default titles');
check($options[0]['logos'] === ['mtn', 'orange'] && $options[1]['logos'] === ['visa', 'mastercard'], 'default logos: the operators of the currency, the cards');
check($options[2]['logos'] === ['paypal', 'applepay'], 'Other payment methods shows the logos of the other channels the account offers');
check(options_requests() === 1, 'the account is asked once for the whole checkout');
$request = $GLOBALS['http']->sent[0];
check($request['timeout'] === 3, 'asked with three seconds at most');
parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
check(($query['currency'] ?? '') === 'XAF' && (float) ($query['amount'] ?? 0) === 15000.0 && ($query['country'] ?? '') === 'CM', 'asked for the cart\'s currency, total and country');
check($s->offer()->typesLeft('XAF', 15000, 'CM') === ['paypal', 'wallet'], 'the types left for Other payment methods: what Mobile Money and cards do not cover');

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile']])];
$options = $s->checkoutOptions()->forCart(cart_view(), 1, 'XAF');
check(array_column($options, 'kind') === ['mobile_money'], 'an account with Mobile Money only: no Card, no Other payment methods');

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['card', 'card']])];
check(array_column($s->checkoutOptions()->forCart(cart_view(), 1, 'XAF'), 'kind') === ['card'], 'an account with cards only: no Mobile Money');

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [[500, ['message' => 'down']]];
$options = $s->checkoutOptions()->forCart(cart_view(), 1, 'XAF');
check(array_column($options, 'kind') === ['mobile_money', 'card'], 'Wajub down: Mobile Money and Card show, Other payment methods does not');
check(options_requests() === 1, 'asked once: no second try on a failure');
check($GLOBALS['store']->ttl(array_key_first(array_filter($GLOBALS['store']->rows, static fn ($k) => str_starts_with($k, 'offer_failed:'), ARRAY_FILTER_USE_KEY))) === 60, 'the failure is kept a minute');

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [[500, ['message' => 'down']], options_answer([['cm.mtn', 'mobile_money'], ['paypal', 'paypal']])];
$s->offer()->offer('XAF', 15000, 'CM');
$later = new Offer($s->settings(), $s->environment(), $GLOBALS['store'], function () use ($s): Client {
    return $s->client();
}, $GLOBALS['logger']);
check($later->offer('XAF', 15000, 'CM') === null && options_requests() === 1, 'another page within the minute: the failure kept, Wajub not asked');
$GLOBALS['store']->now = time() + 61;
$minuteLater = new Offer($s->settings(), $s->environment(), $GLOBALS['store'], function () use ($s): Client {
    return $s->client();
}, $GLOBALS['logger']);
check($minuteLater->offer('XAF', 15000, 'CM') !== null && options_requests() === 2, 'a minute later Wajub is asked again');
$GLOBALS['store']->now = 0;

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money']])];
$s->offer()->offer('XAF', 15000, 'CM');
$key = (string) array_key_first(array_filter($GLOBALS['store']->rows, static fn ($k) => str_starts_with($k, 'offer:'), ARRAY_FILTER_USE_KEY));
check($GLOBALS['store']->ttl($key) === 600, 'an answer is kept ten minutes');
$other = new Offer($s->settings(), $s->environment(), $GLOBALS['store'], function () use ($s): Client {
    return $s->client();
}, $GLOBALS['logger']);
$other->offer('XAF', 17900, 'CM');
check(options_requests() === 1, 'a total of the same magnitude reads the answer kept');
$other->offer('XAF', 150000, 'CM');
check(options_requests() === 2, 'a total ten times larger asks again');
$GLOBALS['store']->rows = [];
$other->offer('XAF', 150000, 'CM');
check(options_requests() === 2, 'the request keeps what it was told, whatever the store lost');

$s = payment_services();
$backOffice = new Offer($s->settings(), $s->environment(), $GLOBALS['store'], function () use ($s): Client {
    return $s->client();
}, $GLOBALS['logger'], true);
check($backOffice->offer('XAF', 15000, 'CM') === null && $backOffice->showsKind('card', cart_view()) && $backOffice->showsOther(cart_view()), 'the back office never asks Wajub: the options show as they are set');
check(options_requests() === 0, 'and sends nothing');

$s = payment_services();
$s->settings()->setMethodEnabled('mobile_money', false);
$s->settings()->setMethodEnabled('card', false);
$options = $s->checkoutOptions()->forCart(cart_view(), 1, 'XAF');
check(array_column($options, 'kind') === ['other'] && options_requests() === 0, 'Mobile Money and Card off: one option, offering everything, nothing asked');
check($options[0]['title'] === 'Mobile Money or card' && $options[0]['description'] === 'Pay with Mobile Money, card, or other methods via Wajub.', 'named as the one option for everything');
check($options[0]['logos'] === ['mtn', 'orange', 'visa', 'mastercard'], 'with the operators and the cards');

$s = payment_services();
$s->settings()->setSecretKey('sandbox', '');
check($s->checkoutOptions()->forCart(cart_view(), 1, 'XAF') === [], 'no key for the environment in use: no option at all');

$s = payment_services();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['card', 'card'], ['paypal', 'paypal']])];
$s->settings()->setMethodTexts('mobile_money', [1 => 'Payer par téléphone', 2 => ''], [1 => "Ligne 1\nLigne 2", 2 => '']);
$s->settings()->setMethodLogos('card', ['visa']);
$options = $s->checkoutOptions()->forCart(cart_view(), 1, 'XAF');
check($options[0]['title'] === 'Payer par téléphone' && $options[0]['description'] === "Ligne 1\nLigne 2", 'the merchant\'s title and description, in the page\'s language');
check($s->checkoutOptions()->title('mobile_money', 2) === 'Mobile Money', 'an empty title reads as the default');
check($s->checkoutOptions()->description('mobile_money', 2) === '', 'a description cleared stays cleared');
check($options[1]['logos'] === ['visa'], 'the merchant\'s choice of logos');
$config = $s->checkoutOptions()->clientConfig('mobile_money', 'fr');
check($config['component'] === 'mobileMoney' && $config['componentOptions'] === ['variant' => 'fields', 'fields' => ['customer' => 'external']] && $config['locale'] === 'fr', 'the form: Wajub.js\'s mobileMoney fields, the customer the shop\'s, the operators listed');
$s->settings()->setOperatorFromNumber(true);
check($s->checkoutOptions()->clientConfig('mobile_money', 'fr')['componentOptions']['fields']['operator'] === 'detect', 'the operator found from the number, when the merchant asks it');
check(!str_contains((string) json_encode($s->checkoutOptions()->clientConfig('card', 'fr')), 'sk_test'), 'no key in what the page gets');

// --- The payment prepared for the form (PreparedPayments, PrepareEndpoint) ----------------

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [prepared_answer()];
$answer = prepare_request($s, ['kind' => 'mobile_money']);
check($answer['status'] === 200 && $answer['body']['data'] === ['kind' => 'mobile_money', 'paymentId' => 'trx_test_p1', 'sessionId' => 'sess_p1', 'currency' => 'XAF'], 'the box gets the payment\'s id, its session and its currency, nothing else');
$body = $GLOBALS['http']->json(0);
check(($body['deferred'] ?? null) === true && $body['amount'] === 15000 && $body['currency'] === 'XAF' && $body['expires'] === ['in' => 120], 'prepared for the cart\'s total, deferred, for two hours');
check($body['accept'] === ['types' => ['mobile_money', 'mobile']], 'restricted to Mobile Money\'s channel types');
check(str_starts_with((string) $body['reference'], 'psf_') && !isset($body['customer']) && ($body['metadata']['cart_id'] ?? '') === '501', 'a reference that names no order, no customer yet, the cart in its metadata');
$slot = $GLOBALS['payments']->find('trx_test_p1');
check($slot !== null && $slot->state === PaymentRecord::PREPARED && $slot->idCart === 501 && $slot->idOrder === 0 && $slot->environment === 'sandbox' && $slot->sessionToken() === 'sess_p1', 'kept as the cart\'s prepared payment, in Sandbox');
check($slot !== null && $slot->meta('key') !== '' && !str_contains((string) json_encode($slot->metadata), 'sk_test'), 'with the fingerprint of the key that made it, never the key');

prepare_request($s, ['kind' => 'mobile_money']);
check(count($GLOBALS['http']->sent) === 1, 'the same cart again: the same payment, nothing sent');

$s->preparedPayments();
$GLOBALS['http']->routes['PATCH ' . API . '/payments/trx_test_p1'] = [[200, ['id' => 'trx_test_p1', 'amount' => 18935, 'currency' => 'XAF', 'status' => 'pending']]];
$answer = prepare_request($s, ['kind' => 'mobile_money'], cart_view(501, 18935));
check($answer['body']['data']['paymentId'] === 'trx_test_p1' && $GLOBALS['http']->last()['method'] === 'PATCH', 'a new total (a carrier, a coupon): the same payment, brought to it');
check(($GLOBALS['http']->json(1)['amount'] ?? null) === 18935 && $GLOBALS['payments']->find('trx_test_p1')->amount === 18935.0, 'and the shop keeps its new amount');

$GLOBALS['http']->routes['PATCH ' . API . '/payments/trx_test_p1'] = [[422, ['message' => 'No payment method', 'errors' => ['accept' => ['No channel left']]]]];
$answer = prepare_request($s, ['kind' => 'mobile_money'], cart_view(501, 2000000));
check($answer['status'] === 409 && $answer['body']['data']['code'] === 'method_unavailable', 'a total Mobile Money cannot take: the box says to choose another method');
check($GLOBALS['payments']->find('trx_test_p1')->state === PaymentRecord::DROPPED, 'and the payment is dropped');

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [prepared_answer('trx_test_p1', 'sess_p1'), prepared_answer('trx_p2', 'sess_p2')];
prepare_request($s, ['kind' => 'card']);
$s->settings()->setEnvironment('live');
$answer = prepare_request($s, ['kind' => 'card']);
check($answer['body']['data']['paymentId'] === 'trx_p2' && $GLOBALS['payments']->find('trx_test_p1')->state === PaymentRecord::DROPPED, 'the shop switched to Live: a new payment, with the live key; the Sandbox one is dropped');
check(str_starts_with($GLOBALS['http']->last()['headers']['Authorization'], 'sk.'), 'prepared with the live key');
check($GLOBALS['http']->json(1)['accept'] === ['types' => ['card']], 'a card payment restricted to cards');

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [prepared_answer('trx_test_p1', 'sess_p1'), prepared_answer('trx_test_p3', 'sess_p3')];
prepare_request($s, ['kind' => 'mobile_money']);
$answer = prepare_request($s, ['kind' => 'mobile_money', 'renew' => '1']);
check($answer['body']['data']['paymentId'] === 'trx_test_p3' && $GLOBALS['payments']->find('trx_test_p1')->state === PaymentRecord::DROPPED, 'renew (the form\'s session ended): a new payment');

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[422, ['message' => 'Amount too small', 'errors' => ['amount' => ['min']]]]];
$answer = prepare_request($s, ['kind' => 'mobile_money'], cart_view(501, 50));
check($answer['status'] === 409 && $answer['body']['data']['code'] === 'amount_out_of_range', 'an amount out of the currency\'s limits: said so');

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [network_down()];
$answer = prepare_request($s, ['kind' => 'mobile_money']);
check($answer['status'] === 503 && $answer['body']['data']['code'] === 'unavailable' && $answer['body']['data']['message'] === 'The payment service is temporarily unavailable. Please try again in a few moments.', 'Wajub out of reach: the box falls back, saying why');

$s = payment_services();
$token = $s->tokens()->checkout(501, 7);
check($s->prepareEndpoint()->answer(['kind' => 'mobile_money', 'token' => 'forged'], cart_view(), $token, '10.0.0.1')['status'] === 403, 'a wrong token: refused');
check($s->prepareEndpoint()->answer(['kind' => 'mobile_money', 'token' => $token], null, '', '10.0.0.1')['status'] === 403, 'no cart: refused');
check(prepare_request($s, ['kind' => 'other'])['status'] === 400, 'not a kind of the form: refused');
$s->settings()->setMethodEnabled('card', false);
check(prepare_request($s, ['kind' => 'card'])['status'] === 400, 'a kind the merchant turned off: refused');
check(count($GLOBALS['http']->sent) === 0, 'none of them reached Wajub');
$s->settings()->setSecretKey('sandbox', '');
check(prepare_request($s, ['kind' => 'mobile_money'])['body']['data']['code'] === 'not_configured', 'no key: the shop cannot take payments, said so');

$s = payment_services();
$GLOBALS['http']->routes['POST ' . API . '/payments'] = static function (): array {
    static $n = 0;
    ++$n;

    return prepared_answer('trx_test_l' . $n, 'sess_l' . $n);
};
$now = 1_800_000_000;
$statuses = [];
for ($i = 0; $i < 11; ++$i) {
    $statuses[] = prepare_request($s, ['kind' => 'mobile_money', 'renew' => '1'], null, '10.0.0.9', $now)['status'];
}
check(count(array_filter($statuses, static fn (int $st): bool => $st === 200)) === 10 && end($statuses) === 429, 'ten prepares a minute per visitor, the eleventh refused (429)');
check(prepare_request($s, ['kind' => 'mobile_money', 'renew' => '1'], null, '10.0.0.9', $now + 60)['status'] === 200, 'the next minute starts again');
$codes = [];
for ($i = 0; $i < 25; ++$i) {
    $codes[] = prepare_request($s, ['kind' => 'mobile_money', 'renew' => '1'], null, '10.1.0.' . $i, $now + 120)['status'];
}
check(count(array_filter($codes, static fn (int $st): bool => $st === 429)) === 0, 'others share the shop\'s 30 a minute');
for ($i = 0; $i < 6; ++$i) {
    $codes[] = prepare_request($s, ['kind' => 'mobile_money', 'renew' => '1'], null, '10.2.0.' . $i, $now + 120)['status'];
}
check(count(array_filter($codes, static fn (int $st): bool => $st === 429)) === 1, 'the 31st of the minute, from anyone, is refused');
check(PrepareEndpoint::limits(['ip' => 50, 'site' => '200']) === ['ip' => 50, 'site' => 200] && PrepareEndpoint::limits(['ip' => 0]) === ['ip' => 1, 'site' => 30], 'the limits a hook gives, at least one each');

// --- The order's payment (Starter) ---------------------------------------------------

/** A shop whose cart 501 has a prepared Mobile Money payment, then an order 9 placed from it. */
function placed_shop(): Services
{
    $s = payment_services();
    $GLOBALS['http']->routes['POST ' . API . '/payments'] = [prepared_answer('trx_test_f1', 'sess_f1')];
    prepare_request($s, ['kind' => 'mobile_money']);
    $GLOBALS['http']->reset();
    $GLOBALS['orders']->add(9, 15000.0, 'XAF', ['cart' => 501, 'reference' => 'XKBKNABJK']);

    return $s;
}

$s = placed_shop();
$pre = $s->starter()->preflight(cart_view(), 'mobile_money', 'trx_test_other');
check(($pre['data']['code'] ?? '') === 'refreshed' && $GLOBALS['payments']->find('trx_test_f1')->state === PaymentRecord::PREPARED, 'a form older than the cart\'s payment: refreshed, the cart\'s payment left for its own form');
$pre = $s->starter()->preflight(cart_view(), 'mobile_money', 'trx_test_f1');
check(isset($pre['slot']) && $pre['slot']->paymentId === 'trx_test_f1', 'the form\'s own payment is ready to be activated');
$pre = $s->starter()->preflight(cart_view(501, 15000, 'EUR'), 'mobile_money', 'trx_test_f1');
check(($pre['data']['code'] ?? '') === 'refreshed' && $GLOBALS['payments']->find('trx_test_f1')->state === PaymentRecord::DROPPED, 'the cart\'s currency changed: refreshed, the payment dropped');

$s = placed_shop();
$slot = $GLOBALS['payments']->find('trx_test_f1');
$slot->expiresAt = time() + 300;
$GLOBALS['payments']->save($slot);
$GLOBALS['http']->routes['PATCH ' . API . '/payments/trx_test_f1'] = [network_down()];
$pre = $s->starter()->preflight(cart_view(), 'mobile_money', 'trx_test_f1');
check(($pre['data']['code'] ?? '') === 'refreshed', 'too little life left, and Wajub cannot extend it: the form loads a new payment');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[200, ['activated' => true]]];
$order = $GLOBALS['orders']->find(9);
$slot = $GLOBALS['payments']->find('trx_test_f1');
$answer = $s->starter()->fields($order, cart_view(), 'mobile_money', $slot, 'fr');
check($answer['ok'] && $answer['data']['fields'] === '1' && $answer['data']['paymentId'] === 'trx_test_f1' && $answer['data']['sessionId'] === 'sess_f1', 'the form\'s payment, activated for the order');
check($answer['data']['hostedUrl'] === 'https://pay.wajub.com/trx_test_f1?locale=fr' && str_contains($answer['data']['statusUrl'], 'id_order=9') && str_contains($answer['data']['releaseUrl'], 'release'), 'with its hosted page in the payer\'s language, the order\'s status and release addresses');
$body = $GLOBALS['http']->json(0);
check($GLOBALS['http']->routes()[0] === 'POST ' . API . '/payments/trx_test_f1/activate', 'the same amount: activated without a change');
check($body['amount'] === 15000 && $body['currency'] === 'XAF' && $body['customer'] === ['email' => 'payer@example.cm', 'phone' => '+237677123456', 'name' => 'Amina Diallo'], 'the order\'s amount and the payer, their phone international');
check($body['reference'] === 'Order #XKBKNABJK' && $body['description'] === 'Order #XKBKNABJK', 'named after the order\'s reference');
check($body['callback'] === 'https://shop.test/module/wajub/return?id_order=9&token=' . $GLOBALS['storefront']->orderToken($order), 'the payer comes back to the order\'s return address, signed');
check($body['metadata']['order_id'] === '9' && $body['metadata']['cart_id'] === '501' && $body['metadata']['shop_id'] === '1' && $body['metadata']['source'] === 'prestashop', 'the order, cart and shop ids in its metadata');
$bound = $GLOBALS['payments']->find('trx_test_f1');
check($bound->idOrder === 9 && $bound->state === PaymentRecord::ACTIVE && $bound->activationHash !== '' && $bound->reference === 'Order #XKBKNABJK', 'the payment is the order\'s now, with the hash of what was sent');

$GLOBALS['http']->reset();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_f1'] = [[200, payment_data('trx_test_f1', 'pending', 15000, ['metadata' => ['order_id' => '9']])]];
$again = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check($again['ok'] && $again['data']['paymentId'] === 'trx_test_f1', 'the same order placed again (a double click, a lost answer): the same payment');
check($GLOBALS['http']->json(0) === $body, 'the very same activation body, which Wajub answers as done');
check($GLOBALS['http']->routes()[1] === 'GET ' . API . '/payments/trx_test_f1', 'then read, to know it is still this order\'s and open');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[409, ['message' => 'Activated with another body']]];
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_f1'] = [[200, payment_data('trx_test_f1', 'pending', 15000, ['metadata' => ['order_id' => '9']])]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check($answer['ok'], 'a refusal (409) of a payment Wajub holds open for this order: it pays the order');
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_f1'] = [[200, payment_data('trx_test_f1', 'pending', 15000, ['metadata' => ['order_id' => '77']])]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'refreshed' && $answer['data']['leave'] === true, 'one Wajub holds for another order: the order is given back, nothing charged');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[422, ['message' => 'Amount', 'errors' => ['amount' => ['mismatch']]]], [200, ['activated' => true]]];
$GLOBALS['http']->routes['PATCH ' . API . '/payments/trx_test_f1'] = [[200, ['id' => 'trx_test_f1']]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check($answer['ok'] && $GLOBALS['http']->routes() === ['POST ' . API . '/payments/trx_test_f1/activate', 'PATCH ' . API . '/payments/trx_test_f1', 'POST ' . API . '/payments/trx_test_f1/activate'], 'the payment\'s amount and the order\'s differ: brought level, activated once more');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[422, ['message' => 'No method', 'errors' => ['accept.types' => ['none']]]]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'method_unavailable' && $answer['data']['leave'] === true, 'Mobile Money cannot take the order: said so, the order to be given back');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [network_down()];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'start_failed' && $answer['data']['leave'] === false, 'Wajub out of reach: the payer may try again, the order is kept');
check($GLOBALS['payments']->find('trx_test_f1')->idOrder === 9, 'and the payment stays the order\'s, the same activation sent again next time');

$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[409, ['message' => 'Already activated']]];
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_f1'] = [[200, payment_data('trx_test_f1', 'succeeded', 15000, ['metadata' => ['order_id' => '9']])]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'paid' && str_contains($answer['data']['redirect'], 'order-confirmation'), 'paid already (approved, then placed again): the order is paid, the payer goes to its confirmation');
check($GLOBALS['orders']->state(9) === FakeShopOrders::PAID && count($GLOBALS['orders']->paymentsOf(9)) === 1, 'settled as its webhook would: paid, one payment');

// The hosted page: Other payment methods, and a form that could not load.
$s = payment_services();
$GLOBALS['orders']->add(12, 15000.0, 'XAF', ['reference' => 'QWERTYUIO']);
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['card', 'card'], ['paypal', 'paypal']])];
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[201, ['id' => 'trx_test_h1', 'reference' => 'Order #QWERTYUIO', 'authorization_url' => 'https://pay.wajub.com/trx_test_h1', 'status' => 'pending']]];
$answer = $s->starter()->hosted($GLOBALS['orders']->find(12), cart_view(512), 'other', 'fr');
check($answer['ok'] && $answer['data']['redirect'] === 'https://pay.wajub.com/trx_test_h1?locale=fr', 'Other payment methods: the payer goes to Wajub\'s page, in their language');
$created = $GLOBALS['http']->json(1);
check($created['accept'] === ['types' => ['paypal']], 'restricted to what Mobile Money and cards do not cover');
check($created['amount'] === 15000 && $created['reference'] === 'Order #QWERTYUIO' && str_contains($created['callback'], 'return?id_order=12') && $created['metadata']['mode'] === 'hosted', 'for the order\'s total, named after it, back to its return address');
$record = $GLOBALS['payments']->find('trx_test_h1');
check($record !== null && $record->idOrder === 12 && $record->mode === PaymentRecord::MODE_HOSTED && $record->method === 'other' && $record->state === PaymentRecord::ACTIVE, 'kept as the order\'s payment');

$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_h1'] = [[200, payment_data('trx_test_h1', 'pending', 15000, ['metadata' => ['order_id' => '12']])]];
$again = $s->starter()->hosted($GLOBALS['orders']->find(12), cart_view(512), 'other', 'fr');
check($again['ok'] && $again['data']['redirect'] === 'https://pay.wajub.com/trx_test_h1?locale=fr' && count(array_filter($GLOBALS['http']->routes(), static fn (string $r): bool => $r === 'POST ' . API . '/payments')) === 1, 'a second click: the payment the order holds on Wajub\'s page, nobody having paid it yet, not a second one');

$s = payment_services();
$GLOBALS['orders']->add(13, 15000.0);
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[201, ['id' => 'trx_test_h2', 'authorization_url' => 'https://pay.wajub.com/trx_test_h2', 'status' => 'pending']]];
$s->starter()->hosted($GLOBALS['orders']->find(13), cart_view(513), 'card', 'en');
check($GLOBALS['http']->json(0)['accept'] === ['types' => ['card']] && options_requests() === 0, 'a card form that could not load: Wajub\'s page, cards only');
check($s->starter()->referenceFor($GLOBALS['orders']->find(13)) === 'Order #REFXXXX13-2', 'the order\'s next payment: its reference numbered');

$s = payment_services();
$s->settings()->setMethodEnabled('mobile_money', false);
$s->settings()->setMethodEnabled('card', false);
$GLOBALS['orders']->add(14, 15000.0);
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[201, ['id' => 'trx_test_h3', 'authorization_url' => 'https://pay.wajub.com/trx_test_h3', 'status' => 'pending']]];
$s->starter()->hosted($GLOBALS['orders']->find(14), cart_view(514), 'other', 'en');
check(!isset($GLOBALS['http']->json(0)['accept']), 'the one option for everything: no restriction');

$s = payment_services();
$GLOBALS['orders']->add(15, 15000.0);
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['card', 'card']])];
$answer = $s->starter()->hosted($GLOBALS['orders']->find(15), cart_view(515), 'other', 'en');
check(!$answer['ok'] && $answer['data']['code'] === 'method_unavailable' && $answer['data']['leave'], 'nothing left for Other payment methods: refused, the order to be given back');

$s = payment_services();
$GLOBALS['orders']->add(16, 15000.0);
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[401, ['message' => 'Unauthenticated.']]];
$answer = $s->starter()->hosted($GLOBALS['orders']->find(16), cart_view(516), 'card', 'en');
check(!$answer['ok'] && $answer['data']['message'] === 'Online payment is temporarily unavailable in this shop. Please contact the shop.' && !str_contains($answer['data']['message'], 'Unauthenticated'), 'a key Wajub refuses: the payer reads the shop cannot take payments, never the API\'s words');

// --- One payment at a time per order (Starter, OpenPayments) -------------------------------

/**
 * Cart 501 placed as order 9 with the Mobile Money form (trx_test_m,
 * activated), while another tab of the checkout still holds the cart's
 * Card form (trx_test_c, prepared, never placed).
 */
function two_tabs_shop(): Services
{
    $s = payment_services();
    $GLOBALS['http']->routes['POST ' . API . '/payments'] = [prepared_answer('trx_test_m', 'sess_m'), prepared_answer('trx_test_c', 'sess_c')];
    prepare_request($s, ['kind' => 'mobile_money']);
    prepare_request($s, ['kind' => 'card'], null, '10.0.0.2');
    $GLOBALS['orders']->add(9, 15000.0, 'XAF', ['cart' => 501, 'reference' => 'XKBKNABJK']);
    $GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_m/activate'] = [[200, ['activated' => true]]];
    check($s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_m'), 'fr')['ok'], 'setup: order 9 placed with Mobile Money');
    $GLOBALS['http']->reset();
    $GLOBALS['http']->routes = ['POST ' . API . '/payments/trx_test_c/activate' => [[200, ['activated' => true]]]];

    return $s;
}

function sent(string $route): int
{
    return count(array_filter($GLOBALS['http']->routes(), static fn (string $r): bool => strtok($r, '?') === $route));
}

/** The order's payments still with Wajub. */
function open_payments(int $idOrder): array
{
    return array_values(array_map(static fn (PaymentRecord $r): string => $r->paymentId, array_filter($GLOBALS['payments']->forOrder($idOrder), static fn (PaymentRecord $r): bool => $r->state === PaymentRecord::ACTIVE)));
}

// Paid with Mobile Money, then "Place order" with the Card form in the other tab.
$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [[200, payment_data('trx_test_m', 'succeeded', 15000, ['metadata' => ['order_id' => '9']])]];
$s->settlement()->apply($GLOBALS['orders']->find(9), $GLOBALS['payments']->find('trx_test_m'), $s->settlement()->outcome($GLOBALS['payments']->find('trx_test_m'), true), 'now');
check($GLOBALS['orders']->find(9)->paid, 'setup: order 9 paid by Mobile Money');
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'card', $GLOBALS['payments']->find('trx_test_c'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'paid' && str_contains($answer['data']['redirect'], 'order-confirmation'), 'a paid order takes no second payment: the payer goes to its confirmation');
check(sent('POST ' . API . '/payments/trx_test_c/activate') === 0 && $GLOBALS['payments']->find('trx_test_c')->idOrder === 0, 'the Card payment is never activated');
$answer = $s->starter()->hosted($GLOBALS['orders']->find(9), cart_view(), 'other', 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'paid' && sent('POST ' . API . '/payments') === 0, 'nor any payment on Wajub\'s page');

// Mobile Money being approved on the payer's phone.
$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [[200, payment_data('trx_test_m', 'processing', 15000, ['metadata' => ['order_id' => '9']])]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'card', $GLOBALS['payments']->find('trx_test_c'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'pending' && str_contains($answer['data']['redirect'], 'order-confirmation'), 'Mobile Money being approved: no Card payment, the confirmation page follows the order\'s');
check(sent('GET ' . API . '/payments/trx_test_m') === 1 && sent('DELETE ' . API . '/payments/trx_test_m') === 0 && sent('POST ' . API . '/payments/trx_test_c/activate') === 0, 'read, never cancelled, and the Card payment never activated');
check(open_payments(9) === ['trx_test_m'], 'one payment open for the order');
$answer = $s->starter()->hosted($GLOBALS['orders']->find(9), cart_view(), 'other', 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'pending' && sent('POST ' . API . '/payments') === 0, 'nor Other payment methods: no payment on Wajub\'s page beside it');

// Mobile Money not approved yet: cancelled first, then the rest on Wajub's page.
$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [[200, payment_data('trx_test_m', 'pending', 15000, ['metadata' => ['order_id' => '9']])]];
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_m'] = [[200, ['id' => 'trx_test_m', 'status' => 'cancelled']]];
$GLOBALS['http']->routes['POST ' . API . '/payments'] = [[201, ['id' => 'trx_test_h9', 'authorization_url' => 'https://pay.wajub.com/trx_test_h9', 'status' => 'pending']]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'card', $GLOBALS['payments']->find('trx_test_c'), 'fr');
$routes = array_map(static fn (string $r): string => (string) strtok($r, '?'), $GLOBALS['http']->routes());
check($answer['ok'] && ($answer['data']['redirect'] ?? '') === 'https://pay.wajub.com/trx_test_h9?locale=fr', 'Mobile Money not approved yet: the card payment on Wajub\'s page');
check(in_array('POST ' . API . '/payments', $routes, true) && array_search('DELETE ' . API . '/payments/trx_test_m', $routes, true) < array_search('POST ' . API . '/payments', $routes, true), 'the Mobile Money payment cancelled with Wajub before the new one starts');
$created = in_array('POST ' . API . '/payments', $routes, true) ? $GLOBALS['http']->json((int) array_search('POST ' . API . '/payments', $routes, true)) : [];
check(($created['accept'] ?? null) === ['types' => ['card']], 'the new one in the kind the payer chose');
check(open_payments(9) === ['trx_test_h9'] && sent('POST ' . API . '/payments/trx_test_c/activate') === 0, 'one payment open for the order, the last one started, which the pages follow');
check($s->payments()->current(9)->paymentId === 'trx_test_h9' && $GLOBALS['orders']->noted(9, 'the earlier Wajub payment trx_test_m, not completed, was cancelled'), 'with a note for the merchant');

$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [[200, payment_data('trx_test_m', 'pending', 15000, ['metadata' => ['order_id' => '9']])]];
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_m'] = [network_down()];
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['paypal', 'paypal']])];
$answer = $s->starter()->hosted($GLOBALS['orders']->find(9), cart_view(), 'other', 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'busy' && empty($answer['data']['leave']) && sent('POST ' . API . '/payments') === 0, 'the payment open cannot be cancelled (Wajub out of reach): nothing starts, the payer tries again');

$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [network_down()];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'card', $GLOBALS['payments']->find('trx_test_c'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'busy' && sent('POST ' . API . '/payments/trx_test_c/activate') === 0, 'nor when it cannot be read');

$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/options'] = [options_answer([['cm.mtn', 'mobile_money'], ['card', 'card']])];
$answer = $s->starter()->hosted($GLOBALS['orders']->find(9), cart_view(), 'other', 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'method_unavailable' && $answer['data']['leave'] === false && sent('DELETE ' . API . '/payments/trx_test_m') === 0, 'Other payment methods offering nothing more, from a page drawn before: refused, the order and its payment kept');

// Paid meanwhile: counted, the order paid, nothing new.
$s = two_tabs_shop();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m'] = [[200, payment_data('trx_test_m', 'succeeded', 15000, ['metadata' => ['order_id' => '9']])]];
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_m/refunds'] = [[200, ['items' => []]]];
$answer = $s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'card', $GLOBALS['payments']->find('trx_test_c'), 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'paid' && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID && count($GLOBALS['orders']->paymentsOf(9)) === 1, 'the Mobile Money payment went through: counted, the order paid, no Card payment');

// The order and its payment read again under the lock: the webhook counted
// the payment while this request waited for it.
$s = placed_shop();
$GLOBALS['http']->routes['POST ' . API . '/payments/trx_test_f1/activate'] = [[200, ['activated' => true]]];
$s->starter()->fields($GLOBALS['orders']->find(9), cart_view(), 'mobile_money', $GLOBALS['payments']->find('trx_test_f1'), 'fr');
$staleOrder = $GLOBALS['orders']->find(9);
$staleSlot = $GLOBALS['payments']->find('trx_test_f1');
$succeeded = payment_data('trx_test_f1', 'succeeded', 15000, ['metadata' => ['order_id' => '9']]);
$s->settlement()->apply($GLOBALS['orders']->find(9), $GLOBALS['payments']->find('trx_test_f1'), ['outcome' => 'succeeded', 'status' => 'succeeded', 'transaction' => $succeeded], 'now');
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_f1'] = [[200, $succeeded]];
$answer = $s->starter()->fields($staleOrder, cart_view(), 'mobile_money', $staleSlot, 'fr');
check(!$answer['ok'] && $answer['data']['code'] === 'paid', 'placed again as the webhook paid it: the payer goes to the confirmation');
check(count($GLOBALS['orders']->paymentsOf(9)) === 1 && $GLOBALS['payments']->find('trx_test_f1')->isCounted() && $GLOBALS['payments']->find('trx_test_f1')->state === PaymentRecord::FINAL, 'counted once, its record left as the webhook wrote it');

// --- Helpers ---------------------------------------------------------------------------------

check(Phone::international('677 12 34 56', 237) === '+237677123456', 'a national number takes its address\'s country code');
check(Phone::international('+237 677-12-34-56', 33) === '+237677123456', 'an international number stays as it is');
check(Phone::international('00237677123456', 237) === '+237677123456', '00 is +');
check(Phone::international('237677123456', 237) === '+237677123456', 'a number with its country code already');
check(Phone::international('06 12 34 56 78', 33) === '+33612345678', 'a trunk 0 goes');
check(Phone::international('', 237) === '' && Phone::international('677123456', 0) === '677123456', 'nothing, or no country: nothing guessed');

$tokens = new Tokens('secret');
check($tokens->checkout(1, 2) !== $tokens->checkout(1, 3) && $tokens->order(9, 'a') !== $tokens->order(9, 'b') && Tokens::matches($tokens->order(9, 'a'), $tokens->order(9, 'a')) && !Tokens::matches($tokens->order(9, 'a'), ''), 'tokens for one cart and customer, one order and its key');
check((new Tokens('other'))->order(9, 'a') !== $tokens->order(9, 'a'), 'signed with the shop\'s secret');

check(PreparedPayments::callback('https://shop.cm/return', true) === 'https://shop.cm/return', 'Wajub returns payers to https');
check(PreparedPayments::callback('http://localhost:8082/return', false) === 'http://localhost:8082/return' && PreparedPayments::callback('http://localhost:8082/return', true) === '', 'to http://localhost in Sandbox only');
check(PreparedPayments::callback('http://shop.cm/return', false) === '', 'never to plain http elsewhere: no return rather than a refused payment');

check(CheckoutLocale::of('fr-FR') === 'fr' && CheckoutLocale::of('de') === '' && CheckoutLocale::hostedPageUrl('https://pay.wajub.com/x?a=1', 'en') === 'https://pay.wajub.com/x?a=1&locale=en', 'the checkout\'s language, when Wajub has it');

// The logos as one picture: an SVG file, each logo's drawing inside it
// (an SVG an <img> shows loads nothing else), written once.
$methodsDir = dirname(__DIR__) . '/views/img/methods';
$svg = LogoStrip::svg(['mtn', 'orange', 'airtel'], $methodsDir);
check(str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="82" height="24" viewBox="0 0 82 24"><title>MTN Mobile Money, Orange Money, Airtel Money</title>'), 'the logos as one picture, as wide as they are, named: ' . substr($svg, 0, 160));
check(str_contains($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72" x="0" y="0" width="24" height="24"><image ') && str_contains($svg, 'viewBox="0.07 0.07 53.93 53.93" x="28" y="0" width="24" height="24">'), 'each 24px high, 4px apart, its own drawing in a nested picture');
check(str_contains($svg, '<rect x="56" y="0" width="26" height="24" rx="3" fill="#fff"/>') && str_contains($svg, 'x="60" y="3" width="18" height="18"'), 'Airtel on its white badge');
check(substr_count($svg, '<svg ') === 4 && !str_contains($svg, 'data:image/svg+xml') && !str_contains($svg, 'href="http') && !str_contains($svg, 'href="/'), 'nothing it would have to load: no address, no picture of a picture');
foreach (array_merge(...array_map([Methods::class, 'logoChoices'], Methods::KINDS)) as $key) {
    check(str_ends_with(Methods::logoFile($key), '.svg') && LogoStrip::svg([$key], $methodsDir) !== '', 'every logo is an SVG the picture can hold: ' . $key);
}
check(LogoStrip::svg([], $methodsDir) === '' && LogoStrip::svg(['nope'], $methodsDir) === '', 'no logo: no picture');

$into = sys_get_temp_dir() . '/wajub-strip-' . getmypid();
@mkdir($into);
$file = LogoStrip::file(['mtn', 'orange'], $methodsDir, $into);
check(preg_match('#/wajub-logos-[0-9a-f]{20}\.svg$#', $file) === 1 && is_file($file) && (string) file_get_contents($file) === LogoStrip::svg(['mtn', 'orange'], $methodsDir), 'written once in the folder of the shop\'s generated pictures, named by its content: ' . $file);
check(basename($file) === LogoStrip::PREFIX . substr(hash('sha256', LogoStrip::svg(['mtn', 'orange'], $methodsDir)), 0, 20) . '.svg', 'named by its content: the next page, and every shop of the server, finds it there');
check(LogoStrip::file(['mtn', 'orange'], $methodsDir, $into) === $file && count(glob($into . '/*')) === 1, 'the same logos: the same file, nothing written again, nothing left half written');
check(LogoStrip::file(['orange', 'mtn'], $methodsDir, $into) !== $file && count(glob($into . '/wajub-logos-*.svg')) === 2, 'other logos, or another order: another file');
check(LogoStrip::file(['mtn'], $methodsDir, $into . '/missing') === '' && LogoStrip::file([], $methodsDir, $into) === '', 'a folder that takes no file, or no logo: no file (the shop shows the first logo\'s own file)');
LogoStrip::forget($into);
check(glob($into . '/*') === [], 'uninstalling deletes them');
@rmdir($into);

// What a script's answer says, for the page it takes the payer to.
$kept = new KeptNotices($GLOBALS['store'] = new MemoryStateStore());
$id = $kept->keep(['error' => ['Your cart is back.', '', ['not text']], 'info' => [], 'nonsense' => ['x']], 'https://boutique.cm/order');
check(preg_match('/^[a-f0-9]{32}$/', $id) === 1 && $GLOBALS['store']->ttl('notice:' . $id) === KeptNotices::TTL, 'kept under an id nobody guesses, ten minutes: ' . $id);
check($kept->take($id) === ['to' => 'https://boutique.cm/order', 'messages' => ['error' => ['Your cart is back.']]], 'read with where the payer goes on to, text only, by PrestaShop\'s kinds');
check($kept->take($id) === null, 'read once');
check($kept->keep(['error' => []], 'https://boutique.cm/order') === '' && $kept->keep(['error' => ['x']], '') === '', 'nothing to say, or nowhere to go: nothing kept');
check($kept->take('../notice') === null && $kept->take('') === null && $kept->take(str_repeat('a', 32)) === null, 'an id that is none, or unknown: nothing');

check(Methods::channelLogos(['paypal', 'apple_pay', 'cm.mtn', 'paypal']) === ['paypal', 'applepay'], 'the logos of an account\'s other channels, once each');

done('checkout');

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
 * The API client (Api\Client): what each call sends, with which headers,
 * timeouts and idempotency key, what it does with the API's answers and
 * errors (retries included), and the key it never sends in place of another.
 *
 *     php tests/run-client-tests.php
 */

require_once __DIR__ . '/bootstrap.php';

use Wajub\PrestaShop\Api\ApiConnectionException;
use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\AuthenticationException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Api\InvalidRequestException;
use Wajub\PrestaShop\Api\NotFoundException;
use Wajub\PrestaShop\Api\PaymentLookupException;
use Wajub\PrestaShop\Api\PermissionException;
use Wajub\PrestaShop\Support\Amount;

const API = 'https://api.wajub.com';

function client(array $routes, string $key = 'sk_test.keyfortests', bool $sandbox = true): Client
{
    $GLOBALS['http'] = new FakeTransport($routes);
    $GLOBALS['paused'] = [];
    $GLOBALS['logger'] = new TestLogger();

    return new Client($key, $sandbox, $GLOBALS['http'], Client::userAgent('1.0.0', '8.2.8'), $GLOBALS['logger'], static function (int $ms): void {
        $GLOBALS['paused'][] = $ms;
    });
}

function sent(): array
{
    return $GLOBALS['http']->sent;
}

// === Headers ===================================================================

$c = client(['GET ' . API . '/' => [[200, ['business' => 'Boutique', 'env' => 'sandbox']]]]);
$hello = $c->checkKey();
$request = sent()[0];
check($request['method'] === 'GET' && $request['url'] === API . '/', 'checkKey calls GET /');
check($request['headers']['Authorization'] === 'sk_test.keyfortests', 'the key is sent as it is, as the API reads it');
check(preg_match('#^Wajub-PrestaShop/1\.0\.0 PrestaShop/8\.2\.8 PHP/\d+\.\d+#', $request['headers']['User-Agent']) === 1, 'User-Agent names the module, PrestaShop and PHP: ' . $request['headers']['User-Agent']);
check($request['headers']['Accept'] === 'application/json' && $request['headers']['Content-Type'] === 'application/json', 'JSON in and out');
check(!isset($request['headers']['Idempotency-Key']), 'a GET carries no idempotency key');
check($request['timeout'] === 10, 'checkKey keeps an admin waiting 10 seconds at most');
check($hello === ['business' => 'Boutique', 'environment' => 'sandbox'], 'checkKey reads the business and the environment');
check(client(['GET ' . API . '/' => [[200, ['business' => 'B', 'env' => 'production']]]])->checkKey()['environment'] === 'live', 'the API\'s "production" is live');

$c = client([], ' Bearer sk_test.pasted ');
thrown(static fn () => $c->checkKey());
check((sent()[0]['headers']['Authorization'] ?? '') === 'sk_test.pasted', '"Bearer " copied from a curl example, and spaces, are taken off the key');

// === No key, no request ========================================================

$c = client([], '', true);
check(!$c->isConfigured() && $c->isSandbox(), 'a client without a key is not configured');
$e = thrown(static fn () => $c->checkKey());
check($e instanceof ApiException && $e->errorCode === 'not_configured' && sent() === [], 'and sends nothing: never another environment\'s key in its place');

// === Errors ====================================================================

$cases = [
    [401, AuthenticationException::class],
    [403, PermissionException::class],
    [404, NotFoundException::class],
    [422, InvalidRequestException::class],
    [400, InvalidRequestException::class],
    [409, ApiException::class],
];
foreach ($cases as [$status, $class]) {
    $e = thrown(static fn () => client(['GET ' . API . '/' => [[$status, ['message' => 'Nope', 'error_code' => 'some_code']]]])->checkKey());
    check($e instanceof $class && $e->httpStatus === $status && $e->errorCode === 'some_code', "a {$status} is a " . (new ReflectionClass($class))->getShortName() . ' with its status and code');
}
$e = thrown(static fn () => client(['GET ' . API . '/' => [[422, ['message' => 'Bad', 'errors' => ['url' => ['Taken']]]]]])->checkKey());
check($e instanceof InvalidRequestException && ($e->body['errors']['url'][0] ?? '') === 'Taken', 'the API\'s answer is kept on the exception: its errors by field');
$e = thrown(static fn () => client(['GET ' . API . '/' => [[302, '', ['location' => 'https://elsewhere.example/']]]])->checkKey());
check($e instanceof ApiException && $e->httpStatus === 302 && count(sent()) === 1, 'a redirect is an error, never followed: the key never leaves for another address');
$e = thrown(static fn () => client(['GET ' . API . '/' => [[500, '<html>Bad gateway</html>']]])->checkKey());
check($e instanceof ApiException && $e->httpStatus === 500 && str_contains($e->getMessage(), 'Bad gateway'), 'an answer that is not JSON keeps its words for the log');

// === Retries ===================================================================

$c = client(['POST ' . API . '/refunds' => [[503, ['message' => 'Down']], [201, ['id' => 'rfd_1']]]]);
$refund = $c->createRefund('trx_1', 500.0, null, 'XAF');
check(count(sent()) === 2 && ($refund['id'] ?? '') === 'rfd_1', 'a server error is sent again once');
check(sent()[0]['headers']['Idempotency-Key'] === sent()[1]['headers']['Idempotency-Key'], 'under the same idempotency key: the API creates nothing twice');
check(str_starts_with(sent()[0]['headers']['Idempotency-Key'], 'wajub-prestashop-'), 'the key says it is the module\'s');
check($GLOBALS['paused'] === [100], 'after a short pause: ' . json_encode($GLOBALS['paused']));

$c = client(['POST ' . API . '/refunds' => [[429, ['message' => 'Slow down'], ['retry-after' => '1']], [201, ['id' => 'rfd_2']]]]);
$c->createRefund('trx_1', null, null, 'XAF');
check(count(sent()) === 2 && $GLOBALS['paused'] === [1000], 'a rate limit is waited out (Retry-After) and sent again: ' . json_encode($GLOBALS['paused']));

$c = client(['POST ' . API . '/refunds' => [network_down(), [201, ['id' => 'rfd_3']]]]);
$c->createRefund('trx_1', null, null, 'XAF');
check(count(sent()) === 2, 'no answer is sent again once');

$c = client(['POST ' . API . '/refunds' => [[503, ['message' => 'Down']]]]);
$e = thrown(static fn () => $c->createRefund('trx_1', null, null, 'XAF'));
check($e instanceof ApiException && $e->httpStatus === 503 && count(sent()) === 2, 'twice at most: the second failure is the answer');
$c = client(['POST ' . API . '/refunds' => [network_down()]]);
$e = thrown(static fn () => $c->createRefund('trx_1', null, null, 'XAF'));
check($e instanceof ApiConnectionException && $e->httpStatus === 0 && Client::unanswered($e), 'no answer twice is an ApiConnectionException, which Wajub did not answer');
check(Client::unanswered(new ApiException('x', 'y', 429)) && Client::unanswered(new ApiException('x', 'y', 502)) && !Client::unanswered(new ApiException('x', 'y', 404)), 'a rate limit and a server error are not Wajub\'s answer; a 404 is');

// === Payment options ===========================================================

$c = client(['GET ' . API . '/payments/options' => [[200, ['channels' => [['type' => 'card'], 'junk']]]]]);
$options = $c->paymentOptions('xaf', 5000.4, 'cm', ['types' => ['Card']]);
$request = sent()[0];
parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
check($request['timeout'] === 3, 'paymentOptions waits 3 seconds at most: the checkout reads it');
check($query['currency'] === 'XAF' && $query['amount'] === '5000' && $query['country'] === 'CM', 'with the currency, the amount as the currency counts it, and the country: ' . json_encode($query));
check(($query['accept']['types'] ?? null) === ['card'], 'and the restriction, folded as the API folds it');
check($options === [['type' => 'card']], 'it returns the channels, nothing else');
$c = client(['GET ' . API . '/payments/options' => [[503, ['message' => 'Down']]]]);
$e = thrown(static fn () => $c->paymentOptions('XAF', 1000.0));
check($e instanceof ApiException && count(sent()) === 1, 'and is never sent again: a page that cannot learn it does without');

// === Hosted payments =========================================================

$c = client(['POST ' . API . '/payments' => [[201, ['transaction' => ['id' => 'trx_test_1', 'reference' => 'ps_42_1', 'status' => 'pending'], 'authorization_url' => 'https://pay.wajub.com/trx_test_1']]]]);
$response = $c->createPayment([
    'amount' => '10000.4',
    'currency' => 'xaf',
    'reference' => 'ps_42_1',
    'description' => '<b>Order</b> #42',
    'callback' => 'https://boutique.cm/module/wajub/return?order=42',
    'return_url' => 'javascript:alert(1)',
    'customer' => ['name' => 'Awa', 'email' => 'awa@example.cm', 'phone' => '+237 6 99-00-00-00'],
    'metadata' => ['source' => 'prestashop', 'id_order' => 42, 'paid' => true, 'nested' => ['no']],
    'accept' => ['types' => ['paypal', 'Apple_Pay']],
]);
$body = $GLOBALS['http']->json(0);
check(sent()[0]['timeout'] === 30 && isset(sent()[0]['headers']['Idempotency-Key']), 'createPayment: 30 seconds, under an idempotency key');
check($body['amount'] === 10000 && $body['currency'] === 'XAF', 'createPayment: the amount as the currency counts it, the currency in upper case');
check($body['description'] === 'Order #42' && $body['reference'] === 'ps_42_1', 'createPayment: plain text only');
check($body['customer'] === ['email' => 'awa@example.cm', 'phone' => '+237699000000', 'name' => 'Awa'], 'createPayment: the customer, the phone as digits and its +: ' . json_encode($body['customer']));
check($body['callback'] === 'https://boutique.cm/module/wajub/return?order=42' && !isset($body['return_url']), 'createPayment: an address that is not http(s) is not sent');
check($body['metadata'] === ['id_order' => '42', 'paid' => '1', 'source' => 'prestashop'], 'createPayment: metadata as text, by key, without what is not text: ' . json_encode($body['metadata']));
check($body['accept'] === ['types' => ['paypal', 'apple_pay']], 'createPayment: the restriction as the API takes it');
$parsed = Client::parsePaymentResponse($response);
check($parsed['id'] === 'trx_test_1' && $parsed['reference'] === 'ps_42_1' && $parsed['authorization_url'] === 'https://pay.wajub.com/trx_test_1', 'parsePaymentResponse reads the id, the reference and the payment page');

$c = client(['POST ' . API . '/payments' => [[201, ['id' => 'trx_2']]]]);
$c->createPayment(['amount' => 100, 'currency' => 'EUR', 'reference' => 'r', 'customer' => ['email' => 'a@b.co'], 'accept' => ['typo' => ['card']]]);
check(!isset($GLOBALS['http']->json(0)['accept']) && $GLOBALS['logger']->has('Payment restriction left out: it is not one the API takes'), 'createPayment: a restriction the API would refuse is left out, and logged');
$e = thrown(static fn () => client([])->createPayment(['amount' => 100, 'currency' => 'EUR', 'reference' => 'r', 'customer' => ['name' => 'No contact']]));
check($e instanceof InvalidArgumentException && sent() === [], 'createPayment: a customer with neither email nor phone is not sent');
$e = thrown(static fn () => client([])->createPayment(['amount' => 0.001, 'currency' => 'EUR', 'reference' => 'r', 'customer' => ['email' => 'a@b.co']]));
check($e instanceof InvalidArgumentException, 'createPayment: nothing to pay is refused before asking');

foreach ([
    'javascript:alert(1)' => 'https://pay.wajub.com/trx_page',
    'http://pay.wajub.com/x' => 'https://pay.wajub.com/trx_page',
    'https://pay.wajub.com@evil.example/' => 'https://pay.wajub.com/trx_page',
    '/relative' => 'https://pay.wajub.com/trx_page',
    'https://pay.wajub.com/ok' => 'https://pay.wajub.com/ok',
] as $given => $expected) {
    $page = Client::parsePaymentResponse(['id' => 'trx_page', 'authorization_url' => $given])['authorization_url'];
    check($page === $expected, "parsePaymentResponse follows {$given} as {$expected}, not {$page}");
}

// === Prepared payments =========================================================

$c = client(['POST ' . API . '/payments' => [[201, ['id' => 'trx_test_p', 'reference' => 'ps_cart_7', 'status' => 'created', 'authorization_token' => 'sess_1']]]]);
$before = time();
$prepared = $c->preparePayment(['amount' => 2500, 'currency' => 'XAF', 'reference' => 'ps_cart_7', 'accept' => ['types' => ['mobile_money', 'mobile']], 'metadata' => ['id_cart' => 7]]);
$body = $GLOBALS['http']->json(0);
check(sent()[0]['timeout'] === 15, 'preparePayment: a payer waits 15 seconds at most');
check($body['deferred'] === true && $body['expires'] === ['in' => 120] && $body['accept'] === ['types' => ['mobile_money', 'mobile']], 'preparePayment: deferred, for the length of a checkout, restricted to its kind');
check(!isset($body['customer']) && !isset($body['callback']), 'preparePayment: no customer and no callback: the order is not known yet');
check($prepared['id'] === 'trx_test_p' && $prepared['session_id'] === 'sess_1' && $prepared['expires_at'] >= $before + 7200 && $prepared['expires_at'] <= time() + 7200, 'preparePayment: the payment, its session, and when it expires');
$e = thrown(static fn () => client([])->preparePayment(['amount' => 1, 'currency' => 'XAF', 'reference' => 'r', 'accept' => ['types' => []]]));
check($e instanceof InvalidArgumentException && sent() === [], 'preparePayment: a restriction that restricts nothing is never sent: the form of one kind must not take any');
$e = thrown(static fn () => client([])->preparePayment(['amount' => 1, 'currency' => 'XAF', 'reference' => 'r', 'expires_in' => 2]));
check($e instanceof InvalidArgumentException, 'preparePayment: a lifetime the API refuses is refused before asking');

$c = client(['PATCH ' . API . '/payments/trx_p' => [[200, ['transaction' => ['id' => 'trx_p', 'amount' => 3000]]]]]);
$updated = $c->updatePreparedPayment('trx_p', 3000.2, 'xaf', 60);
check(sent()[0]['method'] === 'PATCH' && $GLOBALS['http']->json(0) === ['amount' => 3000, 'currency' => 'XAF', 'expires' => ['in' => 60]] && $updated['amount'] === 3000, 'updatePreparedPayment: PATCH with the new amount and lifetime');

$params = ['currency' => 'xaf', 'amount' => 2500.4, 'customer' => ['name' => 'Awa', 'phone' => '+237 699', 'email' => ''], 'metadata' => ['b' => 2, 'a' => 1], 'reference' => 'ps_order_42', 'description' => str_repeat('d', 600)];
$body = Client::activationBody($params);
check(array_keys($body) === ['amount', 'currency', 'customer', 'metadata', 'reference', 'description'], 'activation: the body is built the same way every time: ' . implode(',', array_keys($body)));
check($body['customer'] === ['phone' => '+237699', 'name' => 'Awa'] && $body['metadata'] === ['a' => '1', 'b' => '2'] && mb_strlen($body['description']) === 500, 'activation: empty fields left out, metadata by key, the description cut to 500');
check(json_encode(Client::activationBody(array_reverse($params, true))) === json_encode($body), 'activation: the same order sends the same body, whatever order its fields came in');
$e = thrown(static fn () => Client::activationBody(['currency' => 'XAF', 'amount' => 1, 'customer' => ['email' => 'a@b.co'], 'reference' => str_repeat('r', 129)]));
check($e instanceof InvalidArgumentException, 'activation: a reference longer than the API takes is not sent');

$c = client(['POST ' . API . '/payments/trx_p/activate' => [[500, ['message' => 'Oops']], [200, ['activated' => true]]]]);
$c->activatePayment('trx_p', ['amount' => 2500, 'currency' => 'XAF', 'customer' => ['email' => 'a@b.co']]);
check(count(sent()) === 2 && sent()[0]['body'] === sent()[1]['body'], 'activation: sent again after a server error, with the same body, which the API answers as activated');
$e = thrown(static fn () => client(['POST ' . API . '/payments/trx_p/activate' => [[200, ['ok' => 1]]]])->activatePayment('trx_p', ['amount' => 1, 'currency' => 'XAF', 'customer' => ['email' => 'a@b.co']]));
check($e instanceof ApiException && $e->errorCode === 'activation_unconfirmed', 'activation: an answer that does not say it is activated is not taken as one');

// === Reads =====================================================================

$c = client(['GET ' . API . '/payments/trx_ok' => [[200, ['transaction' => ['id' => 'trx_ok', 'status' => 'SUCCEEDED']]]]]);
check($c->paymentOutcome('trx_ok')['outcome'] === Client::OUTCOME_SUCCEEDED, 'paymentOutcome: succeeded');
$c = client(['GET ' . API . '/payments/trx_ko' => [[200, ['status' => 'expired']]]]);
check($c->paymentOutcome('trx_ko')['outcome'] === Client::OUTCOME_FAILED, 'paymentOutcome: expired is a failure');
$c = client(['GET ' . API . '/payments/trx_x' => [network_down()]]);
$outcome = $c->paymentOutcome('trx_x');
check($outcome['outcome'] === Client::OUTCOME_PENDING && ($outcome['unreachable'] ?? false) === true, 'paymentOutcome: a read Wajub did not answer is pending, and says so');
$c = client(['GET ' . API . '/payments/trx_404' => [[404, ['message' => 'Not found']]]]);
$outcome = $c->paymentOutcome('trx_404');
check($outcome['outcome'] === Client::OUTCOME_PENDING && !isset($outcome['unreachable']), 'paymentOutcome: a 404 is Wajub\'s answer, pending, and never a failure');
check(client([])->paymentOutcome('')['outcome'] === Client::OUTCOME_PENDING && sent() === [], 'paymentOutcome: no id, no request');
check(client([])->paymentOutcome('../keys/current')['outcome'] === Client::OUTCOME_PENDING && sent() === [], 'paymentOutcome: an id that is not one is never put in a path');

$c = client(['GET ' . API . '/payments' => [[200, ['items' => [['id' => 'trx_found']]]]]]);
check($c->paymentIdByReference('ps_42_1') === 'trx_found', 'paymentIdByReference: the one payment carrying the reference');
parse_str((string) parse_url(sent()[0]['url'], PHP_URL_QUERY), $query);
check($query === ['reference' => 'ps_42_1', 'per_page' => '2'], 'paymentIdByReference: two asked for, enough to tell one from several');
$e = thrown(static fn () => client(['GET ' . API . '/payments' => [[200, ['items' => []]]]])->paymentIdByReference('ps_none'));
check($e instanceof PaymentLookupException && $e->reason === PaymentLookupException::NONE && $e->reference === 'ps_none', 'paymentIdByReference: none is said');
$e = thrown(static fn () => client(['GET ' . API . '/payments' => [[200, ['data' => [['id' => 'trx_a'], ['id' => 'trx_b']]]]]])->paymentIdByReference('ps_dup'));
check($e instanceof PaymentLookupException && $e->reason === PaymentLookupException::SEVERAL, 'paymentIdByReference: several are said');
check(client([])->paymentIdByReference('trx_already') === 'trx_already' && sent() === [], 'paymentIdByReference: an id is an id');

// === Refunds and other calls ===================================================

$c = client(['POST ' . API . '/refunds' => [[201, ['id' => 'rfd_1']]]]);
$c->createRefund('trx_1', 1500.5, 'Damaged parcel', 'XAF', ['id_order' => '42']);
check($GLOBALS['http']->json(0) === ['payment' => 'trx_1', 'amount' => 1501, 'reason' => 'requested_by_customer', 'metadata' => ['id_order' => '42', 'note' => 'Damaged parcel']], 'createRefund: the merchant\'s words travel as metadata, the reason is one the API takes: ' . json_encode($GLOBALS['http']->json(0)));
check(Client::refundReason('Duplicate ') === 'duplicate' && Client::refundReason('whatever') === 'requested_by_customer', 'refundReason: one of the API\'s, else requested_by_customer');
$c = client(['GET ' . API . '/payments/trx_1/refunds' => [[200, ['items' => [['id' => 'rfd_1'], 'x']]]]]);
check($c->paymentRefunds('trx_1') === [['id' => 'rfd_1']], 'paymentRefunds: the refunds of a payment');
$c = client(['POST ' . API . '/payments/trx_1/close' => [[200, ['status' => 'succeeded']]]]);
$c->closePayment('trx_1', 'anything');
check($GLOBALS['http']->json(0) === ['outcome' => 'keep'], 'closePayment: keep, unless refund is asked for');
$c = client(['DELETE ' . API . '/payments/trx_1' => [[200, ['status' => 'cancelled']]]]);
$c->cancelPayment('trx_1');
check(sent()[0]['method'] === 'DELETE' && sent()[0]['body'] === null && !isset(sent()[0]['headers']['Idempotency-Key']), 'cancelPayment: DELETE, no body, no idempotency key');
$c = client(['DELETE ' . API . '/keys/current' => [[204, '']]]);
$c->revokeCurrentKey();
check(sent()[0]['url'] === API . '/keys/current' && sent()[0]['timeout'] === 10, 'revokeCurrentKey: DELETE /keys/current, 10 seconds');

$c = client(['GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_1', 'url' => 'https://boutique.cm/module/wajub/webhook']]]]]]);
$list = $c->listWebhookEndpoints('https://boutique.cm/module/wajub/webhook');
parse_str((string) parse_url(sent()[0]['url'], PHP_URL_QUERY), $query);
check($query === ['per_page' => '100', 'search' => 'https://boutique.cm/module/wajub/webhook'] && $list[0]['id'] === 'we_1', 'listWebhookEndpoints: searched by the address, a page of 100');
$c = client(['POST ' . API . '/webhooks' => [[201, ['endpoint' => ['id' => 'we_2', 'secret' => 'whsec_test_abc']]]]]);
$endpoint = $c->createWebhookEndpoint('https://boutique.cm/module/wajub/webhook', ['payment.succeeded'], 'PrestaShop: Boutique');
check($GLOBALS['http']->json(0) === ['url' => 'https://boutique.cm/module/wajub/webhook', 'events' => ['payment.succeeded'], 'description' => 'PrestaShop: Boutique'] && $endpoint['secret'] === 'whsec_test_abc', 'createWebhookEndpoint: the address, the events, a description; the endpoint with its secret');
$c = client(['PUT ' . API . '/webhooks/we_2' => [[200, ['id' => 'we_2']]]]);
$c->updateWebhookEndpoint('we_2', ['events' => ['payment.succeeded'], 'active' => true]);
check(sent()[0]['method'] === 'PUT' && $GLOBALS['http']->json(0) === ['events' => ['payment.succeeded'], 'active' => true], 'updateWebhookEndpoint: PUT with what changes');
$c = client(['POST ' . API . '/webhooks/we_2/rotate-secret' => [[200, ['data' => ['id' => 'we_2', 'secret' => 'whsec_test_new']]]]]);
check($c->rotateWebhookSecret('we_2')['secret'] === 'whsec_test_new' && sent()[0]['body'] === '{}', 'rotateWebhookSecret: the new secret, from an empty JSON object');

// === Accept and amounts ========================================================

check(Client::acceptOf(['types' => ['Card', 'card'], 'countries' => ['cm'], 'currencies' => 'payment']) === ['types' => ['card'], 'countries' => ['CM'], 'currencies' => 'payment'], 'acceptOf folds and dedupes codes');
check(Client::acceptOf(['types' => 'card']) === null && Client::acceptOf(['types' => ['a b']]) === null && Client::acceptOf(['currencies' => 'all']) === null, 'acceptOf refuses what the API would refuse');
check(Client::acceptOf(['types' => [], 'channels' => null]) === [], 'acceptOf: nothing restricted is []');
check(Amount::normalize(5000.4, 'XAF') === 5000.0 && Amount::normalize(10.556, 'eur') === 10.56, 'amounts as the currency counts them');
check(Amount::collected(['amount' => 1000, 'amount_paid' => 1200]) === 1000.0 && Amount::collected(['amount' => 1000, 'amount_paid' => 400]) === 400.0 && Amount::collected(['amount' => 1000]) === 1000.0, 'a payment counts for what it collected, never more than it asked');

// === A stand-in's address =======================================================

define('_WAJUB_API_URL_', 'http://host.containers.internal:18766');
check(services()->apiUrl() === Client::BASE_URL, 'a stand-in\'s address is never used outside debug mode: a key sent elsewhere is a key given away');
$c = new Client('sk_test.k', true, $GLOBALS['http'], 'UA', null, null, 'http://stub.example:9000/');
thrown(static fn () => $c->checkKey());
check(($GLOBALS['http']->sent[0]['url'] ?? '') === 'http://stub.example:9000/', 'a client given another address calls it');

done('client');

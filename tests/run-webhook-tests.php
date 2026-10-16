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
 * The webhook (Webhook\Receiver, Signature, WebhookHealth) and its
 * registration (Webhook\WebhookRegistration): a delivery is verified over
 * its raw body with both environments' signing secrets, a refused one acts
 * on nothing and is noted for the merchant only when it carries Wajub's
 * headers and repeats, each event is acted on once, a delivery of the other
 * environment is acknowledged and acts on nothing (a live one is acted on in
 * Sandbox, except in a copy of the shop, which asks for it again later), and
 * is not kept, so another shop of the same PrestaShop acts on that event, a
 * handler that fails leaves the event to be delivered again, and an event
 * nobody handles is acknowledged. Registration in a multistore never trusts
 * a signing secret a shop only inherits.
 *
 *     php tests/run-webhook-tests.php
 */

require_once __DIR__ . '/bootstrap.php';

use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\EventHandler;
use Wajub\PrestaShop\Webhook\EventStore;
use Wajub\PrestaShop\Webhook\Signature;
use Wajub\PrestaShop\Webhook\WebhookHealth;
use Wajub\PrestaShop\Webhook\WebhookRegistration;

const API = 'https://api.wajub.com';
const SANDBOX_SECRET = 'whsec_test_sandboxsecret';
const LIVE_SECRET = 'whsec_livesecret';

final class RecordingHandler implements EventHandler
{
    /** @var list<Event> */
    public array $handled = [];

    public ?Throwable $fail = null;

    public function handles(string $type): bool
    {
        return str_starts_with($type, 'payment.');
    }

    public function handle(Event $event): void
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        $this->handled[] = $event;
    }
}

/** A shop with both signing secrets, in `environment`, with a handler of payment events. */
function shop(string $environment = 'sandbox', array $secrets = ['sandbox' => SANDBOX_SECRET, 'live' => LIVE_SECRET]): Services
{
    $s = services();
    $s->settings()->setEnvironment($environment);
    $s->settings()->setSecretKey('sandbox', key_for('sandbox'));
    $s->settings()->setSecretKey('live', key_for('live'));
    foreach ($secrets as $env => $secret) {
        $s->settings()->setWebhookSecret($env, $secret);
    }
    $GLOBALS['handler'] = new RecordingHandler();
    $s->addEventHandler($GLOBALS['handler']);

    return $s;
}

function payload(array $data = [], string $event = 'payment.succeeded', string $id = 'evt_test_1'): string
{
    return (string) json_encode(['id' => $id, 'event' => $event, 'data' => $data + ['id' => 'trx_test_1', 'status' => 'succeeded', 'sandbox' => true]]);
}

/** Delivers `raw` signed with `secret`, `age` seconds ago. */
function deliver(Services $s, string $raw, string $secret = SANDBOX_SECRET, int $age = 0, ?int $now = null): array
{
    $timestamp = time() - $age;

    return $s->receiver()->receive($raw, Signature::sign($raw, $secret, $timestamp), (string) $timestamp, $now);
}

function health(Services $s): array
{
    return $s->webhookHealth()->state();
}

// === Signature =================================================================

$raw = payload();
$ts = time();
$signature = Signature::sign($raw, SANDBOX_SECRET, $ts);
check(str_starts_with($signature, 'v1=') && $signature === 'v1=' . hash_hmac('sha256', $ts . '.' . $raw, SANDBOX_SECRET), 'the signature is the HMAC SHA-256 of the timestamp, a dot and the raw body');
check(Signature::verify($raw, $signature, (string) $ts, SANDBOX_SECRET), 'a delivery signed with the secret is verified');
check(!Signature::verify($raw . ' ', $signature, (string) $ts, SANDBOX_SECRET), 'a body changed by one byte is not');
check(!Signature::verify($raw, $signature, (string) $ts, LIVE_SECRET), 'nor with another secret');
check(!Signature::verify($raw, 'v1=' . str_repeat('0', 64), (string) $ts, SANDBOX_SECRET) && !Signature::verify($raw, substr($signature, 3), (string) $ts, SANDBOX_SECRET), 'nor a signature without v1= or of zeros');
check(!Signature::verify($raw, Signature::sign($raw, SANDBOX_SECRET, $ts - 301), (string) ($ts - 301), SANDBOX_SECRET) && Signature::verify($raw, Signature::sign($raw, SANDBOX_SECRET, $ts - 300), (string) ($ts - 300), SANDBOX_SECRET), 'five minutes old at most, either way');
check(Signature::matches($raw, Signature::sign($raw, SANDBOX_SECRET, $ts - 400), (string) ($ts - 400), SANDBOX_SECRET), 'matches() tells an old signature of the right secret');
check(!Signature::verify($raw, $signature, '', SANDBOX_SECRET) && !Signature::verify($raw, $signature, (string) $ts, ''), 'no timestamp, or no secret, verifies nothing');

// === Receiving =================================================================

$s = shop();
$answer = deliver($s, payload());
check($answer['status'] === 200 && $answer['body'] === ['received' => true], 'a verified delivery is answered 200, received');
check(count($GLOBALS['handler']->handled) === 1 && $GLOBALS['handler']->handled[0]->type === 'payment.succeeded' && $GLOBALS['handler']->handled[0]->environment === 'sandbox' && $GLOBALS['handler']->handled[0]->objectId() === 'trx_test_1', 'and handed to its handler, with the environment of the secret that verified it');
check($GLOBALS['events']->events['evt_test_1']['status'] === EventStore::PROCESSED && $GLOBALS['events']->events['evt_test_1']['object'] === 'trx_test_1' && $GLOBALS['events']->events['evt_test_1']['shop'] === 1, 'the event is kept as processed, with its object and shop');
check(health($s)['state'] === 'receiving' && health($s)['received']['event'] === 'payment.succeeded' && health($s)['received']['environment'] === 'sandbox', 'the webhook\'s health says deliveries arrive');

$answer = deliver($s, payload());
check($answer['status'] === 200 && ($answer['body']['duplicate'] ?? false) === true && count($GLOBALS['handler']->handled) === 1, 'the same event again is answered as received, and acts on nothing');

$GLOBALS['events']->busy = true;
$answer = deliver($s, payload([], 'payment.succeeded', 'evt_test_2'));
check($answer['status'] === 409 && count($GLOBALS['handler']->handled) === 1, 'an event another request acts on right now is left for Wajub to send again (409)');
$GLOBALS['events']->busy = false;

$s = shop();
$GLOBALS['handler']->fail = new RuntimeException('Order 42 could not be saved');
$answer = deliver($s, payload());
check($answer['status'] === 500 && $GLOBALS['events']->events['evt_test_1']['status'] === EventStore::FAILED, 'a handler that fails: 500, and the event is kept as failed');
check($GLOBALS['logger']->has('Webhook handling failed'), 'and logged');
$GLOBALS['handler']->fail = null;
$answer = deliver($s, payload());
check($answer['status'] === 200 && count($GLOBALS['handler']->handled) === 1 && $GLOBALS['events']->events['evt_test_1']['attempts'] === 2, 'Wajub\'s next delivery acts on it again');

$s = shop();
$answer = deliver($s, payload([], 'transfer.paid', 'evt_test_3'));
check($answer['status'] === 200 && $GLOBALS['handler']->handled === [] && $GLOBALS['events']->events['evt_test_3']['status'] === EventStore::IGNORED, 'an event nobody handles is answered as received');

$s = shop();
$raw = (string) json_encode(['type' => 'payment.failed', 'data' => ['id' => 'trx_test_9', 'status' => 'failed']]);
deliver($s, $raw);
check(isset($GLOBALS['events']->events['hash_' . hash('sha256', $raw)]) && $GLOBALS['handler']->handled[0]->type === 'payment.failed', 'an event without an id is known by its body; `type` is read as `event`');
$raw = (string) json_encode(['id' => 'evt_test_x', 'transaction' => ['id' => 'trx_test_x', 'status' => 'expired']]);
deliver($s, $raw);
check(end($GLOBALS['handler']->handled)->type === 'payment.expired', 'an older delivery without a type is named by its status');

// === Environments ==============================================================

$s = shop('live');
$answer = deliver($s, payload(), SANDBOX_SECRET);
check($answer['status'] === 200 && $answer['body'] === ['received' => true] && $GLOBALS['handler']->handled === [], 'a Sandbox delivery while Live is in use is acknowledged, and acts on nothing');
check(!isset($GLOBALS['events']->events['evt_test_1']), 'and is not kept as received: another shop of this PrestaShop may act on that event');
check($GLOBALS['logger']->has('Webhook from another environment not acted on'), 'and is logged');

$s = shop('sandbox');
$answer = deliver($s, payload(['id' => 'trx_1', 'sandbox' => false], 'payment.succeeded', 'evt_live_1'), LIVE_SECRET);
check($answer['status'] === 200 && count($GLOBALS['handler']->handled) === 1 && $GLOBALS['handler']->handled[0]->environment === 'live', 'a Live delivery is acted on while Sandbox is in use: real money, for orders taken in Live');

$s = shop('sandbox');
$GLOBALS['shop']->production = false;
$answer = deliver($s, payload(['id' => 'trx_1', 'sandbox' => false], 'payment.succeeded', 'evt_live_2'), LIVE_SECRET);
check($GLOBALS['handler']->handled === [], 'but never in a copy of the shop, which would pay its copies of real orders');
check($answer['status'] === 503 && $answer['body'] === ['received' => false, 'retry' => true] && !isset($GLOBALS['events']->events['evt_live_2']), 'which asks Wajub to send it again later (503), and keeps nothing of it');
$GLOBALS['shop']->production = true;
$answer = deliver($s, payload(['id' => 'trx_1', 'sandbox' => false], 'payment.succeeded', 'evt_live_2'), LIVE_SECRET);
check($answer['status'] === 200 && count($GLOBALS['handler']->handled) === 1 && $GLOBALS['events']->events['evt_live_2']['status'] === EventStore::PROCESSED, 'so a shop the public uses, in debug mode for a while, acts on it once Wajub sends it again');

$s = shop('sandbox');
$answer = deliver($s, payload(['sandbox' => false]), SANDBOX_SECRET);
check($answer['status'] === 200 && $GLOBALS['handler']->handled === [] && !isset($GLOBALS['events']->events['evt_test_1']), 'a delivery whose body says another environment than its signature acts on nothing, and is not kept');

// A multistore whose shops share the Wajub account: Wajub sends each event,
// under one id, to every endpoint, and the shops share the event store.
$a = shop('sandbox');
$handlerOfA = $GLOBALS['handler'];
$GLOBALS['shop']->id = 2;
$b = services([], new Scope(2, 1), true);
$b->settings()->setEnvironment('live');
$b->settings()->setSecretKey('live', key_for('live', 'B'));
$b->settings()->setWebhookSecret('sandbox', 'whsec_test_B');
$b->settings()->setWebhookSecret('live', 'whsec_B');
$handlerOfB = new RecordingHandler();
$b->addEventHandler($handlerOfB);
check(deliver($b, payload(), 'whsec_test_B')['status'] === 200 && $handlerOfB->handled === [], 'multistore: the shop in Live acknowledges a sandbox event, and does not act on it');
$GLOBALS['shop']->id = 1;
$answer = deliver($a, payload());
check($answer['body'] === ['received' => true] && count($handlerOfA->handled) === 1, 'multistore: the shop in Sandbox, whose event it is, still acts on it: it is no duplicate');
$GLOBALS['shop']->id = 2;
$GLOBALS['shop']->production = false;
check(deliver($b, payload(), 'whsec_test_B')['body'] === ['received' => true, 'duplicate' => true], 'multistore: an event a shop acted on is a duplicate for every other shop');
$GLOBALS['shop']->production = true;
$GLOBALS['shop']->id = 1;

$s = shop('live');
deliver($s, payload(['id' => 'trx_1', 'sandbox' => false], 'payment.succeeded', 'evt_live_3'), LIVE_SECRET);
check(count($GLOBALS['handler']->handled) === 1, 'a Live delivery in Live is acted on');

// === Refusals ==================================================================

$s = shop();
$answer = deliver($s, payload(), 'whsec_test_someoneelse');
check($answer['status'] === 403 && $answer['body'] === ['received' => false] && $GLOBALS['handler']->handled === [] && $GLOBALS['events']->events === [], 'a delivery no saved secret signed: 403, nothing acted on, nothing kept');
check(health($s)['state'] === 'never' && health($s)['unverified']['count'] === 1, 'one refusal anyone could cause does not show a failing webhook: it is counted');

$GLOBALS['store']->now = time() + 61;
deliver($s, payload(), 'whsec_test_someoneelse', 0, $GLOBALS['store']->now);
$GLOBALS['store']->now = time() + 122;
deliver($s, payload(), 'whsec_test_someoneelse', 0, $GLOBALS['store']->now);
$h = health($s);
check($h['state'] === 'rejected' && $h['rejected']['reason'] === 'invalid_signature' && $h['rejected']['count'] === 3, 'three over two minutes with no verified delivery since: the webhook is failing');
check(str_contains(WebhookHealth::guidance($h['rejected']['reason'], new TestTranslator()), 'Get a new signing secret'), 'and the merchant is told what to do');
$GLOBALS['store']->now = 0;

$s = shop();
for ($i = 0; $i < 5; ++$i) {
    deliver($s, payload(), 'whsec_test_someoneelse');
}
check(health($s)['unverified']['count'] === 1, 'such refusals are written down once a minute at most: a flood writes nothing more');

$s = shop();
$s->receiver()->receive(payload(), 'v1=bogus', '');
$s->receiver()->receive(payload(), '', (string) time());
check(health($s)['unverified'] === null && health($s)['rejected'] === null, 'a request without Wajub\'s headers is refused and noted nowhere: a crawler must not show a failing webhook');

$s = shop();
$answer = deliver($s, payload(), SANDBOX_SECRET, 400);
check($answer['status'] === 403 && health($s)['state'] === 'rejected' && health($s)['rejected']['reason'] === 'stale' && health($s)['rejected']['environment'] === 'sandbox', 'a delivery of the right secret over five minutes old is refused as stale, at once: the server\'s clock is wrong');

$s = shop('sandbox', ['sandbox' => SANDBOX_SECRET]);
$answer = deliver($s, payload(['id' => 'trx_1', 'sandbox' => false]), LIVE_SECRET);
check($answer['status'] === 403 && health($s)['rejected']['reason'] === 'missing_secret' && health($s)['rejected']['environment'] === 'live', 'a Live delivery without a Live secret saved is refused as missing_secret');

$s = shop('sandbox', []);
check(deliver($s, payload())['status'] === 403, 'no secret at all verifies nothing');

$s = shop();
$answer = deliver($s, 'not json');
check($answer['status'] === 400 && health($s)['state'] === 'rejected' && health($s)['rejected']['reason'] === 'invalid_payload', 'a signed body that is not JSON is refused, and said');
check($s->receiver()->receive('', 'v1=x', '1')['status'] === 400, 'an empty body is refused');

$s = shop();
deliver($s, payload(), 'whsec_test_someoneelse');
deliver($s, payload([], 'payment.succeeded', 'evt_test_ok'), SANDBOX_SECRET, 0, time() + 5);
check(health($s)['state'] === 'receiving' && health($s)['unverified'] === null, 'a verified delivery after a refusal: deliveries arrive');

$s = shop();
deliver($s, payload([], 'payment.succeeded', 'evt_test_a'));
$other = services([], new Scope(2, 1), true);
check($other->webhookHealth()->state()['state'] === 'never', 'each shop of a multistore keeps its own webhook health');
check($other->webhookHealth('all')->state()['state'] === 'receiving', 'a shop that uses the webhook of all shops reads that one\'s health');
$GLOBALS['shop']->id = 2;
check(services([], null, true)->webhookHealth()->state()['state'] === 'receiving', 'the health of all shops\' webhook is that of its scope, whichever shop speaks for it');

// === Registration ==============================================================

$s = services();
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_other', 'url' => 'https://elsewhere.cm/hook']]]]],
    'POST ' . API . '/webhooks' => [[201, ['id' => 'we_1', 'secret' => 'whsec_test_created']]],
];
$results = $s->webhookRegistration()->registerAll();
check(array_keys($results) === ['sandbox'] && $results['sandbox']['status'] === 'registered', 'registerAll: for each environment with a key');
$body = $GLOBALS['http']->json(1);
check($body['url'] === 'https://boutique.cm/module/wajub/webhook' && $body['events'] === WebhookRegistration::EVENTS && $body['description'] === 'PrestaShop: Boutique & Co', 'create: the shop\'s address, the events the module acts on, and the shop\'s name');
check($s->settings()->webhookSecret('sandbox') === 'whsec_test_created' && $s->settings()->webhookEndpoint('sandbox')['id'] === 'we_1', 'create: the signing secret and the endpoint are kept');
check(!leaked('whsec_test_created', $results, $GLOBALS['logger']->dump()), 'create: the secret is in no answer or log line');

$s = services();
$s->settings()->setSecretKey('live', key_for('live'));
$s->settings()->setWebhookSecret('live', LIVE_SECRET);
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_2', 'url' => 'HTTPS://Boutique.cm/module/wajub/webhook/', 'events' => ['payment.succeeded'], 'active' => false]]]]],
    'PUT ' . API . '/webhooks/we_2' => [[200, ['id' => 'we_2']]],
];
$result = $s->webhookRegistration()->registerAll()['live'];
check($result['status'] === 'updated' && $GLOBALS['http']->json(1)['active'] === true && count($GLOBALS['http']->json(1)['events']) === count(WebhookRegistration::EVENTS), 'adopt: the shop\'s endpoint, found give or take case and slash, is switched on and given the events it lacks');
check($s->settings()->webhookSecret('live') === LIVE_SECRET, 'adopt: the secret saved stays');

$s = services();
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_3', 'url' => 'https://boutique.cm/module/wajub/webhook', 'events' => ['*']]]]]],
    'POST ' . API . '/webhooks/we_3/rotate-secret' => [[200, ['id' => 'we_3', 'secret' => 'whsec_test_rotated']]],
];
check($s->webhookRegistration()->registerAll()['sandbox']['status'] === 'rotated' && $s->settings()->webhookSecret('sandbox') === 'whsec_test_rotated', 'adopt: an endpoint whose secret the shop does not have gets a new one');
$s->webhookRegistration()->registerAll(true);
check(in_array('POST ' . API . '/webhooks/we_3/rotate-secret', $GLOBALS['http']->routes(), true), '"Get a new signing secret" rotates it even when one is saved');

$s = services();
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$s->settings()->setWebhookSecret('sandbox', SANDBOX_SECRET);
$s->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_4', 'url' => 'https://boutique.cm/module/wajub/webhook', 'at' => time()]);
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_4', 'url' => 'https://boutique.cm/module/wajub/webhook', 'events' => ['*'], 'active' => true]]]]],
];
check($s->webhookRegistration()->registerAll()['sandbox']['status'] === 'existing' && $s->settings()->webhookSecret('sandbox') === SANDBOX_SECRET, 'adopt: the endpoint the shop registered, with its secret: nothing to change');
$s->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_old', 'url' => 'https://ancienne.cm/module/wajub/webhook', 'at' => time()]);
$GLOBALS['http']->routes['POST ' . API . '/webhooks/we_4/rotate-secret'] = [[200, ['id' => 'we_4', 'secret' => 'whsec_test_new_address']]];
$GLOBALS['http']->reset();
check($s->webhookRegistration()->registerAll()['sandbox']['status'] === 'rotated' && $s->settings()->webhookSecret('sandbox') === 'whsec_test_new_address' && $s->settings()->webhookEndpoint('sandbox')['id'] === 'we_4', 'adopt: the secret kept with an endpoint of an older address is not this one\'s: a new one is asked for');

// Multistore: shop 2 inherits the endpoint and secret all shops keep, which
// are the default shop's, at another address. Wajub has shop 2's endpoint.
$all = services();
$all->settings()->setSecretKey('sandbox', key_for('sandbox', 'G'));
$all->settings()->setWebhookSecret('sandbox', 'whsec_test_SHOP1');
$all->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_shop1', 'url' => 'https://boutique.cm/module/wajub/webhook', 'at' => time()]);
$shop2 = services([], new Scope(2, 1), true);
$GLOBALS['shop']->id = 2;
$GLOBALS['shop']->webhookUrl = 'https://shop2.cm/module/wajub/webhook';
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_shop2', 'url' => 'https://shop2.cm/module/wajub/webhook', 'events' => ['*'], 'active' => true]]]]],
    'POST ' . API . '/webhooks/we_shop2/rotate-secret' => [[200, ['id' => 'we_shop2', 'secret' => 'whsec_test_SHOP2']]],
];
$result = $shop2->webhookRegistration()->registerAll()['sandbox'];
check($result['status'] === 'rotated' && $shop2->settings()->webhookSecret('sandbox') === 'whsec_test_SHOP2', 'multistore: a shop that only inherits a signing secret gets one of its own endpoint');
check($all->settings()->webhookSecret('sandbox') === 'whsec_test_SHOP1' && $all->settings()->webhookEndpoint('sandbox')['id'] === 'we_shop1', 'multistore: saved for that shop alone: all shops keep theirs');
check(deliver($shop2, payload([], 'payment.succeeded', 'evt_test_shop2'), 'whsec_test_SHOP2')['status'] === 200, 'multistore: and that shop then verifies the deliveries of its own endpoint');
$GLOBALS['http']->reset();
check($shop2->webhookRegistration()->registerAll()['sandbox']['status'] === 'existing' && !in_array('POST ' . API . '/webhooks/we_shop2/rotate-secret', $GLOBALS['http']->routes(), true), 'multistore: registered again, its own secret is kept');
$GLOBALS['shop']->id = 1;

$s = services();
$s->settings()->setSecretKey('live', key_for('live'));
$GLOBALS['shop']->webhookUrl = 'http://boutique.cm/module/wajub/webhook';
$result = $s->webhookRegistration()->registerAll()['live'];
check($result['status'] === 'manual' && $result['reason'] === 'https_required' && $GLOBALS['http']->sent === [], 'a live webhook needs https: the manual steps, without asking Wajub');
$GLOBALS['shop']->webhookUrl = 'https://boutique.test/module/wajub/webhook';
$result = $s->webhookRegistration()->registerAll()['live'];
check($result['status'] === 'manual' && $result['reason'] === 'private_url' && str_contains($result['message'], 'boutique.test'), 'a local address cannot be reached: the manual steps');
check(str_contains($result['message'], 'Live webhooks work once the shop is online, at an https:// address.') && !str_contains($result['message'], 'wajub listen') && !str_contains($result['message'], 'tunnel'), 'in Live: once the shop is online, no Wajub CLI to forward live events to a computer: ' . $result['message']);
// In Sandbox, the Wajub CLI's `wajub listen`: no tunnel, no endpoint in the
// Dashboard, its own signing secret pasted by hand.
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$GLOBALS['shop']->webhookUrl = 'http://localhost:8082/en/module/wajub/webhook';
$GLOBALS['http']->reset();
$result = $s->webhookRegistration()->registerAll()['sandbox'];
check($result['reason'] === 'private_url' && $GLOBALS['http']->sent === [] && $result['message'] === 'Wajub cannot reach localhost: it is a local or private address. Automatic setup works once the shop is online. Meanwhile, to receive Sandbox events on this computer, run “wajub listen --forward-to http://localhost:8082/en/module/wajub/webhook” with the Wajub CLI, and paste the secret that “wajub listen --print-secret” shows into Sandbox signing secret, under Set the webhook up by hand: no endpoint is added in the Wajub Dashboard.', 'in Sandbox: the Wajub CLI command for this address, and where its secret goes, never a tunnel: ' . $result['message']);
check(WebhookRegistration::listenCommand('http://localhost:8082/module/wajub/webhook') === 'wajub listen --forward-to http://localhost:8082/module/wajub/webhook'
    && WebhookRegistration::listenCommand('http://localhost:8082/module/wajub/webhook?scope=s2') === "wajub listen --forward-to 'http://localhost:8082/module/wajub/webhook?scope=s2'"
    && WebhookRegistration::listenCommand('http://shop/index.php?fc=module&module=wajub&controller=webhook') === "wajub listen --forward-to 'http://shop/index.php?fc=module&module=wajub&controller=webhook'", 'the command quotes an address a shell would read otherwise (? and &)');
$GLOBALS['shop']->webhookUrl = 'https://boutique.test/module/wajub/webhook';
foreach (['https://localhost/x' => 'private_url', 'https://192.168.1.5/x' => 'private_url', 'https://10.0.0.1/x' => 'private_url', 'https://shop/x' => 'private_url', 'https://8.8.8.8/x' => '', 'http://boutique.cm/x' => '', 'nonsense' => 'invalid_url'] as $url => $problem) {
    check(WebhookRegistration::localAddressProblem($url, 'sandbox') === $problem, "localAddressProblem({$url}) is '{$problem}'");
}

$s = services();
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [[403, ['message' => 'Forbidden']]]];
check($s->webhookRegistration()->registerAll()['sandbox']['reason'] === 'key', 'a key that cannot manage webhooks: the manual steps');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [[200, ['items' => []]]], 'POST ' . API . '/webhooks' => [[422, ['message' => 'Invalid', 'errors' => ['url' => ['The url resolves to a private address.']]]]]];
check($s->webhookRegistration()->registerAll()['sandbox']['reason'] === 'private_url', 'Wajub\'s refusal of a private address is read from its errors');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [network_down()]];
check($s->webhookRegistration()->registerAll()['sandbox']['status'] === 'error', 'Wajub out of reach: an error to try again');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [[200, ['items' => []]]], 'POST ' . API . '/webhooks' => [[201, ['id' => 'we_9']]]];
check($s->webhookRegistration()->registerAll()['sandbox']['reason'] === 'no_secret', 'an endpoint created without its secret says where to copy it from');

// What the webhook listens to: the plugin's events, and those that carry what
// the handlers keep besides. Wajub has no payment.partial: a payment between
// two instalments says so in its payment.split.* events.
foreach (['payment.succeeded', 'payment.failed', 'payment.expired', 'payment.cancelled', 'payment.processing', 'refund.created', 'refund.succeeded', 'refund.failed', 'refund.cancelled', 'dispute.created', 'dispute.updated', 'dispute.won', 'dispute.lost', 'dispute.closed'] as $event) {
    check(in_array($event, WebhookRegistration::EVENTS, true), "the webhook listens to {$event}, as the plugin's");
}
foreach (['payment.split.processing', 'payment.split.succeeded', 'payment.split.failed', 'refund.processing'] as $event) {
    check(in_array($event, WebhookRegistration::EVENTS, true), "and to {$event}");
}
check(!in_array('payment.partial', WebhookRegistration::EVENTS, true) && count(WebhookRegistration::EVENTS) === count(array_unique(WebhookRegistration::EVENTS)), 'never an event Wajub does not send, nor one twice');

// A shop registered before is given the events it lacks (upgrade-1.0.1.php):
// nothing created, no secret changed.
$s = services();
$s->settings()->setSecretKey('sandbox', key_for('sandbox'));
$s->settings()->setWebhookSecret('sandbox', SANDBOX_SECRET);
$s->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_up', 'url' => 'https://boutique.cm/module/wajub/webhook', 'at' => time()]);
$older = ['payment.succeeded', 'payment.failed', 'payment.expired', 'payment.cancelled', 'payment.processing', 'refund.created', 'refund.succeeded'];
$GLOBALS['http']->routes = [
    'GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_other', 'url' => 'https://boutique.cm/module/wajub/webhook'], ['id' => 'we_up', 'url' => 'https://boutique.cm/module/wajub/webhook', 'events' => $older, 'active' => true]]]]],
    'PUT ' . API . '/webhooks/we_up' => [[200, ['id' => 'we_up']]],
];
$GLOBALS['http']->reset();
$result = $s->webhookRegistration()->addMissingEvents();
$put = $GLOBALS['http']->json(1);
check($result === ['sandbox' => 'updated', 'live' => 'none'] && $GLOBALS['http']->routes() === ['GET ' . API . '/webhooks?per_page=100&search=https%3A%2F%2Fboutique.cm%2Fmodule%2Fwajub%2Fwebhook', 'PUT ' . API . '/webhooks/we_up'], 'upgrade: the endpoint the shop registered, found by its id, given the events it lacks: ' . json_encode([$result, $GLOBALS['http']->routes()]));
check(array_values(array_diff(WebhookRegistration::EVENTS, $put['events'] ?? [])) === [] && array_slice($put['events'] ?? [], 0, count($older)) === $older && !isset($put['active']), 'upgrade: its events kept, the new ones added, nothing else changed: ' . json_encode($put));
check($s->settings()->webhookSecret('sandbox') === SANDBOX_SECRET && $s->settings()->webhookEndpoint('sandbox')['id'] === 'we_up', 'upgrade: its secret and endpoint stay');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [[200, ['items' => [['id' => 'we_up', 'url' => 'https://boutique.cm/module/wajub/webhook', 'events' => ['*']]]]]]];
$GLOBALS['http']->reset();
check($s->webhookRegistration()->addMissingEvents()['sandbox'] === 'current' && count($GLOBALS['http']->sent) === 1, 'upgrade: an endpoint of every event (*): left as it is');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [[200, ['items' => []]]]];
$GLOBALS['http']->reset();
check($s->webhookRegistration()->addMissingEvents()['sandbox'] === 'none' && count($GLOBALS['http']->sent) === 1, 'upgrade: an endpoint Wajub no longer has: nothing created');
$GLOBALS['http']->routes = ['GET ' . API . '/webhooks' => [network_down()]];
check($s->webhookRegistration()->addMissingEvents()['sandbox'] === 'error', 'upgrade: Wajub out of reach: said, never an exception that would stop the upgrade');
$shop2 = services([], new Scope(2, 1), true);
$shop2->settings()->setSecretKey('sandbox', key_for('sandbox', 'B'));
$GLOBALS['http']->reset();
check($shop2->webhookRegistration()->addMissingEvents() === ['sandbox' => 'none', 'live' => 'none'] && $GLOBALS['http']->sent === [], 'upgrade: a shop that uses the endpoint of all shops has none of its own to change');
check($s->settings()->webhookEndpointsEverywhere() === ['all'], 'upgrade: the scopes that registered an endpoint of their own: ' . json_encode($s->settings()->webhookEndpointsEverywhere()));

$s = services();
$s->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_1', 'url' => 'https://boutique.cm/module/wajub/webhook/', 'at' => time()]);
check(!$s->webhookRegistration()->moved('sandbox'), 'moved(): the same address, give or take a slash, has not moved');
$GLOBALS['shop']->webhookUrl = 'https://boutique.cm/index.php?fc=module&module=wajub&controller=webhook';
check($s->webhookRegistration()->moved('sandbox') && !$s->webhookRegistration()->moved('live'), 'moved(): an address that changed since (friendly URLs turned off) is said; an environment never registered is not');

done('webhook');

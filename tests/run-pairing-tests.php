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
 * "Connect with Wajub" (Pairing\Pairing, Pairing\PairingClient, through
 * Admin\AjaxActions): the session the shop opens on the Wajub Dashboard and
 * what it sends for it (asked again without what an older Dashboard
 * refuses), every answer of the Dashboard's poll, a request replaced by a
 * start in another tab, and what the shop does once the merchant approved:
 * the key saved through the same checks as a pasted one, the webhook
 * registered with it, the key it replaced revoked after the save (never a
 * pasted one, never from a copy of the shop), what the approver chose kept
 * with its origin, its expiry shown, and the key never in an answer, a log
 * line or the state store.
 *
 *     php tests/run-pairing-tests.php
 */

require_once __DIR__ . '/bootstrap.php';

use Wajub\PrestaShop\Admin\AjaxActions;
use Wajub\PrestaShop\Pairing\Pairing;
use Wajub\PrestaShop\Pairing\PairingException;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Scope;

const SESSIONS = 'https://dashboard.wajub.com/api/apps/auth/sessions';
const POLL = 'https://dashboard.wajub.com/api/apps/auth/sessions/poll';
const API = 'https://api.wajub.com';
const CODE = 'K7QD-9XHT';
const EMPLOYEE = 7;

define('TOKEN', 'tok' . str_repeat('A1b2', 15) . 'z');
define('SANDBOX_KEY', 'sk_test.PAIREDsandboxKEY' . str_repeat('s', 80));
define('LIVE_KEY', 'sk.PAIREDliveKEY' . str_repeat('l', 87));
define('RESTRICTED_KEY', 'rk_test.PAIREDrestrictedKEY' . str_repeat('r', 77));
define('OLD_PAIRED_KEY', 'sk_test.OLDpairedKEY' . str_repeat('o', 84));
define('PASTED_KEY', 'sk_test.PASTEDkey' . str_repeat('p', 87));

/** The Dashboard's answer to a start. */
function created(array $overrides = []): array
{
    return [201, $overrides + [
        'pairing_code' => CODE,
        'polling_token' => TOKEN,
        'client_type' => 'prestashop',
        'confirm_url' => 'https://dashboard.wajub.com/connect?code=' . CODE,
        'expires_at' => gmdate('c', time() + 900),
        'interval' => 2,
    ]];
}

/** The Dashboard's answer once the merchant approved. */
function authorized(array $overrides = []): array
{
    return [200, $overrides + [
        'status' => 'authorized',
        'key' => SANDBOX_KEY,
        'environment' => 'sandbox',
        'client_type' => 'prestashop',
        'business' => 'Boutique Douala',
        'key_type' => 'private',
        'expires_at' => null,
    ]];
}

/** Wajub's `GET /` for a key of `environment`. */
function hello(string $environment, string $business = 'Boutique Douala'): array
{
    return [200, ['business' => $business, 'env' => $environment === 'live' ? 'production' : 'sandbox']];
}

/** A shop whose webhook registers at once, with a session started for `environment`. */
function begin(string $environment = 'sandbox', ?Services $s = null): Services
{
    $s = $s ?? services();
    $GLOBALS['http']->routes += [
        'POST ' . SESSIONS => [created()],
        'GET ' . API . '/' => [hello('sandbox')],
        'GET ' . API . '/webhooks' => [[200, ['items' => []]]],
        'POST ' . API . '/webhooks' => [[201, ['id' => 'we_1', 'secret' => 'whsec_test_new']]],
    ];
    $s->pairing()->start($environment, EMPLOYEE);

    return $s;
}

function session(): mixed
{
    return $GLOBALS['store']->get('pairing:' . EMPLOYEE . ':all');
}

function ajax(Services $s, string $action, array $posted = []): array
{
    return (new AjaxActions($s))->handle($action, $posted, EMPLOYEE);
}

// === Start =====================================================================

$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [created()];
$answer = ajax($s, 'pairing_start', ['environment' => 'sandbox']);
$data = $answer['body']['data'];
check($answer['status'] === 200 && $answer['body']['success'] === true, 'start: a session is opened');
check(array_keys($data) === ['code', 'confirm_url', 'expires_at', 'expires_in', 'interval', 'environment'], 'start: it answers the code, the address, the expiry, the interval and the environment');
check($data['code'] === CODE && $data['confirm_url'] === 'https://dashboard.wajub.com/connect?code=' . CODE && $data['environment'] === 'sandbox', 'start: the code and the Dashboard page to approve it on');
check($data['interval'] === 3, 'start: polled every 3 seconds at least, though the Dashboard says 2');
check($data['expires_in'] > 880 && $data['expires_in'] <= 900, 'start: it expires when the Dashboard says, 15 minutes');
check(!leaked(TOKEN, $answer), 'start: the answer never holds the polling token');

$request = $GLOBALS['http']->sent[0];
$body = $GLOBALS['http']->json(0);
check($request['method'] === 'POST' && $request['url'] === SESSIONS && $request['timeout'] === 10, 'start: one POST to the Dashboard\'s apps/auth/sessions, 10 seconds');
check($body === [
    'device_name' => 'Boutique & Co (boutique.cm)',
    'client_type' => 'prestashop',
    'environment' => 'sandbox',
    'client_key' => substr(hash('sha256', 'https://boutique.cm/'), 0, 32),
    'permissions' => ['payment.read', 'payment.write', 'refund.read', 'refund.write', 'webhook.read', 'webhook.write'],
], 'start: the shop\'s name and host, the prestashop client type, a hash of its address and the permissions it needs: ' . json_encode($body));
check(!array_key_exists('return_url', $body), 'start: no return_url: the back office\'s address names its secret folder');
check(!isset($request['headers']['Authorization']) && str_starts_with($request['headers']['User-Agent'], 'Wajub-PrestaShop/1.0.0 PrestaShop/8.2.8'), 'start: no token yet, and the module\'s User-Agent');

$stored = session();
check(is_array($stored) && $stored['token'] === TOKEN && $stored['state'] === 'pending' && $stored['code'] === CODE && $stored['client_type'] === 'prestashop', 'start: the session is kept for the employee, with its polling token');
$ttl = $GLOBALS['store']->ttl('pairing:' . EMPLOYEE . ':all');
check($ttl > 900 + 3600 - 20 && $ttl <= 900 + 3600, 'start: kept until an hour after the Dashboard\'s expiry, so a late approval is still collected: ' . $ttl);
check($s->pairing()->pending(EMPLOYEE) !== null && !leaked(TOKEN, $s->pairing()->pending(EMPLOYEE)), 'pending(): what the browser may see of the session, never the token');
check($s->pairing()->pending(EMPLOYEE + 1) === null, 'pending(): another employee has no session');

$shopScoped = services([], new Scope(3, 1), true);
check($shopScoped->pairing()->pending(EMPLOYEE) === null, 'pending(): a session is for the shop scope it started in: another scope has none');

for ($i = 2; $i <= 5; ++$i) {
    check(ajax($s, 'pairing_start', ['environment' => 'sandbox'])['body']['success'], "start: start {$i} of the minute goes through");
}
$limited = ajax($s, 'pairing_start', ['environment' => 'live']);
check(!$limited['body']['success'] && $limited['status'] === 429 && $limited['body']['data']['code'] === 'rate_limited' && count($GLOBALS['http']->sent) === 5, 'start: the sixth in a minute is refused (429), and asks the Dashboard nothing');
check(ajax($s, 'pairing_start', ['environment' => 'staging'])['status'] === 400, 'start: an environment other than sandbox or live is refused (400)');

// A Dashboard that does not know `prestashop` yet.
$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [[422, ['message' => 'Invalid', 'errors' => ['client_type' => ['The selected client type is invalid.']]]], created(['client_type' => 'other'])];
$opened = $s->pairing()->start('sandbox', EMPLOYEE);
check(count($GLOBALS['http']->sent) === 2 && $GLOBALS['http']->json(0)['client_type'] === 'prestashop' && $GLOBALS['http']->json(1)['client_type'] === 'other', 'client type: a 422 on client_type is asked again as "other"');
check(array_diff_key($GLOBALS['http']->json(0), ['client_type' => 1]) === array_diff_key($GLOBALS['http']->json(1), ['client_type' => 1]), 'client type: nothing else changes');
check($GLOBALS['store']->get('pairing_client_type') === 'other' && $GLOBALS['store']->ttl('pairing_client_type') === 86400, 'client type: "other" is remembered a day');
check(session()['client_type'] === 'other', 'client type: the session knows the type its key is named after');
$GLOBALS['http']->reset();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [created()];
$s->pairing()->start('sandbox', EMPLOYEE);
check(count($GLOBALS['http']->sent) === 1 && $GLOBALS['http']->json(0)['client_type'] === 'other', 'client type: the next start makes one request, as "other"');

$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [[422, ['errors' => ['permissions.2' => ['Invalid permission.']]]], created()];
$s->pairing()->start('sandbox', EMPLOYEE);
check(count($GLOBALS['http']->sent) === 2 && !array_key_exists('permissions', $GLOBALS['http']->json(1)), 'permissions: a 422 on one of them is asked again without them');

$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [[422, ['errors' => ['client_type' => ['x'], 'permissions' => ['y']]]], [422, ['errors' => ['client_type' => ['x'], 'permissions' => ['y']]]]];
$failed = thrown(static fn () => $s->pairing()->start('sandbox', EMPLOYEE));
check($failed instanceof PairingException && $failed->reason === 'refused' && count($GLOBALS['http']->sent) === 2, 'a 422 on both is asked again once without both, then the merchant is told');
check(session() === null, 'start: no session is kept for a refused start');

$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [[422, ['errors' => ['device_name' => ['Too long.']]]]];
check(thrown(static fn () => $s->pairing()->start('sandbox', EMPLOYEE)) instanceof PairingException && count($GLOBALS['http']->sent) === 1, 'start: any other 422 is not asked again');

foreach ([
    'a code of another shape' => created(['pairing_code' => 'ABCD-0000']),
    'a token of another shape' => created(['polling_token' => 'short']),
    'a confirmation page elsewhere' => created(['confirm_url' => 'https://evil.example/connect?code=' . CODE]),
    'a confirmation page over http' => created(['confirm_url' => 'http://dashboard.wajub.com/connect?code=' . CODE]),
    'a confirmation page with a user' => created(['confirm_url' => 'https://x@dashboard.wajub.com/connect']),
    'a confirmation page on another path' => created(['confirm_url' => 'https://dashboard.wajub.com/logout']),
    'no expiry' => created(['expires_at' => 'never']),
    'a 200 instead of a 201' => [200, created()[1]],
] as $case => $answer) {
    $s = services();
    $GLOBALS['http']->routes['POST ' . SESSIONS] = [$answer];
    $failed = thrown(static fn () => $s->pairing()->start('sandbox', EMPLOYEE));
    check($failed instanceof PairingException && session() === null, "start: {$case} is refused before the merchant is sent anywhere");
}

$s = services();
$GLOBALS['http']->routes['POST ' . SESSIONS] = [created(['interval' => 120, 'expires_at' => gmdate('c', time() + 86400)])];
$opened = $s->pairing()->start('sandbox', EMPLOYEE);
check($opened['interval'] === 30 && $opened['expires_in'] === 900, 'start: an interval of 2 minutes is polled every 30 seconds, and a lifetime that makes no sense is the documented 15 minutes');

foreach ([
    'no answer' => [network_down(), 'unreachable', 200],
    'a server error' => [[503, ['message' => 'Down']], 'unavailable', 200],
    'the Dashboard\'s own limit' => [[429, ['message' => 'Too many']], 'busy', 200],
] as $case => [$answer, $code, $status]) {
    $s = services();
    $GLOBALS['http']->routes['POST ' . SESSIONS] = [$answer];
    $response = ajax($s, 'pairing_start', ['environment' => 'sandbox']);
    check(!$response['body']['success'] && $response['status'] === $status && $response['body']['data']['code'] === $code, "start: {$case} is answered 200, success false, as {$code}: a proxy would hide a 5xx's words");
    check(!leaked(TOKEN, $response, $GLOBALS['logger']->dump()), "start: {$case}: the token is neither answered nor logged");
}

// === Poll ======================================================================

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [[200, ['status' => 'pending']]];
$GLOBALS['http']->reset();
$answer = ajax($s, 'pairing_poll', ['code' => strtolower(CODE)]);
check($answer['body']['data'] === ['status' => 'pending'], 'poll: pending while the merchant decides');
check($GLOBALS['http']->sent[0]['url'] === POLL && $GLOBALS['http']->sent[0]['headers']['Authorization'] === 'Bearer ' . TOKEN && !str_contains($GLOBALS['http']->sent[0]['url'], TOKEN), 'poll: the token travels in the Authorization header only, never in the address');
check($GLOBALS['store']->get('pairing_lock:' . EMPLOYEE) === null, 'poll: the lock is released');

$GLOBALS['store']->set('pairing_lock:' . EMPLOYEE, time(), 30);
$GLOBALS['http']->reset();
check($s->pairing()->poll(EMPLOYEE, CODE) === ['status' => 'pending'] && $GLOBALS['http']->sent === [], 'lock: while another poll holds it, the answer is pending and the Dashboard is not asked');
$GLOBALS['store']->now = time() + 31;
check($s->pairing()->poll(EMPLOYEE, CODE)['status'] === 'pending' && count($GLOBALS['http']->sent) === 1, 'lock: a lock older than 30 seconds was left by a request that died: it lapses');
$GLOBALS['store']->now = 0;

foreach ([
    'denied' => [[403, ['status' => 'denied']], 'denied'],
    'expired' => [[410, ['status' => 'expired']], 'expired'],
    'not found' => [[404, ['status' => 'not_found']], 'expired'],
] as $case => [$answer, $status]) {
    $s = begin();
    $GLOBALS['http']->routes['GET ' . POLL] = [$answer];
    $result = $s->pairing()->poll(EMPLOYEE, CODE);
    check($result['status'] === $status && $result['message'] !== '' && session() === null, "poll: {$case} ends the session, with words for the merchant");
}

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [[503, ['message' => 'Down']]];
for ($i = 1; $i < 10; ++$i) {
    $s->pairing()->poll(EMPLOYEE, CODE);
}
check(session()['errors'] === 9 && session()['state'] === 'pending', 'poll: a failure is asked again, nine times in a row');
$result = $s->pairing()->poll(EMPLOYEE, CODE);
check($result['status'] === 'error' && session() === null, 'poll: the tenth failure in a row gives up, as `wajub login` does');

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [[200, ['status' => 'pending']]];
$result = ajax($s, 'pairing_poll', ['code' => 'ZZZZ-ZZZZ'])['body']['data'];
check($result['status'] === 'replaced' && str_contains($result['message'], 'no longer works'), 'replaced: a page whose code is no longer the session\'s is told so');
check(session() !== null && session()['code'] === CODE, 'replaced: the new session is left to the other tab');
check(ajax($s, 'pairing_poll', ['code' => '<script>'])['body']['data']['status'] === 'pending', 'poll: something that is not a code is ignored');

$s = begin();
$GLOBALS['store']->set('pairing:' . EMPLOYEE . ':all', ['state' => 'saving', 'saving_at' => time()] + session(), 3600);
$GLOBALS['http']->reset();
check($s->pairing()->poll(EMPLOYEE, CODE) === ['status' => 'pending'] && $GLOBALS['http']->sent === [], 'saving: while another request saves the key, pending, without asking the Dashboard');
check($s->pairing()->pending(EMPLOYEE) !== null, 'saving: a page opened meanwhile still shows the request');
$GLOBALS['store']->set('pairing:' . EMPLOYEE . ':all', ['state' => 'saving', 'saving_at' => time() - 200] + session(), 3600);
check($s->pairing()->poll(EMPLOYEE, CODE)['status'] === 'consumed' && session() === null, 'saving: a save that never finished reads as used');

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [[410, ['status' => 'consumed']]];
check($s->pairing()->poll(EMPLOYEE, CODE)['status'] === 'consumed' && session() === null, 'consumed: the key went to nobody this shop knows: the session ends');

// === Authorized ================================================================

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$GLOBALS['http']->reset();
$summary = ajax($s, 'pairing_poll', ['code' => CODE])['body']['data'];
check($summary['status'] === 'authorized' && $summary['environment'] === 'sandbox' && $summary['business'] === 'Boutique Douala', 'authorized: status, environment and business');
check(str_contains($summary['message'], 'Connected to Boutique Douala in Sandbox') && str_contains($summary['message'], 'does not expire'), 'authorized: the merchant is told where the shop is connected, and that the key does not expire: ' . $summary['message']);
check($s->settings()->secretKey('sandbox') === SANDBOX_KEY, 'authorized: the key is saved as the sandbox key');
$routes = $GLOBALS['http']->routes();
check(in_array('GET ' . API . '/', $routes, true) && $GLOBALS['http']->sent[1]['headers']['Authorization'] === SANDBOX_KEY, 'authorized: Wajub is asked about the key, as for a pasted one');
check($summary['webhook']['status'] === 'registered' && $s->settings()->webhookSecret('sandbox') === 'whsec_test_new', 'authorized: the webhook is registered with the new key, and its secret saved');
check($summary['warnings'] === [], 'authorized: nothing to warn about: ' . json_encode($summary['warnings']));
$origin = $s->settings()->keyOrigin('sandbox');
check(is_array($origin) && $origin['via'] === 'pairing' && $origin['fp'] === Keys::fingerprint(SANDBOX_KEY) && $origin['business'] === 'Boutique Douala' && $origin['client_type'] === 'prestashop', 'authorized: where the key came from is kept, by its fingerprint');
check($origin['home'] === 'boutique.cm' && $origin['site_type'] === 'production' && $origin['expires_at'] === 0 && $origin['key_type'] === 'private', 'authorized: with the shop\'s address, its type, the expiry and the key type');
check(!leaked(SANDBOX_KEY, $summary, $GLOBALS['store']->dump(), $GLOBALS['logger']->dump(), $origin), 'authorized: the key is in no answer, state, log line or origin');
check(!leaked(TOKEN, $summary, $GLOBALS['store']->dump(), $GLOBALS['logger']->dump()), 'authorized: nor is the token, once done');
check(session()['state'] === 'done' && $GLOBALS['store']->ttl('pairing:' . EMPLOYEE . ':all') <= 300, 'authorized: the session is done, its summary kept five minutes');
check($s->pairing()->pending(EMPLOYEE) === null, 'authorized: nothing is pending any more');
$GLOBALS['http']->reset();
check($s->pairing()->poll(EMPLOYEE, CODE) === $summary && $GLOBALS['http']->sent === [], 'authorized: a tab that asks after it is done gets the summary, without asking the Dashboard');
check(ajax($s, 'pairing_cancel', ['code' => CODE])['body']['data'] === $summary, 'cancel, once another tab saved the key: the shop is connected, and says so');

$env = $s->pairing()->environmentState('sandbox');
check($env['connected_on'] === 'Connected with Wajub on ' . gmdate('Y-m-d') && $env['button'] === 'Reconnect Sandbox' && $env['has_key'], 'the Connection tab: connected with Wajub, on the day, with Reconnect');
check($s->pairing()->environmentState('live')['button'] === 'Connect Live' && $s->pairing()->environmentState('live')['connected_on'] === '', 'and Connect for an environment without a key');

// The approver chose Live on the confirmation page.
$s = begin('sandbox');
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => LIVE_KEY, 'environment' => 'live'])];
$GLOBALS['http']->routes['GET ' . API . '/'] = [hello('live')];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check($summary['status'] === 'authorized' && $s->settings()->secretKey('live') === LIVE_KEY && $s->settings()->secretKey('sandbox') === '', 'environment: the one Wajub returns wins: saved as the live key, never in the Sandbox field');
check($s->settings()->environment() === 'sandbox', 'environment: Sandbox stays selected: the merchant turns Live on');
check(str_contains(implode(' ', $summary['warnings']), 'You approved Live instead') && str_contains(implode(' ', $summary['warnings']), 'Sandbox has no key'), 'environment: the merchant is told, and that Sandbox takes no payment without its own key');

// A key whose prefix says another environment than Wajub does.
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => LIVE_KEY, 'environment' => 'sandbox'])];
$GLOBALS['http']->reset();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => LIVE_KEY, 'environment' => 'sandbox'])];
$result = $s->pairing()->poll(EMPLOYEE, CODE);
check($result['status'] === 'error' && str_contains($result['message'], 'prefix does not match') && str_contains($result['message'], 'Boutique & Co (boutique.cm)'), 'a key whose prefix is not the environment Wajub gave is refused, and the merchant told which key to delete');
check($s->settings()->secretKey('sandbox') === '' && $s->settings()->secretKey('live') === '' && count($GLOBALS['http']->sent) === 1, 'and nothing is saved, registered or revoked');
check(!leaked(LIVE_KEY, $result, $GLOBALS['logger']->dump(), $GLOBALS['store']->dump()), 'and the refused key is in no answer, log line or state');

// A key Wajub refuses.
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$GLOBALS['http']->routes['GET ' . API . '/'] = [[401, ['message' => 'Unauthenticated']]];
$result = $s->pairing()->poll(EMPLOYEE, CODE);
check($result['status'] === 'error' && str_contains($result['message'], 'does not recognise') && $s->settings()->secretKey('sandbox') === '', 'a key Wajub refuses is not saved, and its words come back');
check($s->settings()->keyOrigin('sandbox') === null && $s->settings()->webhookEndpoint('sandbox') === null, 'and no origin, no webhook');

$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => ''])];
check($s->pairing()->poll(EMPLOYEE, CODE)['status'] === 'error' && session() === null, 'authorized without a key: an error, nothing saved');

// Saved while Wajub could not be asked about it.
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$GLOBALS['http']->routes['GET ' . API . '/'] = [network_down()];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check($summary['status'] === 'authorized' && $s->settings()->secretKey('sandbox') === SANDBOX_KEY && str_contains(implode(' ', $summary['warnings']), 'Test connection'), 'a key saved while Wajub could not be reached is kept, and the merchant warned');

// Restricted keys.
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => RESTRICTED_KEY, 'key_type' => 'restricted', 'permissions' => ['payment.read', 'payment.write', 'refund.read', 'refund.write']])];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check(str_contains(implode(' ', $summary['warnings']), 'lacks these permissions: webhook.read, webhook.write'), 'permissions: a restricted key is told what it lacks');
check($s->settings()->keyOrigin('sandbox')['permissions'] === ['payment.read', 'payment.write', 'refund.read', 'refund.write'], 'permissions: and what it has is kept');
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key_type' => 'restricted'])];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check($s->settings()->keyOrigin('sandbox')['key_type'] === 'restricted' && $GLOBALS['logger']->has('Paired key type does not match its prefix'), 'permissions: a full-access prefix the Dashboard calls restricted is taken as restricted, and logged');
$s = begin();
$GLOBALS['http']->routes['GET ' . POLL] = [authorized(['key' => RESTRICTED_KEY, 'key_type' => 'restricted', 'permissions' => Pairing::PERMISSIONS])];
check($s->pairing()->poll(EMPLOYEE, CODE)['warnings'] === [], 'permissions: a restricted key with everything the module needs is not warned about');

// Expiry.
$expires = time() + 90 * 86400;
$s = begin();
$answer = authorized(['key_expires_at' => gmdate('c', $expires)]);
unset($answer[1]['expires_at']);
$GLOBALS['http']->routes['GET ' . POLL] = [$answer];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check($s->settings()->keyOrigin('sandbox')['expires_at'] === $expires && str_contains($summary['message'], 'expires on ' . gmdate('Y-m-d', $expires)), 'expiry: read from key_expires_at when expires_at is missing, and said');
$origins = $s->keyOrigins();
check($origins->expiryState('sandbox') === '' && $origins->expiryState('sandbox', $expires - 86400) === 'expiring' && $origins->expiryState('sandbox', $expires + 1) === 'expired', 'expiry: warned within 14 days, expired after');
check(str_contains($s->pairing()->environmentState('sandbox')['expiry'], 'expires on'), 'expiry: the Connection tab shows the day');
check($origins->expiredOn('sandbox', SANDBOX_KEY, $expires + 5) === $expires && $origins->expiredOn('sandbox', PASTED_KEY, $expires + 5) === 0, 'expiredOn(): only for the key a pairing gave');
$origins->accepted('sandbox', SANDBOX_KEY, $expires + 5);
check(!array_key_exists('expires_at', (array) $s->settings()->keyOrigin('sandbox')) && $origins->expiryState('sandbox', $expires + 5) === '', 'a key Wajub accepted past its date: the date was wrong, and is forgotten');
$s->settings()->setSecretKey('sandbox', PASTED_KEY);
check($origins->pairedKey('sandbox') === null && $s->pairing()->environmentState('sandbox')['connected_on'] === '', 'a key pasted since is not said to come from Wajub');

// Business.
$s = services();
$s->connectionStatus()->remember(key_for('sandbox', 'X'), 'sandbox', 'connected', 'Ancienne Boutique');
$s = begin('sandbox', $s);
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check(str_contains(implode(' ', $summary['warnings']), 'This key belongs to Boutique Douala, not Ancienne Boutique'), 'business: a key of another business than the one the shop used is warned about');

// === Revoking the key it replaced ==============================================

/** A shop whose sandbox key a pairing gave before, from `home`, approved again now. */
function replacing(string $home = 'boutique.cm', bool $production = true, array $revokeAnswer = [204, '']): Services
{
    $s = services();
    $GLOBALS['shop']->production = $production;
    $s->settings()->setSecretKey('sandbox', OLD_PAIRED_KEY);
    $s->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint(OLD_PAIRED_KEY), 'home' => $home, 'at' => time() - 86400]);
    $GLOBALS['http']->routes['DELETE ' . API . '/keys/current'] = [$revokeAnswer];
    $s = begin('sandbox', $s);
    $GLOBALS['http']->routes['GET ' . POLL] = [authorized()];

    return $s;
}

$s = replacing();
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
$revokes = array_values(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE'));
check(count($revokes) === 1 && $revokes[0]['headers']['Authorization'] === OLD_PAIRED_KEY, 'revoke: the key a previous pairing gave is revoked, with itself');
$order = array_map(static fn (array $r): string => $r['method'] . ' ' . strtok($r['url'], '?'), $GLOBALS['http']->sent);
check(array_search('DELETE ' . API . '/keys/current', $order, true) > array_search('GET ' . API . '/', $order, true), 'revoke: only after the new key was checked and saved');
check($summary['warnings'] === [] && $s->settings()->secretKey('sandbox') === SANDBOX_KEY, 'revoke: nothing left to say');

$s = replacing('boutique.cm', true, [409, ['message' => 'Not a paired key']]);
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
check(str_contains(implode(' ', $summary['warnings']), 'still active') && $GLOBALS['logger']->has('Replaced paired key not revoked'), 'revoke: a key Wajub would not revoke is left, and the merchant told where to delete it');
$s = replacing('boutique.cm', true, [401, ['message' => 'Gone']]);
check($s->pairing()->poll(EMPLOYEE, CODE)['warnings'] === [], 'revoke: a key Wajub no longer knows was deleted already: nothing to say');

$s = replacing('boutique.cm');
$GLOBALS['shop']->baseUrl = 'http://www.boutique.cm/';
$s->pairing()->poll(EMPLOYEE, CODE);
check(count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE')) === 1, 'revoke: the same address, give or take www. and https, is the same shop');

foreach ([
    'a copy at another address' => static fn (): Services => replacing('staging.boutique.cm'),
    'a copy in debug mode' => static fn (): Services => replacing('boutique.cm', false),
] as $case => $make) {
    $s = $make();
    $summary = $s->pairing()->poll(EMPLOYEE, CODE);
    check(count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE')) === 0, "revoke: {$case} never revokes the key it shares with the shop it was copied from");
    check(str_contains(implode(' ', $summary['warnings']), 'was not deleted'), "revoke: {$case}: the merchant is told it may still be in use, and where to delete it");
}

$s = services();
$s->settings()->setSecretKey('sandbox', PASTED_KEY);
$s = begin('sandbox', $s);
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$GLOBALS['http']->routes['DELETE ' . API . '/keys/current'] = [[204, '']];
$s->pairing()->poll(EMPLOYEE, CODE);
check(count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE')) === 0 && $s->settings()->secretKey('sandbox') === SANDBOX_KEY, 'revoke: a pasted key is never revoked');

$s = services();
$s->settings()->setSecretKey('sandbox', PASTED_KEY);
$s->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint(OLD_PAIRED_KEY), 'home' => 'boutique.cm']);
$s = begin('sandbox', $s);
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$s->pairing()->poll(EMPLOYEE, CODE);
check(count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE')) === 0, 'revoke: a key pasted over a paired one is never revoked');

$s = services();
$s->settings()->setSecretKey('live', LIVE_KEY);
$s->settings()->setKeyOrigin('live', ['via' => 'pairing', 'fp' => Keys::fingerprint(LIVE_KEY), 'home' => 'boutique.cm']);
$s = begin('sandbox', $s);
$GLOBALS['http']->routes['GET ' . POLL] = [authorized()];
$s->pairing()->poll(EMPLOYEE, CODE);
check(count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE')) === 0 && $s->settings()->secretKey('live') === LIVE_KEY, 'revoke: a key of the other environment is never revoked');

// Multistore: a key saved for all shops (or a group) is every shop's that
// has none of its own. A shop or group that connects on its own saves its
// new key for itself alone.

/** All shops paired with OLD_PAIRED_KEY from the default shop's address; `scope` approves again now. */
function multistore(Scope $scope): Services
{
    $all = services();
    $all->settings()->setSecretKey('sandbox', OLD_PAIRED_KEY);
    $all->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint(OLD_PAIRED_KEY), 'home' => 'boutique.cm', 'at' => time() - 86400]);
    $GLOBALS['http']->routes['DELETE ' . API . '/keys/current'] = [[204, '']];
    $s = begin('sandbox', services([], $scope, true));
    $GLOBALS['http']->routes['GET ' . POLL] = [authorized()];

    return $s;
}

$deletes = static fn (): int => count(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE'));
foreach (['the default shop' => new Scope(1, 1), 'a group' => new Scope(null, 1)] as $who => $scope) {
    $s = multistore($scope);
    $summary = $s->pairing()->poll(EMPLOYEE, CODE);
    check($s->settings()->secretKey('sandbox') === SANDBOX_KEY && services([], Scope::allShops(), true)->settings()->secretKey('sandbox') === OLD_PAIRED_KEY, "multistore: {$who} connects on its own: the new key is its own, all shops keep theirs");
    check($deletes() === 0 && services([], new Scope(2, 2), true)->settings()->secretKey('sandbox') === OLD_PAIRED_KEY, "multistore: {$who} never revokes the key the other shops still pay with");
    check(str_contains(implode(' ', $summary['warnings']), 'other shops of this PrestaShop still use it') && $GLOBALS['logger']->has('Replaced paired key left: other shops still use it'), "multistore: {$who}: the merchant is told why the old key stays");
}

$s = multistore(new Scope(1, 1));
$s->settings()->setSecretKey('sandbox', key_for('sandbox', 'SHOP1OLD'));
$s->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint(key_for('sandbox', 'SHOP1OLD')), 'home' => 'boutique.cm']);
$s->pairing()->poll(EMPLOYEE, CODE);
$revokes = array_values(array_filter($GLOBALS['http']->sent, static fn (array $r): bool => $r['method'] === 'DELETE'));
check(count($revokes) === 1 && $revokes[0]['headers']['Authorization'] === key_for('sandbox', 'SHOP1OLD'), 'multistore: a shop that connected on its own before revokes its own old key, which no other shop uses');

$s = multistore(Scope::allShops());
services([], new Scope(2, 1), true)->settings()->setSecretKey('sandbox', OLD_PAIRED_KEY);
$s->pairing()->poll(EMPLOYEE, CODE);
check($deletes() === 0, 'multistore: all shops connecting again keep the old key while a shop saved it as its own');
$s = multistore(Scope::allShops());
$s->pairing()->poll(EMPLOYEE, CODE);
check($deletes() === 1 && services([], new Scope(2, 1), true)->settings()->secretKey('sandbox') === SANDBOX_KEY, 'multistore: but revoke it when no shop saved it: every shop now reads the new key');

// A shop that connects on its own in Sandbox while all shops take real
// payments: its environment is its own from then on, and it never pays with
// the live key of all shops, which may be another business's.
$s = multistore(new Scope(2, 1));
services([], Scope::allShops(), true)->settings()->setSecretKey('live', key_for('live', 'ALLSHOPS'));
services([], Scope::allShops(), true)->settings()->setEnvironment('live');
$summary = $s->pairing()->poll(EMPLOYEE, CODE);
$two = services([], new Scope(2, 1), true);
check($two->settings()->savedFor('WAJUB_ENVIRONMENT') === 's2' && $two->environment()->activeKey() === '' && $two->environment()->liveKeyMissing(), 'multistore: a shop paired in Sandbox while it read Live keeps Live for itself, without the live key of all shops: it takes no payment');
check(in_array('Live is selected and this shop has no live key of its own, so it takes no payments: connect Live as well to take real payments, or switch to Sandbox to make test payments.', $summary['warnings'], true), 'multistore: and the summary says so: ' . json_encode($summary['warnings']));
services([], Scope::allShops(), true)->settings()->setEnvironment('sandbox');
check(services([], new Scope(2, 1), true)->environment()->inUse() === 'live', 'multistore: all shops switched back to Sandbox: the shop stays where it was');

// === Cancel ====================================================================

$s = begin();
$GLOBALS['http']->reset();
check(ajax($s, 'pairing_cancel', ['code' => CODE])['body']['data'] === ['status' => 'cancelled'] && session() === null && $GLOBALS['http']->sent === [], 'cancel: forgets the session; the Dashboard has no cancel, and is not asked');
$s = begin();
$result = ajax($s, 'pairing_cancel', ['code' => 'ZZZZ-ZZZZ'])['body']['data'];
check($result['status'] === 'replaced' && session() !== null, 'cancel, replaced in another tab: in the words a poll gives, and the new session is left to it');
check(ajax($s, 'pairing_cancel', [])['body']['data']['status'] === 'cancelled' && session() === null, 'cancel from a page that names no code: forgotten');

// === Other actions =============================================================

$s = services();
check(ajax($s, 'nope')['status'] === 400, 'an unknown action is refused');
$GLOBALS['http']->routes['GET ' . API . '/'] = [hello('sandbox')];
$s->settings()->setSecretKey('sandbox', SANDBOX_KEY);
$answer = ajax($s, 'test_connection');
check($answer['body']['success'] && $answer['body']['data']['status'] === 'connected' && $answer['body']['data']['tone'] === 'success' && str_contains($answer['body']['data']['label'], 'Boutique Douala'), 'test_connection: asks Wajub now, and answers in words');
$s = services();
check(ajax($s, 'register_webhooks')['status'] === 400, 'register_webhooks: refused without a key, which registers it');

done('pairing');

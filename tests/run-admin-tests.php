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
 * The settings page (Admin\FormHandler, Admin\ConfigurationView,
 * Connection\ConnectionStatus): what each tab's form saves from what it
 * posts, read as the type it must be; what the page shows, never a key or a
 * signing secret, and the HTTPS warning of Mobile Money and Card in Live;
 * the connection's status, read without calling Wajub while a page loads;
 * and the back office's notices (Admin\Notices), each on the pages where
 * it belongs, hidden a week by the employee who hides it.
 *
 *     php tests/run-admin-tests.php
 */

require_once __DIR__ . '/bootstrap.php';

use Wajub\PrestaShop\Admin\ConfigurationView;
use Wajub\PrestaShop\Admin\FormHandler;
use Wajub\PrestaShop\Admin\Notices;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;

const API = 'https://api.wajub.com';

$languages = [
    ['id_lang' => 1, 'name' => 'English (English)', 'iso_code' => 'en'],
    ['id_lang' => 2, 'name' => 'Français (French)', 'iso_code' => 'fr'],
];
$sandbox = key_for('sandbox', 'S');
$live = key_for('live', 'L');

function save(Services $s, string $form, array $posted): array
{
    return (new FormHandler($s))->handle($form, $posted, [1, 2]);
}

function texts(array $notices): string
{
    return implode(' | ', array_map(static fn (array $n): string => $n['type'] . ': ' . $n['text'], $notices));
}

// === Connection form ===========================================================

$s = services();
$GLOBALS['http']->routes['GET ' . API . '/'] = [[200, ['business' => 'Boutique', 'env' => 'production']]];
$notices = save($s, 'connection', ['environment' => 'live', 'live_key' => $live, 'debug_log' => '1']);
check($s->settings()->secretKey('live') === $live && $s->settings()->environment() === 'live', 'connection: a live key pasted and Live chosen in one save: the key is saved first, so Live is allowed: ' . texts($notices));
check($s->settings()->debugLog(), 'connection: the debug log is turned on');
check(count($notices) === 2 && $notices[0]['type'] === 'success' && str_contains($notices[1]['text'], 'Live is on'), 'connection: both said: ' . texts($notices));

$s = services();
$notices = save($s, 'connection', ['environment' => 'live']);
check($s->settings()->environment() === 'sandbox' && $notices[0]['type'] === 'error', 'connection: Live without a live key is refused');

$s = services();
$s->settings()->setSecretKey('live', $live);
$s->settings()->setEnvironment('live');
$notices = save($s, 'connection', ['environment' => 'sandbox', 'remove_live_key' => '1']);
check($s->settings()->secretKey('live') === '' && $s->settings()->environment() === 'sandbox', 'connection: the live key removed in the save that chooses Sandbox: the merchant wants no payment: ' . texts($notices));

$s = services();
$s->settings()->setSecretKey('sandbox', $sandbox);
$notices = save($s, 'connection', ['environment' => 'sandbox', 'sandbox_key' => '', 'debug_log' => ['1']]);
check($s->settings()->secretKey('sandbox') === $sandbox && $notices[0]['text'] === 'Settings saved.' && $GLOBALS['http']->sent === [], 'connection: an empty key field keeps the saved key, and asks nothing');
check(!$s->settings()->debugLog(), 'connection: a value of the wrong type reads as unticked');
save($s, 'connection', ['environment' => 'production']);
check($s->settings()->environment() === 'sandbox', 'connection: an environment that does not exist changes nothing');

// === Payment methods form ======================================================

$s = services();
$notices = save($s, 'methods', [
    'mobile_money_enabled' => '1',
    'mobile_money_title' => [1 => '  Mobile <b>Money</b> ', 2 => 'Paiement mobile', 99 => 'Ignored'],
    'mobile_money_description' => [1 => "Pay on your phone.\nNo fee.", 2 => ''],
    'mobile_money_logos' => ['orange', 'visa', 'mtn'],
    'mobile_money_operator' => 'detect',
    'card_logos' => 'visa',
    'other_enabled' => '1',
    'other_no_description' => '1',
    'other_description' => [1 => 'Ignored when hidden'],
]);
$settings = $s->settings();
check($notices[0]['type'] === 'success', 'methods: saved, and said');
check($settings->methodEnabled(Methods::MOBILE_MONEY) && !$settings->methodEnabled(Methods::CARD) && $settings->methodEnabled(Methods::OTHER), 'methods: a box left unticked turns its method off');
check($settings->methodTitle(Methods::MOBILE_MONEY, 1) === 'Mobile Money' && $settings->methodTitle(Methods::MOBILE_MONEY, 2) === 'Paiement mobile', 'methods: titles by language, plain text');
check($settings->methodTitle(Methods::MOBILE_MONEY, 99) === '', 'methods: only the shop\'s languages are saved');
check($settings->methodDescription(Methods::MOBILE_MONEY, 1) === "Pay on your phone.\nNo fee." && $settings->methodDescription(Methods::MOBILE_MONEY, 2) === null, 'methods: a description with its line breaks; an empty one is the default');
check($settings->methodDescription(Methods::OTHER, 1) === '' && $settings->methodDescription(Methods::OTHER, 2) === '', 'methods: "Show no description" clears it in every language');
check($settings->methodLogos(Methods::MOBILE_MONEY) === ['orange', 'mtn'] && $settings->methodLogos(Methods::CARD) === [], 'methods: the logos of the method\'s kind; none ticked is none');
check($settings->operatorFromNumber(), 'methods: the operator found from the number');
save($s, 'methods', ['mobile_money_operator' => 'list']);
check(!$settings->operatorFromNumber(), 'methods: or chosen from the list');

// === Appearance form ===========================================================

$s = services();
save($s, 'appearance', ['form_theme' => 'dark']);
check($s->settings()->formTheme() === 'dark' && !$s->settings()->securedLine(), 'appearance: the dark form, and no "Secured by Wajub"');
save($s, 'appearance', ['form_theme' => 'neon', 'secured_line' => '1']);
check($s->settings()->formTheme() === 'system' && $s->settings()->securedLine(), 'appearance: a theme that does not exist is the shop\'s look');

// === Webhooks form =============================================================

$s = services();
$notices = save($s, 'webhooks', ['sandbox_secret' => ' whsec_test_pasted ', 'live_secret' => 'whsec_test_wrongfield']);
check($s->settings()->webhookSecret('sandbox') === 'whsec_test_pasted', 'webhooks: a sandbox signing secret pasted is saved');
check($s->settings()->webhookSecret('live') === '' && str_contains(texts($notices), 'Live signing secret not saved.'), 'webhooks: one of the other environment is refused, and said');
$notices = save($s, 'webhooks', ['remove_sandbox_secret' => '1', 'sandbox_secret' => 'whsec_test_ignored']);
check($s->settings()->webhookSecret('sandbox') === '' && $notices[0]['type'] === 'warning', 'webhooks: a secret removed is said to stop the deliveries');
check(save($s, 'webhooks', [])[0]['type'] === 'info', 'webhooks: nothing pasted: nothing to save');
check(save($s, 'nonsense', ['x' => 1]) === [], 'a form that does not exist saves nothing');

// === What the page shows =======================================================

$s = services();
$s->settings()->setSecretKey('sandbox', $sandbox);
$s->settings()->setWebhookSecret('sandbox', 'whsec_test_visibleonlymasked');
$s->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint($sandbox), 'at' => time(), 'business' => 'Boutique', 'key_type' => 'restricted', 'permissions' => ['payment.read'], 'expires_at' => 0, 'home' => 'boutique.cm']);
$s->settings()->setWebhookEndpoint('sandbox', ['id' => 'we_1', 'url' => 'https://boutique.cm/module/wajub/webhook', 'at' => time()]);
$GLOBALS['http']->reset();
$view = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($GLOBALS['http']->sent === [], 'the page calls Wajub for nothing while it loads');
check(!leaked($sandbox, $view) && !leaked('whsec_test_visibleonlymasked', $view), 'the page holds no key and no signing secret');
check($view['environments']['sandbox']['masked_key'] === Keys::mask($sandbox) && $view['webhook']['environments']['sandbox']['masked_secret'] === Keys::mask('whsec_test_visibleonlymasked'), 'only their masks');
check($view['environments']['sandbox']['key_type'] === 'restricted' && $view['environments']['sandbox']['permissions'] === 'payment.read' && $view['environments']['sandbox']['business'] === 'Boutique', 'the key\'s type, permissions and business, as the pairing gave them');
check(str_contains($view['environments']['sandbox']['expiry'], 'does not expire'), 'and its expiry');
check($view['connection']['page_status'] === 'unknown' && $view['connection']['can_test'], 'a key nobody tested yet: the page asks once, after it loads');
check($view['environment'] === 'sandbox' && !$view['is_live'] && !$view['sandbox_key_missing'], 'the environment in use');
check($view['webhook']['url'] === 'https://boutique.cm/module/wajub/webhook' && $view['webhook']['environments']['sandbox']['endpoint_id'] === 'we_1' && $view['webhook']['state'] === 'never', 'the webhook: its address, its registration, and no delivery yet');
check($view['webhook']['environments']['live']['has_key'] === false, 'an environment without a key says so');
check($view['webhook']['local'] === false, 'an address Wajub can reach: the steps in the Wajub Dashboard');
$GLOBALS['shop']->webhookUrl = 'http://localhost:8082/en/module/wajub/webhook';
$local = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($local['webhook']['local'] === true && $local['webhook']['listen_command'] === 'wajub listen --forward-to http://localhost:8082/en/module/wajub/webhook', 'a local address: the Wajub CLI\'s command for it, instead of an endpoint the Dashboard would refuse');
$GLOBALS['shop']->webhookUrl = 'https://boutique.cm/module/wajub/webhook';

$mm = $view['methods'][0];
check(array_column($view['methods'], 'kind') === ['mobile_money', 'card', 'other'], 'the three methods, in the checkout\'s order');
check($mm['default_title'] === 'Mobile Money' && $mm['texts'][1]['iso_code'] === 'fr' && $mm['texts'][0]['title'] === '', 'each with its default title, and a field per language');
check(array_column(array_filter($mm['logos'], static fn (array $l): bool => $l['checked']), 'key') === ['mtn', 'orange'], 'logos never chosen show the defaults of the shop\'s currency');
check($view['methods'][2]['logos'] !== [] && array_filter($view['methods'][2]['logos'], static fn (array $l): bool => $l['checked']) === [], 'the other methods\' logos are offered, none ticked');
check($mm['no_description'] === false, 'a description never saved is not "no description"');

$s->settings()->setSecretKey('live', $live);
$s->settings()->setSecretKey('sandbox', '');
$view = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($view['sandbox_key_missing'], 'Sandbox with only a live key: the page says no payment is taken');
check($view['connection']['tone'] === 'warning' && str_contains($view['connection']['detail'], 'Sandbox has no key'), 'and the connection says what to do: ' . $view['connection']['detail']);

// === Connection status =========================================================

$s = services();
$status = $s->connectionStatus();
check($status->cached()['status'] === 'not_configured', 'no key: not configured');
$s->settings()->setSecretKey('sandbox', $sandbox);
check($status->cached()['status'] === 'unknown' && $status->lastKnown()['status'] === 'unknown', 'a key nobody checked: unknown');
$GLOBALS['http']->routes['GET ' . API . '/'] = [[200, ['business' => 'Boutique', 'env' => 'sandbox']]];
$state = $status->refresh();
check($state['status'] === 'connected' && $state['business'] === 'Boutique', 'refresh(): asks Wajub with the key in use');
check(($GLOBALS['http']->sent[0]['headers']['Authorization'] ?? '') === $sandbox, 'with the key of the environment in use');
check($status->describe($state)['label'] === 'Connected to Boutique' && $status->describe($state)['tone'] === 'success', 'described in words');
$GLOBALS['store']->now = time() + 601;
check($status->cached()['status'] === 'unknown', 'the answer is current ten minutes');
$last = $status->lastKnown();
check($last['status'] === 'connected' && $last['stale'] === true && str_starts_with($status->describe($last)['detail'], 'Last checked '), 'then shown with its age, never taken for a check: ' . $status->describe($last)['detail']);
$s->settings()->setSecretKey('sandbox', key_for('sandbox', 'NEW'));
check($status->lastKnown()['status'] === 'unknown', 'a new key is never shown the old key\'s answer');
$GLOBALS['store']->now = 0;

$s = services();
$s->settings()->setSecretKey('sandbox', $sandbox);
$s->settings()->setKeyOrigin('sandbox', ['via' => 'pairing', 'fp' => Keys::fingerprint($sandbox), 'expires_at' => time() - 86400, 'home' => 'boutique.cm']);
$GLOBALS['http']->routes['GET ' . API . '/'] = [[401, ['message' => 'Unauthenticated']]];
$state = $s->connectionStatus()->refresh();
check($state['status'] === 'error' && str_contains($state['message'], 'expired on') && str_contains($state['message'], 'Connect with Wajub again'), 'a paired key refused past its date: the merchant connects again: ' . $state['message']);
$GLOBALS['http']->routes['GET ' . API . '/'] = [network_down()];
check(str_contains($s->connectionStatus()->refresh()['message'], 'could not be reached'), 'Wajub out of reach is said as such');
$GLOBALS['http']->routes['GET ' . API . '/'] = [[200, ['business' => 'Boutique', 'env' => 'sandbox']]];
$s->connectionStatus()->refresh();
check(!array_key_exists('expires_at', (array) $s->settings()->keyOrigin('sandbox')), 'a paired key Wajub accepts past the date kept for it: that date was wrong, and is forgotten');

// === The HTTPS warning of Mobile Money and Card ================================

$s = services();
$s->settings()->setSecretKey('sandbox', $sandbox);
$GLOBALS['shop']->https = false;
$GLOBALS['shop']->production = false;
$view = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($view['plain_http'] === '' && str_contains($view['plain_http_if_live'], 'not served over HTTPS'), 'a copy of the shop in Sandbox over http: nothing on the page, but said when Live is chosen: ' . $view['plain_http_if_live']);
check(str_contains($view['plain_http_if_live'], 'Shop Parameters > General, enable SSL, and SSL on all pages, before'), 'with where SSL is turned on, on PrestaShop 8 with SSL on all pages');
$GLOBALS['shop']->sslOnAllPages = false;
$said = (new ConfigurationView($s))->build(7, $languages, 'XAF', '')['plain_http_if_live'];
check(str_contains($said, 'Shop Parameters > General, enable SSL before') && !str_contains($said, 'all pages'), 'PrestaShop 9 has no "SSL on all pages": only SSL is asked for: ' . $said);
$GLOBALS['shop']->sslOnAllPages = true;
$GLOBALS['shop']->production = true;
check((new ConfigurationView($s))->build(7, $languages, 'XAF', '')['plain_http'] !== '', 'a shop the public uses, still in Sandbox: said before it goes live');
$GLOBALS['shop']->production = false;
$s->settings()->setSecretKey('live', $live);
$s->settings()->setEnvironment('live');
check((new ConfigurationView($s))->build(7, $languages, 'XAF', '')['plain_http'] !== '', 'in Live: said');
$s->settings()->setMethodEnabled(Methods::MOBILE_MONEY, false);
$s->settings()->setMethodEnabled(Methods::CARD, false);
$view = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($view['plain_http'] === '' && $view['plain_http_if_live'] === '', 'only Mobile Money and Card send payers away: Other payment methods alone says nothing');
$s->settings()->setMethodEnabled(Methods::CARD, true);
$GLOBALS['shop']->https = true;
$view = (new ConfigurationView($s))->build(7, $languages, 'XAF', '');
check($view['plain_http'] === '' && $view['plain_http_if_live'] === '', 'a shop over HTTPS: nothing to say');

$GLOBALS['taskLog']->last = 0;
check($view['task_last_run'] === 'It has not run yet.', 'the scheduled task that never ran, under its address');
$GLOBALS['taskLog']->last = time() - 7200;
check((new ConfigurationView($s))->build(7, $languages, 'XAF', '')['task_last_run'] === 'Last run: 2 hours ago.', 'and when it last ran');

// === The back office's notices =================================================

$never = static fn (): bool => false;
$ids = static fn (array $notices): string => implode(',', array_column($notices, 'id'));
$now = time();

$s = services();
$notices = new Notices($s);
check($ids($notices->due('AdminDashboard', 7, $never, $now)) === 'setup' && $ids($notices->due('AdminModulesSf', 7, $never, $now)) === 'setup', 'no key: "Finish setting up" on the dashboard and the module manager');
$setup = $notices->due('AdminDashboard', 7, $never, $now)[0];
check($setup['type'] === 'info' && $setup['hideable'] && $setup['action'] === 'connection' && $setup['label'] === 'Set up Wajub' && $setup['title'] === 'Finish setting up Wajub.', 'an information, which can be hidden, with the way to set it up: ' . json_encode($setup));
check($notices->due('AdminOrders', 7, $never, $now) === [] && $notices->due(Notices::SETTINGS_PAGE, 7, $never, $now) === [], 'not on the orders, nor on the module\'s page, where it is set up');
check(!$notices->sandboxBadge() && $GLOBALS['taskLog']->since === 0, 'no key: no "Wajub · Sandbox", and the scheduled task not waited for');

$s->settings()->setSecretKey('live', $live);
foreach (['AdminCarriers', 'AdminDashboard', Notices::SETTINGS_PAGE] as $page) {
    $due = $notices->due($page, 7, $never, $now);
    check($ids($due) === 'no_payments' && $due[0]['type'] === 'error' && !$due[0]['hideable'] && str_contains($due[0]['text'], 'Sandbox is selected and has no key'), 'Sandbox without its key, a live key saved: "Wajub takes no payments" on every page (' . $page . '), and nothing else, never hidden');
}
check($notices->due('AdminDashboard', 7, $never, $now)[0]['label'] === 'Add a sandbox key' && !$notices->sandboxBadge(), 'with the way to fix it; no payment, no badge');
$s->settings()->setEnvironment('live');
$s->settings()->setSecretKey('live', '');
$s->settings()->setSecretKey('sandbox', $sandbox);
$due = $notices->due('AdminCarriers', 7, $never, $now);
check($ids($due) === 'no_payments' && str_contains($due[0]['text'], 'Live is selected and has no key') && $due[0]['label'] === 'Add a live key', 'Live without its key: the same, for Live');
check(!$notices->hide(7, 'no_payments', $now), 'it cannot be hidden');

$s->settings()->setEnvironment('sandbox');
check($notices->sandboxBadge(), 'Sandbox with its key: "Wajub · Sandbox"');
$GLOBALS['shop']->production = true;
$due = $notices->due('AdminOrders', 7, $never, $now);
check($ids($due) === 'sandbox_live_shop' && $due[0]['type'] === 'warning' && $due[0]['hideable'] && $due[0]['label'] === 'Go live', 'a shop the public uses (debug mode off) in Sandbox: said on the orders');
check($ids($notices->due(Notices::SETTINGS_PAGE, 7, $never, $now)) === 'sandbox_live_shop' && $notices->due('AdminCarriers', 7, $never, $now) === [], 'and on the module\'s page, not on a page about something else');
$GLOBALS['shop']->production = false;
check($notices->due('AdminOrders', 7, $never, $now) === [], 'a copy in debug mode: Sandbox is right there');
check($GLOBALS['taskLog']->since === $now, 'the scheduled task is waited for from the first page that asks');

// Hidden a week, by the employee who hides it.
$GLOBALS['shop']->production = true;
check($notices->hide(7, 'sandbox_live_shop', $now), 'hidden');
check($notices->due('AdminOrders', 7, $never, $now) === [] && $ids($notices->due('AdminOrders', 8, $never, $now)) === 'sandbox_live_shop', 'for that employee only');
$GLOBALS['taskLog']->last = $now + Notices::HIDE_FOR;
check($notices->due('AdminOrders', 7, $never, $now + Notices::HIDE_FOR - 60) === [] && $ids($notices->due('AdminOrders', 7, $never, $now + Notices::HIDE_FOR + 1)) === 'sandbox_live_shop', 'for a week, then back: what it warns about has not changed');
$GLOBALS['taskLog']->last = 0;
check(!$notices->hide(0, 'setup', $now) && !$notices->hide(7, 'nonsense', $now), 'nobody, or a notice that does not exist: nothing hidden');
$GLOBALS['store']->set('notices_hidden:9', ['sandbox_live_shop' => 'soon', 'no_payments' => $now + 999, 'setup' => $now - 1]);
check($ids($notices->due('AdminOrders', 9, $never, $now)) === 'sandbox_live_shop', 'what was stored and is not a time to come hides nothing');
$GLOBALS['shop']->production = false;

// The scheduled task.
$GLOBALS['taskLog']->since = $now - Notices::TASK_LATE_AFTER - 1;
$due = $notices->due('AdminDashboard', 7, $never, $now);
check($ids($due) === 'task' && $due[0]['title'] === 'Wajub’s scheduled task has never run.' && $due[0]['type'] === 'warning' && $due[0]['hideable'], 'three hours after the module waits for it, the scheduled task never ran: said');
check($due[0]['copy'] === $GLOBALS['shop']->cronUrl($s->tokens()->cron()) && str_contains($due[0]['copy'], 'token=') && str_contains($due[0]['text'], 'Install PrestaShop’s Cron tasks manager module'), 'with its address and its token, to copy, and the two ways to run it');
$due = $notices->due('AdminDashboard', 7, static fn (): bool => true, $now);
check(str_contains($due[0]['text'], 'is installed but has not run it'), 'the Cron tasks manager installed: its own task is to check');
check($notices->due('AdminCarriers', 7, $never, $now) === [], 'said where payments are looked after, not everywhere');
$GLOBALS['taskLog']->last = $now - 3600;
check($notices->due('AdminDashboard', 7, $never, $now) === [], 'ran an hour ago: nothing to say');
$GLOBALS['taskLog']->last = $now - 4 * 3600;
$due = $notices->due('AdminDashboard', 7, $never, $now);
check($ids($due) === 'task' && $due[0]['title'] === 'Wajub’s scheduled task last ran 4 hours ago.', 'four hours since: late, and when it last ran');
$cronAsked = 0;
$notices->due('AdminDashboard', 7, static function () use (&$cronAsked): bool {
    ++$cronAsked;

    return false;
}, $now - 3 * 3600);
check($cronAsked === 0, 'whether the Cron tasks manager is on is asked only when the task is late');
$GLOBALS['taskLog']->last = $now;

// Not offered at checkout.
foreach (Methods::KINDS as $kind) {
    $s->settings()->setMethodEnabled($kind, false);
}
$due = $notices->due(Notices::SETTINGS_PAGE, 7, $never, $now);
check($ids($due) === 'not_offered' && $due[0]['action'] === 'methods' && $due[0]['type'] === 'info' && str_contains($due[0]['text'], 'all turned off'), 'every method off: "not offered at checkout", with the Payment methods tab');
check($ids($notices->due('AdminPayment', 7, $never, $now)) === 'not_offered' && $notices->due('AdminDashboard', 7, $never, $now) === [], 'on the module\'s page and Payment\'s, not the dashboard');
$s->settings()->setMethodEnabled(Methods::OTHER, true);
check($notices->due(Notices::SETTINGS_PAGE, 7, $never, $now) === [], 'one method on: offered');
$GLOBALS['shop']->emptyPreferences = ['currencies', 'carriers'];
$due = $notices->due('AdminPaymentPreferences', 7, $never, $now);
check($ids($due) === 'not_offered' && $due[0]['action'] === 'preferences' && str_contains($due[0]['text'], 'under Currency restrictions, Carrier restrictions in Payment > Preferences'), 'nothing ticked for the module in Payment > Preferences: said, with the lists: ' . ($due[0]['text'] ?? 'nothing said'));
$GLOBALS['shop']->emptyPreferences = [];

// Most serious first, and never a call to Wajub.
$s->settings()->setSecretKey('live', $live);
$s->settings()->setSecretKey('sandbox', '');
$GLOBALS['taskLog']->last = 0;
$GLOBALS['taskLog']->since = $now - Notices::TASK_LATE_AFTER - 1;
check($ids($notices->due('AdminDashboard', 7, $never, $now)) === 'no_payments', 'a shop that takes no payment hears that alone, not about a task it has no use for');
check($GLOBALS['http']->sent === [], 'no notice asks Wajub anything');

done('admin');

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
 * signing secret; and the connection's status, read without calling Wajub
 * while a page loads.
 *
 *     php tests/run-admin-tests.php
 */

require_once __DIR__ . '/bootstrap.php';

use Wajub\PrestaShop\Admin\ConfigurationView;
use Wajub\PrestaShop\Admin\FormHandler;
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

done('admin');

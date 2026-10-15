<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Admin;

use Wajub\PrestaShop\Pairing\PairingClient;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Ago;
use Wajub\PrestaShop\Webhook\WebhookHealth;
use Wajub\PrestaShop\Webhook\WebhookRegistration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the settings page shows, read from the settings without calling
 * Wajub: every value the template prints, already in words, and never a
 * key, a signing secret or a polling token (a saved one shows masked).
 */
final class ConfigurationView
{
    public const TABS = ['connection', 'methods', 'appearance', 'webhooks'];

    /** @var Services */
    private $services;

    public function __construct(Services $services)
    {
        $this->services = $services;
    }

    /**
     * @param list<array{id_lang: int, name: string, iso_code: string}> $languages the shop's languages, the employee's first
     * @param string $currency the shop's default currency, for the logos shown until the merchant chooses
     * @param string $scopeLabel what the shop selector says: the shop, group, or all shops the settings are for
     * @param array<string, string> $scopeNames in a multistore, the names of the scopes above this one, by slug ('all', 'g<id>'); empty otherwise
     * @param array<string, string> $namesInText the same, as a sentence names them ("all shops", "the group Default"); the names when not given
     *
     * @return array<string, mixed>
     */
    public function build(int $employeeId, array $languages, string $currency, string $scopeLabel, array $scopeNames = [], array $namesInText = []): array
    {
        $namesInText += $scopeNames;
        $t = $this->services->translator();
        $settings = $this->services->settings();
        $environment = $this->services->environment();
        $pairing = $this->services->pairing();
        $status = $this->services->connectionStatus();

        $state = $status->lastKnown();
        $described = $status->describe($state);

        $sources = $settings->connectionSources();
        $environments = [];
        foreach (Settings::ENVIRONMENTS as $each) {
            $origin = $this->services->keyOrigins()->pairedKey($each);
            $key = $settings->secretKey($each);
            $environments[$each] = $pairing->environmentState($each) + [
                'masked_key' => Keys::mask($key),
                'key_unreadable' => $settings->secretKeyUnreadable($each),
                'expected_prefix' => Keys::expectedPrefix($each),
                'key_type' => $key === '' ? '' : ($origin !== null && $origin['key_type'] !== '' ? $origin['key_type'] : Keys::typeOf($key)),
                'permissions' => $origin !== null && is_array($origin['permissions']) ? implode(', ', $origin['permissions']) : '',
                'business' => $origin !== null ? $origin['business'] : '',
                'saved_for' => $key !== '' ? $this->scopeName($sources['keys'][$each], $scopeNames) : '',
            ];
        }

        return [
            'scope_label' => $scopeLabel,
            'scope_all' => $settings->scope()->isAllShops(),
            'multistore' => $this->multistore($scopeNames, $sources, $namesInText),
            'environment' => $settings->environment(),
            'environment_label' => $pairing->environmentLabel($settings->environment()),
            'is_live' => $environment->isLive(),
            'sandbox_key_missing' => $environment->sandboxKeyMissing(),
            'live_key_missing' => $environment->liveKeyMissing(),
            'connection' => $described + [
                // Asked once by the page, after it loads, when nothing at all
                // is known about the key in use; never while it loads.
                'page_status' => !empty($state['stale']) ? 'stale' : $state['status'],
                'can_test' => $state['status'] !== 'not_configured',
            ],
            'environments' => $environments,
            'pairing_pending' => $pairing->pending($employeeId),
            'no_expiry_advice' => $t->trans('When you approve in Wajub, choose “No expiry” for a shop whose checkout must not stop. Otherwise, the key lasts 90 days and you connect again.', [], 'Modules.Wajub.Admin'),
            'dashboard_url' => PairingClient::DASHBOARD,
            'api_keys_url' => PairingClient::DASHBOARD . '/settings/developer/keys',
            'webhooks_url' => PairingClient::DASHBOARD . '/settings/developer/webhooks/endpoints',
            'debug_log' => $settings->debugLog(),
            'methods' => $this->methods($languages, $currency),
            'operator_from_number' => $settings->operatorFromNumber(),
            'cancel_after' => $settings->cancelAfter(),
            'cancel_after_max' => Settings::CANCEL_AFTER_MAX,
            'cron_url' => $this->services->shop()->cronUrl($this->services->tokens()->cron()),
            'form_theme' => $settings->formTheme(),
            'secured_line' => $settings->securedLine(),
            'webhook' => $this->webhook($scopeNames, $sources, $namesInText),
            'languages' => $languages,
            // Each field says its language only when there are several.
            'multilingual' => count($languages) > 1,
        ];
    }

    /**
     * @param list<array{id_lang: int, name: string, iso_code: string}> $languages
     *
     * @return list<array<string, mixed>>
     */
    private function methods(array $languages, string $currency): array
    {
        $t = $this->services->translator();
        $settings = $this->services->settings();
        $methods = [];
        foreach (Methods::KINDS as $kind) {
            $texts = [];
            $noDescription = true;
            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                $description = $settings->methodDescription($kind, $idLang);
                if ($description !== '') {
                    $noDescription = false;
                }
                $texts[] = [
                    'id_lang' => $idLang,
                    'language' => $language['name'],
                    'iso_code' => $language['iso_code'],
                    'title' => $settings->methodTitle($kind, $idLang),
                    'description' => (string) $description,
                ];
            }

            $chosen = $settings->methodLogos($kind) ?? Methods::defaultLogos($kind, $currency);
            $logos = [];
            foreach (Methods::logoChoices($kind) as $key) {
                $logos[] = [
                    'key' => $key,
                    'name' => Methods::logoName($key),
                    'file' => Methods::logoFile($key),
                    'width' => Methods::logoWidth($key),
                    'plain' => Methods::logoIsPlain($key),
                    'checked' => in_array($key, $chosen, true),
                ];
            }

            switch ($kind) {
                case Methods::MOBILE_MONEY:
                    $name = $t->trans('Mobile Money', [], 'Modules.Wajub.Admin');
                    $help = $t->trans('Wajub’s Mobile Money form inside your checkout: customers enter their number and approve the payment on their phone, without leaving your shop.', [], 'Modules.Wajub.Admin');
                    break;
                case Methods::CARD:
                    $name = $t->trans('Card', [], 'Modules.Wajub.Admin');
                    $help = $t->trans('Wajub’s card form inside your checkout: customers pay by Visa or Mastercard without leaving your shop.', [], 'Modules.Wajub.Admin');
                    break;
                default:
                    $name = $t->trans('Other payment methods', [], 'Modules.Wajub.Admin');
                    $help = $t->trans('Every other way to pay your Wajub account offers (PayPal, wallets…), on a secure Wajub page. Shown at checkout only when your account offers one for the order.', [], 'Modules.Wajub.Admin');
            }

            $methods[] = [
                'kind' => $kind,
                'name' => $name,
                'help' => $help,
                'enabled' => $settings->methodEnabled($kind),
                'default_title' => MethodTexts::defaultTitle($kind, $t),
                'default_description' => MethodTexts::defaultDescription($kind, $t),
                'no_description' => $noDescription,
                'texts' => $texts,
                'logos' => $logos,
            ];
        }

        return $methods;
    }

    /**
     * Where this shop's (or group's) connection comes from, in a multistore:
     * its own, or that of the scope above it, which it uses again once it
     * forgets its own (FormHandler, `inherit`).
     *
     * @param array<string, string> $scopeNames
     * @param array{environment: string, keys: array<string, string>, secrets: array<string, string>, endpoints: array<string, string>} $sources
     * @param array<string, string> $namesInText
     *
     * @return array{on: bool, group: bool, own: bool, parent: string, parent_in_text: string, environment_saved_for: string}
     */
    private function multistore(array $scopeNames, array $sources, array $namesInText): array
    {
        $settings = $this->services->settings();
        $scope = $settings->scope();
        if ($scopeNames === [] || $scope->isAllShops()) {
            return ['on' => $scopeNames !== [], 'group' => false, 'own' => true, 'parent' => '', 'parent_in_text' => '', 'environment_saved_for' => ''];
        }

        // The scope above whose connection this one reads once it forgets
        // its own: its group's, when the group saved one, else all shops'.
        $parent = 'all';
        foreach (array_slice($scope->chain(), 1) as $slug) {
            foreach (Settings::connectionKeys() as $key) {
                if ($settings->isSavedFor($key, $slug)) {
                    $parent = $slug;
                    break 2;
                }
            }
        }

        return [
            'on' => true,
            'group' => $scope->isGroup(),
            'own' => $settings->hasOwnConnection(),
            'parent' => $scopeNames[$parent] ?? ($scopeNames['all'] ?? ''),
            // Inside a sentence: "the connection saved for all shops".
            'parent_in_text' => $namesInText[$parent] ?? ($namesInText['all'] ?? ''),
            'environment_saved_for' => $this->scopeName($sources['environment'], $scopeNames),
        ];
    }

    /**
     * What the page calls the scope a value is saved for: "this shop" (or
     * "this group"), a group, all shops; '' without multistore.
     *
     * @param array<string, string> $scopeNames
     */
    private function scopeName(string $slug, array $scopeNames): string
    {
        if ($scopeNames === [] || $slug === '') {
            return '';
        }
        $t = $this->services->translator();
        $scope = $this->services->settings()->scope();
        if ($slug === $scope->slug()) {
            if ($scope->isAllShops()) {
                return $scopeNames['all'] ?? '';
            }

            return $scope->isGroup() ? $t->trans('this group', [], 'Modules.Wajub.Admin') : $t->trans('this shop', [], 'Modules.Wajub.Admin');
        }

        return $scopeNames[$slug] ?? '';
    }

    /**
     * @param array<string, string> $scopeNames
     * @param array{environment: string, keys: array<string, string>, secrets: array<string, string>, endpoints: array<string, string>} $sources
     * @param array<string, string> $namesInText
     *
     * @return array<string, mixed>
     */
    private function webhook(array $scopeNames, array $sources, array $namesInText): array
    {
        $t = $this->services->translator();
        $settings = $this->services->settings();
        $registration = $this->services->webhookRegistration();
        $pairing = $this->services->pairing();
        $url = $this->services->shop()->webhookUrl();

        $environments = [];
        foreach (Settings::ENVIRONMENTS as $environment) {
            $endpoint = $settings->webhookEndpoint($environment);
            $environments[$environment] = [
                'environment' => $environment,
                'label' => $pairing->environmentLabel($environment),
                'has_key' => $settings->secretKey($environment) !== '',
                'masked_secret' => Keys::mask($settings->webhookSecret($environment)),
                'secret_unreadable' => $settings->webhookSecretUnreadable($environment),
                'expected_prefix' => $environment === Settings::SANDBOX ? 'whsec_test_' : 'whsec_',
                'endpoint_id' => $endpoint !== null ? $endpoint['id'] : '',
                'registered_on' => $endpoint !== null && $endpoint['at'] > 0 ? $t->date($endpoint['at']) : '',
                'moved' => $registration->moved($environment),
                'local_problem' => WebhookRegistration::localAddressProblem($url, $environment),
                'saved_for' => $settings->webhookSecret($environment) !== '' ? $this->scopeName($sources['secrets'][$environment], $scopeNames) : '',
            ];
        }

        // A shop of a multistore that uses the signing secret of the scope
        // above gets Wajub's deliveries through that scope's endpoint: its
        // health is that one's.
        $inUse = $this->services->environment()->isLive() ? Settings::LIVE : Settings::SANDBOX;
        $secretFrom = $sources['secrets'][$inUse];
        $own = $this->services->scope()->slug();
        $inherited = $scopeNames !== [] && $secretFrom !== '' && $secretFrom !== $own;
        $health = $this->services->webhookHealth($inherited ? $secretFrom : null)->state();
        $received = $health['received'];
        $rejected = $health['rejected'];

        return [
            'url' => $url,
            'environments' => $environments,
            'state' => $health['state'],
            'received' => $received !== null ? [
                'ago' => Ago::since($received['at'], $t),
                'event' => $received['event'],
                'environment' => $pairing->environmentLabel($received['environment']),
            ] : null,
            'rejected' => $health['state'] === 'rejected' && $rejected !== null ? [
                'ago' => Ago::since($rejected['at'], $t),
                'guidance' => WebhookHealth::guidance($rejected['reason'], $t),
            ] : null,
            'unverified' => $health['unverified'] !== null ? WebhookHealth::unverifiedNote($health['unverified'], $t) : '',
            'events' => implode(', ', WebhookRegistration::EVENTS),
            'inherited_from' => $inherited ? $this->scopeName($secretFrom, $scopeNames) : '',
            'inherited_in_text' => $inherited ? ($namesInText[$secretFrom] ?? $this->scopeName($secretFrom, $scopeNames)) : '',
        ];
    }
}

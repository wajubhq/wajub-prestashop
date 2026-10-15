<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Settings;

use Wajub\PrestaShop\Payment\Methods;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every setting of the module, typed, for one scope (a shop, a group of
 * shops, or all of them), in one place: what is saved under which key, and
 * what a setting nobody saved reads as.
 *
 * Secret keys and webhook signing secrets are kept encrypted (SecretCipher)
 * and are read back only to be sent to Wajub or to verify a delivery: no
 * page ever prints one. How a pairing gave a key (its origin) is kept beside
 * it, with the key's fingerprint, never the key.
 */
final class Settings
{
    public const SANDBOX = 'sandbox';

    public const LIVE = 'live';

    public const ENVIRONMENTS = [self::SANDBOX, self::LIVE];

    public const THEMES = ['system', 'light', 'dark'];

    public const ENVIRONMENT = 'WAJUB_ENVIRONMENT';

    public const DEBUG_LOG = 'WAJUB_DEBUG_LOG';

    public const FORM_THEME = 'WAJUB_FORM_THEME';

    public const SECURED_LINE = 'WAJUB_SECURED_LINE';

    public const OPERATOR_FROM_NUMBER = 'WAJUB_MM_OPERATOR_FROM_NUMBER';

    /** Minutes after which an unpaid order is cancelled (Order\Cleanup); 0 never. */
    public const CANCEL_AFTER = 'WAJUB_CANCEL_AFTER';

    /** What CANCEL_AFTER is until the merchant chooses: WooCommerce's stock hold. */
    public const CANCEL_AFTER_DEFAULT = 60;

    /** The longest delay the setting takes: 30 days. */
    public const CANCEL_AFTER_MAX = 43200;

    /** The order states the module created (Install\OrderStates): ids kept for all shops, as PrestaShop keeps its own. */
    public const STATE_AWAITING = 'WAJUB_OS_AWAITING';

    public const STATE_PARTIAL = 'WAJUB_OS_PARTIAL';

    /** @var array<string, string> environment => key of its secret key */
    private const SECRET_KEYS = [
        self::SANDBOX => 'WAJUB_SECRET_KEY_SANDBOX',
        self::LIVE => 'WAJUB_SECRET_KEY_LIVE',
    ];

    /** @var array<string, string> environment => key of its webhook signing secret */
    private const WEBHOOK_SECRETS = [
        self::SANDBOX => 'WAJUB_WEBHOOK_SECRET_SANDBOX',
        self::LIVE => 'WAJUB_WEBHOOK_SECRET_LIVE',
    ];

    /** @var array<string, string> environment => how a pairing gave its key (Pairing\Pairing::origin()) */
    private const KEY_ORIGINS = [
        self::SANDBOX => 'WAJUB_KEY_ORIGIN_SANDBOX',
        self::LIVE => 'WAJUB_KEY_ORIGIN_LIVE',
    ];

    /** @var array<string, string> environment => the webhook endpoint registered with Wajub */
    private const WEBHOOK_ENDPOINTS = [
        self::SANDBOX => 'WAJUB_WEBHOOK_ENDPOINT_SANDBOX',
        self::LIVE => 'WAJUB_WEBHOOK_ENDPOINT_LIVE',
    ];

    /** @var array<string, string> payment method kind => prefix of its keys */
    private const METHOD_PREFIXES = [
        Methods::MOBILE_MONEY => 'WAJUB_MM_',
        Methods::CARD => 'WAJUB_CARD_',
        Methods::OTHER => 'WAJUB_OTHER_',
    ];

    /** Longest title and description a merchant may give a method. */
    public const TITLE_MAX = 128;

    public const DESCRIPTION_MAX = 1000;

    /** @var ConfigStore */
    private $store;

    /** @var SecretCipher */
    private $cipher;

    public function __construct(ConfigStore $store, SecretCipher $cipher)
    {
        $this->store = $store;
        $this->cipher = $cipher;
    }

    public function scope(): Scope
    {
        return $this->store->scope();
    }

    /**
     * Every key the module saves, for uninstalling.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $keys = [self::ENVIRONMENT, self::DEBUG_LOG, self::FORM_THEME, self::SECURED_LINE, self::OPERATOR_FROM_NUMBER, self::CANCEL_AFTER, self::STATE_AWAITING, self::STATE_PARTIAL];
        foreach ([self::SECRET_KEYS, self::WEBHOOK_SECRETS, self::KEY_ORIGINS, self::WEBHOOK_ENDPOINTS] as $byEnvironment) {
            $keys = array_merge($keys, array_values($byEnvironment));
        }
        foreach (self::METHOD_PREFIXES as $prefix) {
            foreach (['ENABLED', 'TITLE', 'DESCRIPTION', 'LOGOS'] as $field) {
                $keys[] = $prefix . $field;
            }
        }

        return $keys;
    }

    /**
     * What a shop starts with: Sandbox, every method on (each shows only
     * once the account offers it), the payment form in the shop's colours,
     * and "Secured by Wajub" shown. Titles, descriptions and logos are left
     * unsaved: they read as the defaults of the page's language and the
     * shop's currency until the merchant writes their own.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::ENVIRONMENT => self::SANDBOX,
            self::DEBUG_LOG => '0',
            self::FORM_THEME => 'system',
            self::SECURED_LINE => '1',
            self::OPERATOR_FROM_NUMBER => '0',
            self::CANCEL_AFTER => (string) self::CANCEL_AFTER_DEFAULT,
            self::METHOD_PREFIXES[Methods::MOBILE_MONEY] . 'ENABLED' => '1',
            self::METHOD_PREFIXES[Methods::CARD] . 'ENABLED' => '1',
            self::METHOD_PREFIXES[Methods::OTHER] . 'ENABLED' => '1',
        ];
    }

    // --- Environment and keys ----------------------------------------------------

    /** 'sandbox' or 'live': the environment selected, Sandbox unless Live was chosen. */
    public function environment(): string
    {
        return $this->store->get(self::ENVIRONMENT) === self::LIVE ? self::LIVE : self::SANDBOX;
    }

    public function setEnvironment(string $environment): void
    {
        $this->store->set(self::ENVIRONMENT, $environment === self::LIVE ? self::LIVE : self::SANDBOX);
    }

    /**
     * The secret key of `environment`, or '' when none is saved, or the
     * saved one cannot be read.
     *
     * In a multistore, both keys come from one scope: the nearest of this
     * one, its group and all shops that saved a key itself (keysSavedFor()).
     * A shop that connected on its own, to another Wajub business perhaps,
     * never pays with the key of its other environment saved above it: it
     * has that key of its own, or none.
     */
    public function secretKey(string $environment): string
    {
        $stored = $this->keyLookup($environment)[1];

        return $stored === '' ? '' : trim((string) $this->cipher->decrypt($stored));
    }

    /**
     * Whether a key is saved for `environment` that this shop can no longer
     * read: its encryption key changed (a shop moved without its
     * parameters.php). The merchant connects again.
     */
    public function secretKeyUnreadable(string $environment): bool
    {
        $stored = $this->keyLookup($environment)[1];

        return $stored !== '' && $this->cipher->decrypt($stored) === null;
    }

    /**
     * '' removes the key.
     *
     * A shop or a group of a multistore that saves a key of its own keeps
     * its environment from then on, the one it reads now: its connection
     * no longer follows the scope above, which may switch to Live with the
     * live key of another business.
     */
    public function setSecretKey(string $environment, string $key): void
    {
        if (trim($key) !== '') {
            $this->keepEnvironment();
        }
        $this->setSecret(self::SECRET_KEYS[$environment] ?? '', $key);
    }

    /**
     * Saves the environment this shop or group reads for itself, when it
     * reads the one of the scope above: once it pays with a key of its own,
     * its environment is its own too. Nothing for all shops, nor for a
     * scope that saved its environment already.
     */
    public function keepEnvironment(): void
    {
        $scope = $this->scope();
        if ($scope->isAllShops() || $this->isSavedFor(self::ENVIRONMENT, $scope->slug())) {
            return;
        }
        $this->store->set(self::ENVIRONMENT, $this->environment());
    }

    /**
     * The scope whose keys this scope pays with, as a slug ('s2', 'g1',
     * 'all'): the nearest of its chain that saved a key itself, in either
     * environment; '' when none did. A key removed from a scope (an empty
     * value of its own) does not make it one: the shop still reads the keys
     * above, but for that environment.
     */
    public function keysSavedFor(): string
    {
        $saved = [];
        foreach (self::SECRET_KEYS as $name) {
            foreach ($this->store->savedAt($name) as $slug => $stored) {
                if ($stored !== '') {
                    $saved[$slug] = true;
                }
            }
        }
        foreach ($this->scope()->chain() as $slug) {
            if (isset($saved[$slug])) {
                return $slug;
            }
        }

        return '';
    }

    /**
     * The scope the key of `environment` this scope pays with is saved for,
     * as a slug: '' when it has none (keysSavedFor()).
     */
    public function keySavedFor(string $environment): string
    {
        return $this->keyLookup($environment)[0];
    }

    public function webhookSecret(string $environment): string
    {
        return $this->secret(self::WEBHOOK_SECRETS[$environment] ?? '');
    }

    public function webhookSecretUnreadable(string $environment): bool
    {
        return $this->unreadable(self::WEBHOOK_SECRETS[$environment] ?? '');
    }

    public function setWebhookSecret(string $environment, string $secret): void
    {
        $this->setSecret(self::WEBHOOK_SECRETS[$environment] ?? '', $secret);
    }

    /**
     * How a pairing gave the key of `environment`, as Pairing\Pairing kept
     * it, or null.
     *
     * @return array<string, mixed>|null
     */
    public function keyOrigin(string $environment): ?array
    {
        return $this->json(self::KEY_ORIGINS[$environment] ?? '');
    }

    /**
     * @param array<string, mixed>|null $origin null forgets it
     */
    public function setKeyOrigin(string $environment, ?array $origin): void
    {
        $this->setJson(self::KEY_ORIGINS[$environment] ?? '', $origin);
    }

    /**
     * The webhook endpoint registered for `environment`: ['id', 'url', 'at'],
     * or null.
     *
     * @return array{id: string, url: string, at: int}|null
     */
    public function webhookEndpoint(string $environment): ?array
    {
        return self::endpointFrom($this->json(self::WEBHOOK_ENDPOINTS[$environment] ?? ''));
    }

    /**
     * @param array{id: string, url: string, at: int}|null $endpoint
     */
    public function setWebhookEndpoint(string $environment, ?array $endpoint): void
    {
        $this->setJson(self::WEBHOOK_ENDPOINTS[$environment] ?? '', $endpoint);
    }

    // --- What a scope saved itself, in a multistore --------------------------------

    /**
     * Whether `key` is saved as the secret key of `environment` for any
     * shop, group or all shops. A key saved anywhere is one some shop still
     * pays with, its own or inherited: the key of all shops, which a shop
     * that connected on its own no longer reads, is still the others'.
     */
    public function secretKeySavedAnywhere(string $environment, string $key): bool
    {
        if ($key === '' || !isset(self::SECRET_KEYS[$environment])) {
            return false;
        }
        foreach ($this->store->savedAt(self::SECRET_KEYS[$environment]) as $stored) {
            if ($stored !== '' && hash_equals($key, trim((string) $this->cipher->decrypt($stored)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the signing secret of `environment` this scope reads is saved
     * for this scope itself. One it inherits from its group or all shops
     * belongs to the webhook address of the shop that saved it, which is
     * not this shop's.
     */
    public function webhookSecretSavedHere(string $environment): bool
    {
        return ($this->savedHere(self::WEBHOOK_SECRETS[$environment] ?? '') ?? '') !== '';
    }

    /**
     * The webhook endpoint saved for this scope itself, null when it has
     * none of its own (it may inherit one).
     *
     * @return array{id: string, url: string, at: int}|null
     */
    public function webhookEndpointSavedHere(string $environment): ?array
    {
        $stored = $this->savedHere(self::WEBHOOK_ENDPOINTS[$environment] ?? '');
        $data = $stored !== null && $stored !== '' ? json_decode($stored, true) : null;

        return self::endpointFrom(is_array($data) ? $data : null);
    }

    /**
     * The scope whose saved value of `key` this scope reads, as a slug
     * ('s2', 'g1', 'all'), its own first, as Configuration falls back: ''
     * when no scope saved one. An empty value saved for a shop counts: it
     * overrides the value above, as a key removed from that shop does.
     */
    public function savedFor(string $key): string
    {
        $saved = $this->store->savedAt($key);
        foreach ($this->scope()->chain() as $slug) {
            if (array_key_exists($slug, $saved)) {
                return $slug;
            }
        }

        return '';
    }

    /** Whether the scope `slug` ('s2', 'g1', 'all') saved a value of `key` itself. */
    public function isSavedFor(string $key, string $slug): bool
    {
        return array_key_exists($slug, $this->store->savedAt($key));
    }

    /**
     * Where each part of the connection this scope uses is saved: the
     * environment, each environment's key, signing secret and webhook
     * endpoint, by the slug of the scope that saved it ('' for none). What
     * the settings page tells a shop of a multistore: its own, or its
     * group's, or all shops'.
     *
     * @return array{environment: string, keys: array<string, string>, secrets: array<string, string>, endpoints: array<string, string>}
     */
    public function connectionSources(): array
    {
        $sources = ['environment' => $this->savedFor(self::ENVIRONMENT), 'keys' => [], 'secrets' => [], 'endpoints' => []];
        foreach (self::ENVIRONMENTS as $environment) {
            $sources['keys'][$environment] = $this->keySavedFor($environment);
            $sources['secrets'][$environment] = $this->savedFor(self::WEBHOOK_SECRETS[$environment]);
            $sources['endpoints'][$environment] = $this->savedFor(self::WEBHOOK_ENDPOINTS[$environment]);
        }

        return $sources;
    }

    /**
     * Whether this scope saved any part of the connection itself, rather
     * than reading all of it from the scopes above. All shops always has
     * its own.
     */
    public function hasOwnConnection(): bool
    {
        if ($this->scope()->isAllShops()) {
            return true;
        }
        foreach (self::connectionKeys() as $key) {
            if ($this->isSavedFor($key, $this->scope()->slug())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forgets the connection this scope saved itself (environment, keys and
     * how they came, signing secrets, webhook endpoints): it reads its
     * group's, or all shops', again. Nothing is deleted in Wajub. Not for
     * all shops, which has nothing above it.
     */
    public function forgetOwnConnection(): void
    {
        if ($this->scope()->isAllShops()) {
            return;
        }
        foreach (self::connectionKeys() as $key) {
            $this->store->forget($key);
        }
    }

    /**
     * The keys of the connection: what a shop of a multistore saves for
     * itself when it connects on its own.
     *
     * @return list<string>
     */
    public static function connectionKeys(): array
    {
        return array_merge([self::ENVIRONMENT], array_values(self::SECRET_KEYS), array_values(self::KEY_ORIGINS), array_values(self::WEBHOOK_SECRETS), array_values(self::WEBHOOK_ENDPOINTS));
    }

    /**
     * Every origin of a paired key saved in this PrestaShop, by the slug of
     * the scope that saved it, then by environment: what the reminders of
     * keys that expire go through (Connection\KeyReminders), whatever the
     * scope in context.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function keyOriginsEverywhere(): array
    {
        $origins = [];
        foreach (self::KEY_ORIGINS as $environment => $key) {
            foreach ($this->store->savedAt($key) as $slug => $stored) {
                $data = $stored !== '' ? json_decode($stored, true) : null;
                if (is_array($data)) {
                    $origins[$slug][$environment] = $data;
                }
            }
        }

        return $origins;
    }

    public function debugLog(): bool
    {
        return $this->store->get(self::DEBUG_LOG) === '1';
    }

    public function setDebugLog(bool $on): void
    {
        $this->store->set(self::DEBUG_LOG, $on ? '1' : '0');
    }

    // --- Payment methods ---------------------------------------------------------

    public function methodEnabled(string $kind): bool
    {
        $prefix = self::METHOD_PREFIXES[$kind] ?? null;

        return $prefix !== null && $this->store->get($prefix . 'ENABLED') !== '0';
    }

    public function setMethodEnabled(string $kind, bool $on): void
    {
        if (isset(self::METHOD_PREFIXES[$kind])) {
            $this->store->set(self::METHOD_PREFIXES[$kind] . 'ENABLED', $on ? '1' : '0');
        }
    }

    /** The title the merchant gave the method in this language, '' for the default (Payment\MethodTexts). */
    public function methodTitle(string $kind, int $idLang): string
    {
        $prefix = self::METHOD_PREFIXES[$kind] ?? null;

        return $prefix !== null ? (string) $this->store->get($prefix . 'TITLE', $idLang) : '';
    }

    /**
     * The description the merchant gave the method in this language: null
     * while none was saved (the default then shows), '' once cleared.
     */
    public function methodDescription(string $kind, int $idLang): ?string
    {
        $prefix = self::METHOD_PREFIXES[$kind] ?? null;
        if ($prefix === null) {
            return null;
        }
        $stored = $this->store->get($prefix . 'DESCRIPTION', $idLang);
        if ($stored === null) {
            return null;
        }
        $text = json_decode($stored, true);

        return is_string($text) ? $text : null;
    }

    /**
     * @param array<int, string> $titles by language id; '' for the default
     * @param array<int, string|null> $descriptions by language id; null for the default, '' for none
     */
    public function setMethodTexts(string $kind, array $titles, array $descriptions): void
    {
        $prefix = self::METHOD_PREFIXES[$kind] ?? null;
        if ($prefix === null) {
            return;
        }

        $this->store->setLang($prefix . 'TITLE', $titles);
        // Kept as JSON: Configuration would strip the line breaks of plain
        // text (ShopConfigStore). A description never saved, or set back to
        // the default, is kept as nothing at all.
        $this->store->setLang($prefix . 'DESCRIPTION', array_map(static function (?string $text): string {
            return $text === null ? '' : self::encode($text);
        }, $descriptions));
    }

    /**
     * The logos the merchant chose for the method, or null while they never
     * chose: the default of the shop's currency shows (Methods::defaultLogos()).
     *
     * @return list<string>|null
     */
    public function methodLogos(string $kind): ?array
    {
        $prefix = self::METHOD_PREFIXES[$kind] ?? null;
        $stored = $prefix !== null ? $this->json($prefix . 'LOGOS') : null;

        return $stored === null ? null : Methods::sanitizeLogos($stored, $kind);
    }

    /**
     * @param list<string> $logos
     */
    public function setMethodLogos(string $kind, array $logos): void
    {
        if (isset(self::METHOD_PREFIXES[$kind])) {
            $this->setJson(self::METHOD_PREFIXES[$kind] . 'LOGOS', Methods::sanitizeLogos($logos, $kind));
        }
    }

    /**
     * Whether the Mobile Money form finds the operator from the number alone,
     * rather than showing the list of operators with their logos (the
     * default, with the number's own operator picked already).
     */
    public function operatorFromNumber(): bool
    {
        return $this->store->get(self::OPERATOR_FROM_NUMBER) === '1';
    }

    public function setOperatorFromNumber(bool $on): void
    {
        $this->store->set(self::OPERATOR_FROM_NUMBER, $on ? '1' : '0');
    }

    // --- Unpaid orders -----------------------------------------------------------

    /**
     * Minutes after which an order still awaiting its Wajub payment is
     * cancelled, its products back in stock (Order\Cleanup), unless Wajub
     * holds money for it or a payment may still go through; 0: never.
     */
    public function cancelAfter(): int
    {
        $raw = $this->store->get(self::CANCEL_AFTER);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return self::CANCEL_AFTER_DEFAULT;
        }

        return max(0, min(self::CANCEL_AFTER_MAX, (int) $raw));
    }

    public function setCancelAfter(int $minutes): void
    {
        $this->store->set(self::CANCEL_AFTER, (string) max(0, min(self::CANCEL_AFTER_MAX, $minutes)));
    }

    // --- Appearance --------------------------------------------------------------

    /** The payment form's colours: 'system' (the shop's look), 'light' or 'dark'. */
    public function formTheme(): string
    {
        $theme = (string) $this->store->get(self::FORM_THEME);

        return in_array($theme, self::THEMES, true) ? $theme : 'system';
    }

    public function setFormTheme(string $theme): void
    {
        $this->store->set(self::FORM_THEME, in_array($theme, self::THEMES, true) ? $theme : 'system');
    }

    /** Whether payers see "Secured by Wajub" under the payment options. */
    public function securedLine(): bool
    {
        return $this->store->get(self::SECURED_LINE) !== '0';
    }

    public function setSecuredLine(bool $on): void
    {
        $this->store->set(self::SECURED_LINE, $on ? '1' : '0');
    }

    // --- Order states ------------------------------------------------------------

    public function awaitingState(): int
    {
        return (int) $this->store->get(self::STATE_AWAITING);
    }

    public function partialState(): int
    {
        return (int) $this->store->get(self::STATE_PARTIAL);
    }

    // --- Storage -----------------------------------------------------------------

    /**
     * JSON as Configuration keeps it intact: no line break, tag, ampersand
     * or quote left for its plain-text save to strip (ShopConfigStore).
     */
    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The value saved under `key` for this scope itself, null when it only inherits one, or none. */
    private function savedHere(string $key): ?string
    {
        return $key !== '' ? ($this->store->savedAt($key)[$this->scope()->slug()] ?? null) : null;
    }

    /**
     * Where the key of `environment` this scope pays with is saved, and its
     * stored value: [slug, stored], ['', ''] for none. All shops reads its
     * own. A shop or a group walks its chain up to the scope whose keys it
     * pays with (keysSavedFor()): the first value of the environment saved
     * on the way, an empty one included (a key removed from the shop), is
     * the one; none on the way, and it has no key of that environment,
     * whatever is saved further up.
     *
     * @return array{0: string, 1: string}
     */
    private function keyLookup(string $environment): array
    {
        $name = self::SECRET_KEYS[$environment] ?? '';
        if ($name === '') {
            return ['', ''];
        }
        $scope = $this->scope();
        if ($scope->isAllShops()) {
            $stored = (string) $this->store->get($name);

            return [$stored !== '' ? $scope->slug() : '', $stored];
        }

        $from = $this->keysSavedFor();
        if ($from === '') {
            return ['', ''];
        }
        $saved = $this->store->savedAt($name);
        foreach ($scope->chain() as $slug) {
            if (array_key_exists($slug, $saved)) {
                return [$saved[$slug] !== '' ? $slug : '', $saved[$slug]];
            }
            if ($slug === $from) {
                break;
            }
        }

        return ['', ''];
    }

    /**
     * @param array<string, mixed>|null $stored
     *
     * @return array{id: string, url: string, at: int}|null
     */
    private static function endpointFrom(?array $stored): ?array
    {
        if ($stored === null || (string) ($stored['id'] ?? '') === '') {
            return null;
        }

        return ['id' => (string) $stored['id'], 'url' => (string) ($stored['url'] ?? ''), 'at' => (int) ($stored['at'] ?? 0)];
    }

    private function secret(string $key): string
    {
        $stored = $key !== '' ? (string) $this->store->get($key) : '';
        if ($stored === '') {
            return '';
        }

        return trim((string) $this->cipher->decrypt($stored));
    }

    private function unreadable(string $key): bool
    {
        $stored = $key !== '' ? (string) $this->store->get($key) : '';

        return $stored !== '' && $this->cipher->decrypt($stored) === null;
    }

    private function setSecret(string $key, string $secret): void
    {
        if ($key === '') {
            return;
        }
        $secret = trim($secret);
        // An empty value rather than none: in a shop of a multistore, it
        // overrides a key saved for all shops, which the merchant removed
        // from this shop.
        $this->store->set($key, $secret === '' ? '' : $this->cipher->encrypt($secret));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $key): ?array
    {
        $stored = $key !== '' ? $this->store->get($key) : null;
        if ($stored === null || $stored === '') {
            return null;
        }
        $data = json_decode($stored, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<mixed>|null $value
     */
    private function setJson(string $key, ?array $value): void
    {
        if ($key !== '') {
            $this->store->set($key, $value === null ? '' : self::encode($value));
        }
    }
}

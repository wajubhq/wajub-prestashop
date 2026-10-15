<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Checkout;

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Which Wajub payment options the payer is offered at checkout, from what
 * the merchant's Wajub account offers for the cart (`GET /payments/options`).
 * A port of the WordPress plugin's CheckoutOffer and Fields\Capabilities.
 *
 * With Mobile Money or Card on (each with the payment form in the checkout):
 *  - each shows only when the account offers its kind for the cart;
 *  - "Other payment methods" (Wajub's hosted page) shows only when the
 *    account offers something they do not cover (PayPal, wallets, bank
 *    transfer…), and its payments are restricted to that (`accept.types`),
 *    so the payer is never offered the same method twice.
 * With both off, one option offers everything on Wajub's hosted page, and
 * nothing is asked.
 *
 * When Wajub cannot say, Mobile Money and Card show (preparing their payment
 * tells the payer when one cannot take it), and "Other payment methods" does
 * not: what it would offer besides them is unknown.
 *
 * The checkout never waits long on it: the client asks once, three seconds
 * at most (Client::paymentOptions()); an answer is kept ten minutes, and a
 * failure one minute, per environment, key, currency, country and order of
 * magnitude of the amount (100 to 999, 1 000 to 9 999…): what an account
 * offers changes with the amount only at a method's minimum or maximum, and
 * preparing the payment still says, for the exact total, when a method
 * cannot take it. Every answer is kept for the request too. Never asked from
 * the back office.
 */
final class Offer
{
    private const TTL = 600;

    /** How long a Wajub that did not answer is not asked again. */
    private const FAILED_TTL = 60;

    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var StateStore */
    private $state;

    /** @var callable(): Client */
    private $client;

    /** @var Logger */
    private $logger;

    /** @var bool */
    private $backOffice;

    /** @var array<string, array{types: list<string>, channels: list<array{slug: string, type: string}>}|null> what this request was told */
    private $asked = [];

    /**
     * @param callable(): Client $client the client of the environment in use
     * @param bool $backOffice true in the back office, where Wajub is never asked
     */
    public function __construct(Settings $settings, Environment $environment, StateStore $state, callable $client, Logger $logger, bool $backOffice = false)
    {
        $this->settings = $settings;
        $this->environment = $environment;
        $this->state = $state;
        $this->client = $client;
        $this->logger = $logger;
        $this->backOffice = $backOffice;
    }

    /**
     * The kinds of the payment form that are on (`mobile_money`, `card`).
     *
     * @return list<string>
     */
    public function separateKinds(): array
    {
        $kinds = [];
        foreach ([Methods::MOBILE_MONEY, Methods::CARD] as $kind) {
            if ($this->settings->methodEnabled($kind)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    public function separateOn(): bool
    {
        return $this->separateKinds() !== [];
    }

    /**
     * The channel types the separate methods that are on take.
     *
     * @return list<string>
     */
    public function coveredTypes(): array
    {
        $types = [];
        foreach ($this->separateKinds() as $kind) {
            $types = array_merge($types, Methods::ACCEPT_TYPES[$kind] ?? []);
        }

        return array_values(array_unique($types));
    }

    /**
     * Whether an option of the payment form of this kind shows for the cart:
     * always, unless Wajub said the account does not offer its kind for it.
     */
    public function showsKind(string $kind, ?CartView $cart): bool
    {
        if ($cart === null || !$cart->payable() || $this->backOffice) {
            return true;
        }

        return $this->offers($kind, $cart->currency, $cart->total, $cart->country) !== false;
    }

    /**
     * Whether the hosted page's option shows for the cart: always with both
     * separate methods off; otherwise only when the account offers something
     * they do not cover, which Wajub has to say.
     */
    public function showsOther(?CartView $cart): bool
    {
        if (!$this->separateOn()) {
            return true;
        }
        if ($cart === null || !$cart->payable() || $this->backOffice) {
            return true;
        }
        $left = $this->typesLeft($cart->currency, $cart->total, $cart->country);

        return $left !== null && $left !== [];
    }

    /**
     * The channel types a payment of the hosted page's option is restricted
     * to: what the account offers but the separate methods that are on do
     * not cover. Null when it offers everything (no separate method on) or
     * when Wajub cannot say; [] when nothing is left.
     *
     * @return list<string>|null
     */
    public function typesLeft(string $currency, float $amount, string $country = ''): ?array
    {
        $covered = $this->coveredTypes();
        if ($covered === [] || !$this->environment->isConfigured()) {
            return null;
        }
        $offer = $this->offer($currency, $amount, $country);

        return $offer === null ? null : array_values(array_diff($offer['types'], $covered));
    }

    /**
     * The logos of the hosted page's option. With a separate method on: the
     * merchant's choice, and the logos of the other channels (PayPal, Apple
     * Pay, Google Pay) the account offers for the cart. With none: the
     * operators of the shop's currency and the cards too, as the one option
     * offers them all.
     *
     * @param list<string>|null $chosen the merchant's choice, or null while they never chose
     *
     * @return list<string>
     */
    public function otherLogos(?array $chosen, ?CartView $cart, string $shopCurrency): array
    {
        $keys = $chosen ?? Methods::defaultLogos(Methods::OTHER, $shopCurrency);
        if (!$this->separateOn()) {
            return array_values(array_unique(array_merge(
                Methods::defaultLogos(Methods::MOBILE_MONEY, $shopCurrency),
                Methods::defaultLogos(Methods::CARD, $shopCurrency),
                $keys
            )));
        }
        if ($cart === null || !$cart->payable() || $this->backOffice || !$this->environment->isConfigured()) {
            return $keys;
        }

        $offer = $this->offer($cart->currency, $cart->total, $cart->country);
        if ($offer === null) {
            return $keys;
        }
        $covered = $this->coveredTypes();
        $slugs = [];
        foreach ($offer['channels'] as $channel) {
            if (!in_array($channel['type'], $covered, true)) {
                $slugs[] = $channel['slug'];
            }
        }
        foreach (Methods::channelLogos($slugs) as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Whether the account offers a method of this kind for this currency
     * and amount, or null when Wajub could not say.
     */
    public function offers(string $kind, string $currency, float $amount, string $country = ''): ?bool
    {
        $wanted = Methods::ACCEPT_TYPES[$kind] ?? null;
        if ($wanted === null) {
            return false;
        }
        $offer = $this->offer($currency, $amount, $country);
        if ($offer === null) {
            return null;
        }

        return array_intersect($wanted, $offer['types']) !== [];
    }

    /**
     * What a payment of this currency and amount could be paid with: the
     * channel types, sorted, and each channel's slug with its type. Null
     * when Wajub could not say.
     *
     * @return array{types: list<string>, channels: list<array{slug: string, type: string}>}|null
     */
    public function offer(string $currency, float $amount, string $country = ''): ?array
    {
        $currency = strtoupper(trim($currency));
        $country = strtoupper(trim($country));
        if ($this->backOffice || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || !is_finite($amount) || $amount <= 0 || !$this->environment->isConfigured()) {
            return null;
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $country = '';
        }

        $id = md5(implode('|', [
            $this->environment->inUse(),
            Keys::fingerprint($this->environment->activeKey()),
            $currency,
            $country,
            (string) self::magnitude($amount),
        ]));
        if (array_key_exists($id, $this->asked)) {
            return $this->asked[$id];
        }

        $kept = $this->state->get('offer:' . $id);
        if (is_array($kept) && is_array($kept['types'] ?? null)) {
            return $this->asked[$id] = self::read($kept);
        }
        if ($this->state->get('offer_failed:' . $id) !== null) {
            return $this->asked[$id] = null;
        }

        try {
            $channels = ($this->client)()->paymentOptions($currency, $amount, $country);
        } catch (\Throwable $e) {
            // Kept a minute: each page of the checkout would wait on Wajub again.
            $this->state->set('offer_failed:' . $id, 1, self::FAILED_TTL);
            $this->logger->error('Checkout: the payment methods of the account could not be read', ['error' => $e->getMessage()]);

            return $this->asked[$id] = null;
        }

        $types = [];
        $found = [];
        foreach ($channels as $channel) {
            $type = self::code($channel['type'] ?? null);
            if ($type === '') {
                continue;
            }
            if (!in_array($type, $types, true)) {
                $types[] = $type;
            }
            $slug = self::code($channel['slug'] ?? ($channel['id'] ?? null));
            if ($slug !== '') {
                $found[] = ['slug' => $slug, 'type' => $type];
            }
        }
        sort($types);

        $offer = ['types' => $types, 'channels' => $found];
        $this->state->set('offer:' . $id, $offer, self::TTL);

        return $this->asked[$id] = $offer;
    }

    /** The order of magnitude of an amount, which an answer is kept for: 2 for 100 to 999, 3 for 1 000 to 9 999. */
    public static function magnitude(float $amount): int
    {
        return $amount >= 1 ? (int) floor(log10($amount) + 1e-9) : 0;
    }

    /**
     * A kept answer, as offer() gives it.
     *
     * @param array<mixed> $kept
     *
     * @return array{types: list<string>, channels: list<array{slug: string, type: string}>}
     */
    private static function read(array $kept): array
    {
        $channels = [];
        foreach (is_array($kept['channels'] ?? null) ? $kept['channels'] : [] as $channel) {
            if (is_array($channel) && is_string($channel['slug'] ?? null) && is_string($channel['type'] ?? null)) {
                $channels[] = ['slug' => $channel['slug'], 'type' => $channel['type']];
            }
        }

        return [
            'types' => array_values(array_filter(is_array($kept['types']) ? $kept['types'] : [], 'is_string')),
            'channels' => $channels,
        ];
    }

    /** A type or a slug as the API writes it, in lower case, or '' for anything else. */
    private static function code(mixed $value): string
    {
        $code = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return $code !== '' && strlen($code) <= 64 && preg_match('/^[a-z0-9._-]+$/', $code) === 1 ? $code : '';
    }
}

<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The three payment options the module offers at checkout, and the logos a
 * payer recognises them by.
 *
 * - Mobile Money and Card: Wajub's payment form inside the checkout, each
 *   restricted to its own kind of channel (`accept.types`).
 * - Other payment methods: Wajub's hosted page, restricted to what the
 *   account offers beyond Mobile Money and cards, and shown only when there
 *   is something.
 *
 * The logos are the brands' own artwork, from Wajub's brand kit
 * (views/img/methods), each drawn LOGO_HEIGHT high and as wide as its own
 * shape.
 */
final class Methods
{
    public const MOBILE_MONEY = 'mobile_money';

    public const CARD = 'card';

    public const OTHER = 'other';

    /** In the order the checkout lists them. */
    public const KINDS = [self::MOBILE_MONEY, self::CARD, self::OTHER];

    /**
     * The channel types (`accept.types`) each kind restricts its payments
     * to. The API compares types exactly, and Mobile Money channels exist
     * under both `mobile_money` and `mobile`: one of them alone would hide
     * the others' operators. "Other payment methods" takes every type the
     * account offers but these.
     */
    public const ACCEPT_TYPES = [
        self::MOBILE_MONEY => ['mobile_money', 'mobile'],
        self::CARD => ['card'],
    ];

    /** The height every logo is drawn at; its width follows its own shape (LOGOS). */
    public const LOGO_HEIGHT = 24;

    /**
     * Every logo: its file in views/img/methods, its width when drawn
     * LOGO_HEIGHT high, whether its art has no background of its own (drawn
     * on a white badge, so a dark wordmark still reads on a dark theme), and
     * the kind of method it stands for.
     *
     * @var array<string, array{0: string, 1: float|int, 2: bool, 3: string}>
     */
    private const LOGOS = [
        'mtn' => ['mtn.png', 24, false, self::MOBILE_MONEY],
        'orange' => ['orange.svg', 24, false, self::MOBILE_MONEY],
        'wave' => ['wave.svg', 24.03, false, self::MOBILE_MONEY],
        'moov' => ['moov.png', 26.67, false, self::MOBILE_MONEY],
        'airtel' => ['airtel.svg', 23.92, true, self::MOBILE_MONEY],
        'mpesa' => ['mpesa.png', 91.67, false, self::MOBILE_MONEY],
        'visa' => ['visa.svg', 38.22, false, self::CARD],
        'mastercard' => ['mastercard.svg', 38.8, false, self::CARD],
        'paypal' => ['paypal.svg', 39, false, self::OTHER],
        'applepay' => ['applepay.svg', 34.97, false, self::OTHER],
        'googlepay' => ['googlepay.svg', 34.76, false, self::OTHER],
    ];

    /** Brand names, which no language translates. */
    private const LOGO_NAMES = [
        'mtn' => 'MTN Mobile Money',
        'orange' => 'Orange Money',
        'wave' => 'Wave',
        'moov' => 'Moov Money',
        'airtel' => 'Airtel Money',
        'mpesa' => 'M-Pesa',
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'paypal' => 'PayPal',
        'applepay' => 'Apple Pay',
        'googlepay' => 'Google Pay',
    ];

    /**
     * The Mobile Money operators payers use most, by the shop's currency:
     * the logos shown until the merchant chooses.
     */
    private const OPERATORS_BY_CURRENCY = [
        'XAF' => ['mtn', 'orange'],
        'XOF' => ['orange', 'wave', 'moov', 'mtn'],
        'KES' => ['mpesa', 'airtel'],
        'TZS' => ['mpesa', 'airtel'],
        'UGX' => ['mtn', 'airtel'],
        'RWF' => ['mtn', 'airtel'],
        'ZMW' => ['mtn', 'airtel'],
        'GHS' => ['mtn'],
        'GNF' => ['orange', 'mtn'],
        'CDF' => ['orange', 'mpesa', 'airtel'],
        'MGA' => ['airtel', 'orange'],
        'MWK' => ['airtel'],
    ];

    /** The operators shown for Mobile Money in a currency of none of those zones: Wajub takes Mobile Money in any currency. */
    private const OPERATORS_ELSEWHERE = ['mtn', 'orange', 'airtel', 'mpesa'];

    /** The logo of a channel the API names without a country (its slug), for the methods besides Mobile Money and cards. */
    private const CHANNEL_LOGOS = [
        'paypal' => 'paypal',
        'apple_pay' => 'applepay',
        'google_pay' => 'googlepay',
    ];

    /** The Wajub.js component each kind of the payment form is. */
    public const COMPONENTS = [
        self::MOBILE_MONEY => 'mobileMoney',
        self::CARD => 'card',
    ];

    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * The logos a kind may show, in the order the settings list them.
     *
     * @return list<string>
     */
    public static function logoChoices(string $kind): array
    {
        $keys = [];
        foreach (self::LOGOS as $key => $logo) {
            if ($logo[3] === $kind) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The logos a kind shows until the merchant chooses: the networks payers
     * use most in the shop's currency zone, the cards everywhere, and none
     * for the other methods, whose list the account decides.
     *
     * @return list<string>
     */
    public static function defaultLogos(string $kind, string $currency): array
    {
        switch ($kind) {
            case self::MOBILE_MONEY:
                return self::OPERATORS_BY_CURRENCY[strtoupper(trim($currency))] ?? self::OPERATORS_ELSEWHERE;
            case self::CARD:
                return ['visa', 'mastercard'];
            default:
                return [];
        }
    }

    /**
     * The known logos of a kind in a selection, once each, in the order given.
     *
     * @return list<string>
     */
    public static function sanitizeLogos(mixed $selection, string $kind): array
    {
        if (!is_array($selection)) {
            return [];
        }

        $choices = self::logoChoices($kind);
        $keys = [];
        foreach ($selection as $key) {
            if (is_string($key) && in_array($key, $choices, true) && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** The kind of method a logo stands for, '' for a logo the module does not know. */
    public static function logoKind(string $key): string
    {
        return self::LOGOS[$key][3] ?? '';
    }

    /**
     * The logos of the channels of a Wajub account (their slugs: `paypal`,
     * `apple_pay`), for the methods the module has one of, once each.
     *
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    public static function channelLogos(array $slugs): array
    {
        $keys = [];
        foreach ($slugs as $slug) {
            $key = self::CHANNEL_LOGOS[strtolower(trim($slug))] ?? null;
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Whether the kind is one of the payment form's (Mobile Money, Card). */
    public static function isFields(string $kind): bool
    {
        return isset(self::COMPONENTS[$kind]);
    }

    public static function logoFile(string $key): string
    {
        return self::LOGOS[$key][0] ?? '';
    }

    /** A logo's width when drawn LOGO_HEIGHT high, in whole pixels. */
    public static function logoWidth(string $key): int
    {
        return (int) round(self::LOGOS[$key][1] ?? 38);
    }

    public static function logoIsPlain(string $key): bool
    {
        return self::LOGOS[$key][2] ?? false;
    }

    public static function logoName(string $key): string
    {
        return self::LOGO_NAMES[$key] ?? '';
    }
}

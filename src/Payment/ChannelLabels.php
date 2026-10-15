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

use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The name a payer or a merchant knows a Wajub channel by: `cm.mtn` is "MTN
 * Mobile Money", `paypal` "PayPal", `card` "Card", `crypto.btc` "Crypto
 * (Bitcoin)". A brand reads as the brand writes it; what is no brand
 * ("Card", "Bank transfer") is translated. A channel the module does not
 * know reads as its own provider name, never as the raw slug. A port of the
 * WordPress plugin's PaymentMethods::channelLabel(), with its tables.
 *
 * The codes are what a payment's `channel` holds: the slug of its channel in
 * Wajub's catalogue, or, for a payment made without one, its payment
 * method's type (`mobile_money`, `card`, `crypto`, `bank`, `other`).
 */
final class ChannelLabels
{
    /**
     * Payment methods known by a name of their own, by their whole channel
     * code: wallets, bank payments and vouchers, and the few whose operator
     * part reads otherwise in that country. Brand names, which no language
     * translates.
     */
    private const BRANDS = [
        'apple_pay' => 'Apple Pay',
        'google_pay' => 'Google Pay',
        'paypal' => 'PayPal',
        'link' => 'Link',
        'amazon_pay' => 'Amazon Pay',
        'revolut_pay' => 'Revolut Pay',
        'cash.app' => 'Cash App Pay',
        'us.cashapp' => 'Cash App Pay',
        'mobilepay' => 'MobilePay',
        'grabpay' => 'GrabPay',
        'alipay' => 'Alipay',
        'wechat_pay' => 'WeChat Pay',
        'klarna' => 'Klarna',
        'afterpay' => 'Afterpay',
        'us.affirm' => 'Affirm',
        'ideal' => 'iDEAL',
        'bancontact' => 'Bancontact',
        'p24' => 'Przelewy24',
        'pl.blik' => 'BLIK',
        'at.eps' => 'EPS',
        'de.sofort' => 'Sofort',
        'de.giropay' => 'Giropay',
        'eu.trustly' => 'Trustly',
        'es.bizum' => 'Bizum',
        'pt.multibanco' => 'Multibanco',
        'it.satispay' => 'Satispay',
        'ch.twint' => 'TWINT',
        'se.swish' => 'Swish',
        'my.fpx' => 'FPX',
        'sg.paynow' => 'PayNow',
        'th.promptpay' => 'PromptPay',
        'jp.konbini' => 'Konbini',
        'br.pix' => 'Pix',
        'br.boleto' => 'Boleto',
        'mx.oxxo' => 'OXXO',
        'kr.kakaopay' => 'Kakao Pay',
        'kr.naverpay' => 'Naver Pay',
        'kr.samsungpay' => 'Samsung Pay',
        'in.upi' => 'UPI',
        'in.rupay' => 'RuPay',
        'in.paytm' => 'Paytm',
        'eg.fawry' => 'Fawry',
        'eg.meeza' => 'Meeza',
        'eg.instapay' => 'InstaPay',
        'ma.cmi' => 'CMI',
        'tn.edinar' => 'e-Dinar',
        'tn.flouci' => 'Flouci',
        'dz.cib' => 'CIB',
        'dz.edahabia' => 'Edahabia',
        // Operators whose wallet is named otherwise in this one country:
        // Vodafone's is Telecel Cash in Ghana (OPERATORS), Vodafone Cash in
        // Egypt and M-Pesa in Tanzania (Vodacom's); Airtel and Tigo merged
        // their wallets in Ghana.
        'eg.vodafone' => 'Vodafone Cash',
        'tz.vodafone' => 'M-Pesa',
        'gh.airtel' => 'AirtelTigo Money',
        'gh.tigo' => 'AirtelTigo Money',
        // Yas is the new brand of Tigo in Tanzania and of Free in Senegal.
        // The API announces their wallets under the former channels
        // (`tz.tigo`, `sn.free`), which Wajub's catalogue still names so: a
        // payment read under either code is the same method, named alike
        // (the Overview groups payments by this name).
        'tz.yas' => 'Tigo Pesa',
        'sn.yas' => 'Free Money',
    ];

    /**
     * Mobile Money and wallet operators, by the part of a channel after its
     * country (`cm.mtn`, `cm.mtn.mobile`). Brand names.
     */
    private const OPERATORS = [
        'mtn' => 'MTN Mobile Money',
        'lonestar' => 'MTN Mobile Money',
        'areeba' => 'MTN Mobile Money',
        'orange' => 'Orange Money',
        'moov' => 'Moov Money',
        'onatel' => 'Moov Money',
        'libertis' => 'Moov Money',
        'sotelma' => 'Moov Money',
        'flooz' => 'Flooz',
        'wave' => 'Wave',
        'airtel' => 'Airtel Money',
        'airtel_till' => 'Airtel Money',
        'mpesa' => 'M-Pesa',
        'mpesa_till' => 'M-Pesa',
        'vodacom' => 'M-Pesa',
        'safaricom' => 'M-Pesa',
        'equitel' => 'Equitel',
        'tkash' => 'T-Kash',
        'free' => 'Free Money',
        'yas' => 'Mixx by Yas',
        'expresso' => 'Expresso',
        'tmoney' => 'T-Money',
        'togocel' => 'T-Money',
        'vodafone' => 'Telecel Cash',
        'voda' => 'Telecel Cash',
        'telecel' => 'Telecel Cash',
        // AirtelTigo is AT now; the API announces `gh.at` as `gh.airteltigo`.
        'airteltigo' => 'AirtelTigo Money',
        'at' => 'AirtelTigo Money',
        'tigo' => 'Tigo Pesa',
        'halopesa' => 'HaloPesa',
        'halotel' => 'HaloPesa',
        'viettel' => 'HaloPesa',
        'zamtel' => 'Zamtel Kwacha',
        'tnm' => 'TNM Mpamba',
        'africell' => 'Africell Money',
        'eu' => 'Express Union Mobile',
        'expressunion' => 'Express Union Mobile',
        'express_union' => 'Express Union Mobile',
        'yoomee' => 'YooMee Money',
        'yup' => 'YUP',
        'djamo' => 'Djamo',
        'capitec' => 'Capitec Pay',
        'opay' => 'OPay',
        'palmpay' => 'PalmPay',
    ];

    /** Card networks, by the part of a channel after `card.`. */
    private const CARD_NETWORKS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'amex' => 'American Express',
        'discover' => 'Discover',
        'maestro' => 'Maestro',
        'jcb' => 'JCB',
        'diners' => 'Diners Club',
        'unionpay' => 'UnionPay',
    ];

    /** Cryptocurrencies, by the part of a channel after `crypto.`. */
    private const COINS = [
        'btc' => 'Bitcoin',
        'eth' => 'Ethereum',
        'usdc' => 'USDC',
        'usdt' => 'USDT',
        'doge' => 'Dogecoin',
        'ltc' => 'Litecoin',
        'xrp' => 'XRP',
        'trx' => 'TRON',
        'bnb' => 'BNB',
        'sol' => 'Solana',
        'ada' => 'Cardano',
        'matic' => 'Polygon',
    ];

    public static function label(string $channel, Translator $t): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }
        if (isset(self::BRANDS[$channel])) {
            return self::BRANDS[$channel];
        }

        $generic = self::genericNames($t);
        if (isset($generic[$channel])) {
            return $generic[$channel];
        }

        $parts = explode('.', $channel);
        if ($parts[0] === 'crypto' && ($parts[1] ?? '') !== '') {
            return $t->trans('Crypto (%coin%)', ['%coin%' => self::COINS[$parts[1]] ?? strtoupper($parts[1])], 'Modules.Wajub.Shop');
        }
        if (str_starts_with($channel, 'card')) {
            return self::CARD_NETWORKS[$parts[1] ?? ''] ?? $t->trans('Card', [], 'Modules.Wajub.Shop');
        }

        // The operator follows the country: `cm.mtn`, and `cm.mtn.mobile`,
        // whose third part only says it is Mobile Money.
        $provider = ($parts[1] ?? '') !== '' ? $parts[1] : $parts[0];

        return self::OPERATORS[$provider]
            ?? $generic[$provider]
            ?? ucwords(str_replace(['_', '-'], ' ', $provider));
    }

    /**
     * Whether a payment's channel is a card (`card`, `card.visa`, a local
     * card such as `kr.card`): one the payer approves in the form, with no
     * request to approve on a phone.
     */
    public static function isCard(string $channel): bool
    {
        $channel = strtolower(trim($channel));

        return $channel === 'card' || str_starts_with($channel, 'card.') || str_ends_with($channel, '.card');
    }

    /**
     * The methods that are no brand, which a merchant reads in their own
     * language: by whole channel code or by the part after the country.
     *
     * @return array<string, string>
     */
    private static function genericNames(Translator $t): array
    {
        $bank = $t->trans('Bank transfer', [], 'Modules.Wajub.Shop');
        $mobileMoney = $t->trans('Mobile Money', [], 'Modules.Wajub.Shop');
        $debit = $t->trans('Bacs Direct Debit', [], 'Modules.Wajub.Shop');

        return [
            'card' => $t->trans('Card', [], 'Modules.Wajub.Shop'),
            'kr.card' => $t->trans('Korean local card', [], 'Modules.Wajub.Shop'),
            'mobile' => $mobileMoney,
            'mobile_money' => $mobileMoney,
            'crypto' => $t->trans('Crypto', [], 'Modules.Wajub.Shop'),
            'sepa_debit' => $t->trans('SEPA Direct Debit', [], 'Modules.Wajub.Shop'),
            'us.bank_account' => $t->trans('ACH Direct Debit', [], 'Modules.Wajub.Shop'),
            'ca.acss' => $t->trans('Pre-authorized debit (ACSS)', [], 'Modules.Wajub.Shop'),
            'gb.bacs' => $debit,
            'gb.bacs_debit' => $debit,
            'au.becs' => $t->trans('BECS Direct Debit', [], 'Modules.Wajub.Shop'),
            'in.netbanking' => $t->trans('Net banking', [], 'Modules.Wajub.Shop'),
            'wire' => $bank,
            'bank' => $bank,
            'bank_transfer' => $bank,
            'transfer' => $bank,
            'account' => $bank,
            'eft' => $bank,
            'ussd' => $t->trans('USSD', [], 'Modules.Wajub.Shop'),
            'qr' => $t->trans('QR code', [], 'Modules.Wajub.Shop'),
            'other' => $t->trans('Other', [], 'Modules.Wajub.Shop'),
        ];
    }
}

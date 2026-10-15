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

use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Payment\MethodTexts;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Text;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payment options the checkout lists (hookPaymentOptions), as data: each
 * one's kind, the name and description the payer reads (the merchant's,
 * else the default in the page's language), its logos, and how it pays
 * (the form in the checkout, or Wajub's hosted page). The hook turns them
 * into PrestaShop's PaymentOption objects.
 *
 * - Mobile Money and Card, each when on and offered for the cart (Offer);
 * - "Other payment methods" when on and the account has something besides
 *   them; with Mobile Money and Card both off, it is the one option, and
 *   offers everything Wajub's hosted page has ("Mobile Money or card").
 *
 * Nothing at all while the shop has no key for the environment it uses.
 */
final class CheckoutOptions
{
    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var Offer */
    private $offer;

    /** @var Translator */
    private $t;

    public function __construct(Settings $settings, Environment $environment, Offer $offer, Translator $t)
    {
        $this->settings = $settings;
        $this->environment = $environment;
        $this->offer = $offer;
        $this->t = $t;
    }

    /**
     * @return list<array{kind: string, mode: string, title: string, description: string, logos: list<string>}>
     */
    public function forCart(?CartView $cart, int $idLang, string $shopCurrency): array
    {
        if (!$this->environment->isConfigured()) {
            return [];
        }

        $options = [];
        foreach ($this->offer->separateKinds() as $kind) {
            if ($this->offer->showsKind($kind, $cart)) {
                $options[] = [
                    'kind' => $kind,
                    'mode' => 'fields',
                    'title' => $this->title($kind, $idLang),
                    'description' => $this->description($kind, $idLang),
                    'logos' => $this->logos($kind, $shopCurrency),
                ];
            }
        }

        if ($this->settings->methodEnabled(Methods::OTHER) && $this->offer->showsOther($cart)) {
            $options[] = [
                'kind' => Methods::OTHER,
                'mode' => 'hosted',
                'title' => $this->title(Methods::OTHER, $idLang),
                'description' => $this->description(Methods::OTHER, $idLang),
                'logos' => $this->offer->otherLogos($this->settings->methodLogos(Methods::OTHER), $cart, $shopCurrency),
            ];
        }

        return $options;
    }

    /** The name the payer reads: the merchant's, else the default, which says what the option offers. */
    public function title(string $kind, int $idLang): string
    {
        $title = Text::line($this->settings->methodTitle($kind, $idLang), Settings::TITLE_MAX);
        if ($title !== '') {
            return $title;
        }

        return $kind === Methods::OTHER && !$this->offer->separateOn()
            ? MethodTexts::everythingTitle($this->t)
            : MethodTexts::defaultTitle($kind, $this->t);
    }

    /** The description: the merchant's, '' once they cleared it, else the default. */
    public function description(string $kind, int $idLang): string
    {
        $description = $this->settings->methodDescription($kind, $idLang);
        if ($description !== null) {
            return Text::lines($description, Settings::DESCRIPTION_MAX);
        }

        return $kind === Methods::OTHER && !$this->offer->separateOn()
            ? MethodTexts::everythingDescription($this->t)
            : MethodTexts::defaultDescription($kind, $this->t);
    }

    /**
     * The logos of a kind of the form: the merchant's choice, or the
     * defaults of the shop's currency until they choose.
     *
     * @return list<string>
     */
    public function logos(string $kind, string $shopCurrency): array
    {
        return $this->settings->methodLogos($kind) ?? Methods::defaultLogos($kind, $shopCurrency);
    }

    /**
     * What the checkout's script runs a kind's form with (checkout-fields.js):
     * every word comes from here, and no secret.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(string $kind, string $language): array
    {
        $fields = ['customer' => 'external'];
        if ($kind === Methods::MOBILE_MONEY && $this->settings->operatorFromNumber()) {
            $fields['operator'] = 'detect';
        }

        return [
            'kind' => $kind,
            'component' => Methods::COMPONENTS[$kind] ?? '',
            'locale' => $language,
            'appearance' => ['theme' => $this->settings->formTheme()],
            'componentOptions' => ['variant' => 'fields', 'fields' => $fields],
            'i18n' => CheckoutStrings::fields($kind, $this->t),
        ];
    }
}

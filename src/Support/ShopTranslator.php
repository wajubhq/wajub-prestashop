<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Translator on PrestaShop's translator, in the language of the page,
 * or, given one, in a language of the shop's own: an e-mail to the
 * merchant is written in the shop's language, whoever's request sends it.
 */
final class ShopTranslator implements Translator
{
    /** @var \Language|null */
    private $language;

    public function __construct(?\Language $language = null)
    {
        $this->language = $language;
    }

    /** @var array<string, \PrestaShopBundle\Translation\TranslatorComponent> PrestaShop's translator of each language asked for, this request */
    private static $byLocale = [];

    public function trans(string $id, array $parameters = [], ?string $domain = null): string
    {
        $context = \Context::getContext();
        $locale = $this->language !== null ? (string) $this->language->locale : '';
        $own = $context->language instanceof \Language ? (string) $context->language->locale : '';
        if ($locale === '' || $locale === $own) {
            return (string) $context->getTranslator()->trans($id, $parameters, $domain);
        }
        // The context's translator holds the page's language only: another
        // is loaded, with the modules' catalogues of that language.
        if (!isset(self::$byLocale[$locale])) {
            self::$byLocale[$locale] = $context->getTranslatorFromLocale($locale);
        }

        return (string) self::$byLocale[$locale]->trans($id, $parameters, $domain, $locale);
    }

    public function date(int $timestamp): string
    {
        $language = $this->language ?? \Context::getContext()->language;
        $format = is_object($language) && !empty($language->date_format_lite)
            ? (string) $language->date_format_lite
            : 'Y-m-d';

        return date($format, $timestamp);
    }
}

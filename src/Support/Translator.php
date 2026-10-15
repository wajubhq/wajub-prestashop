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
 * Words for the merchant and the payer, through PrestaShop's translation
 * system. Every caller writes the English wording, its parameters and its
 * domain as literals (`trans('Key saved.', [], 'Modules.Wajub.Admin')`), so
 * PrestaShop's extractor finds them for International > Translations, and
 * the French catalogue the module ships translates them. The domain is
 * written out at each call, never through a constant: the extractor reads
 * literals only.
 */
interface Translator
{
    /**
     * @param array<string, string|int|float> $parameters named placeholders, such as ['%business%' => 'Boutique']
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null): string;

    /** A day as the shop writes it, in the language of the page. */
    public function date(int $timestamp): string;
}

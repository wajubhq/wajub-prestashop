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
 * An amount as a reader of the shop expects to see it: "25 000 FCFA" on a
 * French page, "FCFA25,000" on an English one. Every amount the module
 * writes for a payer or a merchant goes through here.
 */
interface MoneyFormatter
{
    public function format(float $amount, string $currency): string;
}

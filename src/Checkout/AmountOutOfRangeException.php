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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wajub refused the payment's amount: outside what the currency, or the
 * method's kind, can pay (a 422 on `amount`).
 */
final class AmountOutOfRangeException extends \RuntimeException
{
}

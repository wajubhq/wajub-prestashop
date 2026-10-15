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
 * Wajub refused the payment because no payment method of its kind is left
 * for its currency and amount (a 422 on `accept`).
 */
final class MethodUnavailableException extends \RuntimeException
{
}

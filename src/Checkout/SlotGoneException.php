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
 * The payment prepared for the cart can no longer pay the order (Wajub no
 * longer has it, or took another activation of it): the payer needs a new
 * form, unless it paid the order already.
 */
final class SlotGoneException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $payment the payment as Wajub read it, when it was read: one that went through is settled with the order
     */
    public function __construct(string $message, public readonly ?array $payment = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

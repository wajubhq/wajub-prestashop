<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * No single Wajub payment carries a reference (Client::paymentIdByReference()):
 * none, or several. The caller words it for the merchant, with the reference.
 */
final class PaymentLookupException extends \RuntimeException
{
    public const NONE = 'none';

    public const SEVERAL = 'several';

    public function __construct(public readonly string $reason, public readonly string $reference)
    {
        parent::__construct($reason === self::NONE
            ? sprintf('No Wajub payment carries the reference %s.', $reference)
            : sprintf('Several Wajub payments carry the reference %s.', $reference));
    }
}

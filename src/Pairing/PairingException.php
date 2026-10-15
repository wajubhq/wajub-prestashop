<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Why a pairing session could not be opened: `reason` for the code, the
 * message for the merchant, and the HTTP status the answer carries.
 */
final class PairingException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}

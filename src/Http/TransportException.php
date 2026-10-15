<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * No answer came: the transport's own words, which name the host and the
 * failure, never a header that was sent.
 */
final class TransportException extends \RuntimeException
{
}

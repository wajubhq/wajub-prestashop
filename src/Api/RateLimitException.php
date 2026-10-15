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
 * 429: Too many requests: Wajub asks to wait (`Retry-After`).
 */
final class RateLimitException extends ApiException
{
}

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
 * 401: Wajub does not know the key (rolled, deleted, expired, or limited to other addresses).
 */
final class AuthenticationException extends ApiException
{
}

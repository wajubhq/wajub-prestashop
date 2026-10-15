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
 * 403: Wajub knows the key, and it may not do this (a restricted key without the permission).
 */
final class PermissionException extends ApiException
{
}

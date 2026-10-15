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
 * What the module writes down about itself. Never a key, a signing secret or
 * a polling token: a fingerprint names a key (Settings\Keys::fingerprint()).
 */
interface Logger
{
    /**
     * Written only while the merchant has turned the debug log on.
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Always written: something failed that the merchant may have to act on.
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}

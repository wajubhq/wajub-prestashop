<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * How secret keys and signing secrets are kept in the database: encrypted,
 * so a copy of the configuration table (a backup, an export, a support
 * dump) does not hand over the keys that take payments.
 */
interface SecretCipher
{
    public function encrypt(string $plain): string;

    /** The secret, or null when it cannot be read: the shop's own key changed since it was saved. */
    public function decrypt(string $stored): ?string;
}

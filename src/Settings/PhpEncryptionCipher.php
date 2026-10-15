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
 * The SecretCipher on PrestaShop's own encryption (PhpEncryption, with the
 * shop's _NEW_COOKIE_KEY_ from app/config/parameters.php): what the shop
 * encrypts its cookies with, so the module ships no cryptography of its own
 * and keeps no key of its own.
 *
 * A value is kept as `v1:` and the ciphertext, so a later format can be
 * told apart. A shop moved without its parameters.php gets a new cookie
 * key: its saved secrets can no longer be read, and the merchant connects
 * again (Settings says so).
 */
final class PhpEncryptionCipher implements SecretCipher
{
    private const PREFIX = 'v1:';

    public function encrypt(string $plain): string
    {
        return self::PREFIX . (string) $this->engine()->encrypt($plain);
    }

    public function decrypt(string $stored): ?string
    {
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return null;
        }

        try {
            $plain = $this->engine()->decrypt(substr($stored, strlen(self::PREFIX)));
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }

    private function engine(): \PhpEncryption
    {
        return new \PhpEncryption(_NEW_COOKIE_KEY_);
    }
}

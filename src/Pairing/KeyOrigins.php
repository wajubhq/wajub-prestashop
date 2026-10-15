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

use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * How each environment's key came to the shop, when "Connect with Wajub"
 * gave it, and when it stops working.
 *
 * An origin is kept beside the key (Settings::keyOrigin()): via, business,
 * client_type, device_name, fp (the key's fingerprint, never the key), at,
 * environment, key_type, permissions, home (the shop's address when it
 * paired, so a copy of the shop can tell it is one), site_type, and
 * expires_at: 0 for a key that does not expire, missing when the Dashboard
 * did not say; and reminded_at, once the merchant was e-mailed that it
 * expires (Connection\KeyReminders). An origin speaks for the key saved now
 * only while its fingerprint is that key's (pairedKey()): a key pasted since
 * has none, and a new pairing writes a new origin, without reminded_at.
 */
final class KeyOrigins
{
    /** How long before a paired key expires the merchant is warned. */
    public const WARN_BEFORE = 14 * 86400;

    /** @var Settings */
    private $settings;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    public function __construct(Settings $settings, Translator $t, Logger $logger)
    {
        $this->settings = $settings;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * The origin of the key of `environment`, when a pairing gave it, read
     * as it was kept. `expires_at` is null when the Dashboard did not say.
     *
     * @return array{via: string, business: string, client_type: string, device_name: string, fp: string, at: int, environment: string, key_type: string, permissions: list<string>|null, home: string, site_type: string, expires_at: int|null, reminded_at: int}|null
     */
    public function origin(string $environment): ?array
    {
        $origin = $this->settings->keyOrigin($environment);
        if ($origin === null || ($origin['via'] ?? '') !== 'pairing' || (string) ($origin['fp'] ?? '') === '') {
            return null;
        }

        return [
            'via' => 'pairing',
            'business' => (string) ($origin['business'] ?? ''),
            'client_type' => (string) ($origin['client_type'] ?? ''),
            'device_name' => (string) ($origin['device_name'] ?? ''),
            'fp' => (string) $origin['fp'],
            'at' => (int) ($origin['at'] ?? 0),
            'environment' => (string) ($origin['environment'] ?? $environment),
            'key_type' => (string) ($origin['key_type'] ?? ''),
            'permissions' => is_array($origin['permissions'] ?? null) ? array_values(array_map('strval', $origin['permissions'])) : null,
            'home' => (string) ($origin['home'] ?? ''),
            'site_type' => (string) ($origin['site_type'] ?? ''),
            'expires_at' => array_key_exists('expires_at', $origin) && is_numeric($origin['expires_at']) ? max(0, (int) $origin['expires_at']) : null,
            'reminded_at' => max(0, (int) ($origin['reminded_at'] ?? 0)),
        ];
    }

    /**
     * The origin of the key `environment` uses now, when a pairing gave it:
     * null for a key pasted since, or no key.
     *
     * @return array{via: string, business: string, client_type: string, device_name: string, fp: string, at: int, environment: string, key_type: string, permissions: list<string>|null, home: string, site_type: string, expires_at: int|null, reminded_at: int}|null
     */
    public function pairedKey(string $environment): ?array
    {
        $stored = $this->settings->secretKey($environment);
        $origin = $this->origin($environment);

        return $origin !== null && $stored !== '' && hash_equals($origin['fp'], Keys::fingerprint($stored)) ? $origin : null;
    }

    /**
     * Where the paired key of `environment` stands: 'expired' once its date
     * has passed, 'expiring' within WARN_BEFORE of it, '' otherwise (no
     * date, no expiry, a pasted key, no key).
     */
    public function expiryState(string $environment, ?int $now = null): string
    {
        $origin = $this->pairedKey($environment);
        $expiresAt = $origin !== null ? (int) $origin['expires_at'] : 0;
        if ($expiresAt <= 0) {
            return '';
        }
        $now = $now ?? time();
        if ($now >= $expiresAt) {
            return 'expired';
        }

        return $now >= $expiresAt - self::WARN_BEFORE ? 'expiring' : '';
    }

    /**
     * The day the paired key `key` of `environment` expired, or 0: not
     * paired, not expired, or not the key saved for `environment`.
     */
    public function expiredOn(string $environment, string $key, ?int $now = null): int
    {
        $origin = $this->pairedKey($environment);
        if ($origin === null || !hash_equals($origin['fp'], Keys::fingerprint($key))) {
            return 0;
        }
        $expiresAt = (int) $origin['expires_at'];

        return $expiresAt > 0 && ($now ?? time()) >= $expiresAt ? $expiresAt : 0;
    }

    /**
     * Wajub accepted `key`, the key of `environment`. Past the date kept for
     * it, that date was wrong (changed in the Wajub Dashboard since, or the
     * server's clock is ahead): it is forgotten, so the shop stops saying
     * the key expired. When it expires is then no longer known.
     */
    public function accepted(string $environment, string $key, ?int $now = null): void
    {
        $expiredOn = $this->expiredOn($environment, $key, $now);
        $origin = $this->settings->keyOrigin($environment);
        if ($expiredOn === 0 || $origin === null) {
            return;
        }

        unset($origin['expires_at'], $origin['reminded_at']);
        $this->settings->setKeyOrigin($environment, $origin);
        $this->logger->info('Paired key accepted after the date it was to expire: date forgotten', ['environment' => $environment, 'expired_on' => gmdate('Y-m-d', $expiredOn)]);
    }

    /**
     * Notes that the merchant was e-mailed about the paired key of
     * `environment`: one e-mail per key. Kept before it is sent, so a
     * request running alongside sends none, and a failed send is not tried
     * on every page.
     */
    public function markReminded(string $environment, ?int $now = null): void
    {
        $origin = $this->settings->keyOrigin($environment);
        if ($origin === null) {
            return;
        }
        $origin['reminded_at'] = $now ?? time();
        $this->settings->setKeyOrigin($environment, $origin);
    }

    /**
     * When the key of `environment` stops working, as a sentence: '' unless
     * a pairing gave it and said.
     */
    public function expiryNote(string $environment, ?int $now = null): string
    {
        $origin = $this->pairedKey($environment);

        return $origin !== null ? $this->expirySentence($origin['expires_at'], $now) : '';
    }

    /** What the connection says once Wajub refused the paired key because it expired on `expiredAt`. */
    public function expiredMessage(int $expiredAt): string
    {
        return $this->expirySentence($expiredAt, $expiredAt) . ' ' . $this->t->trans('Connect with Wajub again to get a new key.', [], 'Modules.Wajub.Admin');
    }

    /**
     * The warning of an expiring or expired key of `environment`: the day,
     * and what stops.
     */
    public function expiryWarning(string $environment, string $state, int $expiresAt): string
    {
        $date = $this->t->date($expiresAt);
        if ($state === 'expired') {
            return $environment === Settings::LIVE
                ? $this->t->trans('The live key Connect with Wajub created for this shop expired on %date%: Wajub refuses it, and this shop takes no payments. Connect again to get a new key.', ['%date%' => $date], 'Modules.Wajub.Admin')
                : $this->t->trans('The sandbox key Connect with Wajub created for this shop expired on %date%: test payments no longer work. Connect again to get a new key.', ['%date%' => $date], 'Modules.Wajub.Admin');
        }

        return $environment === Settings::LIVE
            ? $this->t->trans('The live key Connect with Wajub created for this shop expires on %date%. After that, Wajub refuses it and this shop takes no payments. Connect again before then.', ['%date%' => $date], 'Modules.Wajub.Admin')
            : $this->t->trans('The sandbox key Connect with Wajub created for this shop expires on %date%. After that, test payments stop. Connect again before then.', ['%date%' => $date], 'Modules.Wajub.Admin');
    }

    /** The sentence for an expiry: '' when it is not known (null). */
    public function expirySentence(?int $expiresAt, ?int $now = null): string
    {
        if ($expiresAt === null) {
            return '';
        }
        if ($expiresAt === 0) {
            return $this->t->trans('The key from Connect with Wajub does not expire.', [], 'Modules.Wajub.Admin');
        }

        return ($now ?? time()) >= $expiresAt
            ? $this->t->trans('The key from Connect with Wajub expired on %date%.', ['%date%' => $this->t->date($expiresAt)], 'Modules.Wajub.Admin')
            : $this->t->trans('The key from Connect with Wajub expires on %date%.', ['%date%' => $this->t->date($expiresAt)], 'Modules.Wajub.Admin');
    }
}

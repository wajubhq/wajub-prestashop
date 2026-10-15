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
 * Which environment the shop takes payments in, and with which key.
 *
 * Each environment pays with its own key only: Sandbox with the sandbox
 * key, Live with the live key. Sandbox without a sandbox key takes no
 * payment at all, whatever live key is saved (sandboxKeyMissing()): the
 * WordPress plugin once fell back to the live key there, and a site that
 * read "Sandbox" took real money. isLive() reads the key that is used, so
 * the back office and the checkout never call real payments "test".
 */
final class Environment
{
    /** Wajub keys: `sk_test.` and `rk_test.` in Sandbox, `sk.` and `rk.` in Live (`sk_test_`, `sk_live_` before). */
    private const SANDBOX_PREFIXES = ['sk_test.', 'rk_test.', 'sk_test_'];

    private const LIVE_PREFIXES = ['sk.', 'rk.', 'sk_live_'];

    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function sandboxSelected(): bool
    {
        return $this->settings->environment() === Settings::SANDBOX;
    }

    /** 'sandbox' or 'live': the environment selected, whose key the client sends, and only its key. */
    public function inUse(): string
    {
        return $this->settings->environment();
    }

    public function hasSandboxKey(): bool
    {
        return $this->settings->secretKey(Settings::SANDBOX) !== '';
    }

    public function hasLiveKey(): bool
    {
        return $this->settings->secretKey(Settings::LIVE) !== '';
    }

    /** The key the API client sends: the selected environment's own, or '' when it has none. */
    public function activeKey(): string
    {
        return $this->settings->secretKey($this->inUse());
    }

    public function isConfigured(): bool
    {
        return $this->activeKey() !== '';
    }

    /** True when payments are real: a live key is in use, whatever the setting says. */
    public function isLive(): bool
    {
        $key = $this->activeKey();
        foreach (self::SANDBOX_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return false;
            }
        }
        foreach (self::LIVE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return !$this->sandboxSelected();
    }

    public function isSandbox(): bool
    {
        return !$this->isLive();
    }

    /**
     * Sandbox is selected, it has no key, and a live key is saved: the shop
     * takes no payment until the sandbox key is added, or Live is chosen to
     * pay with the live key.
     */
    public function sandboxKeyMissing(): bool
    {
        return $this->sandboxSelected() && !$this->hasSandboxKey() && $this->hasLiveKey();
    }

    /**
     * Live is selected, it has no key, and a sandbox key is saved: a shop of
     * a multistore that connected on its own in Sandbox while the scope
     * above it took real payments. It takes no payment until its live key
     * is added, or Sandbox is chosen; never with the live key saved above,
     * which may be another business's (Settings::secretKey()).
     */
    public function liveKeyMissing(): bool
    {
        return !$this->sandboxSelected() && !$this->hasLiveKey() && $this->hasSandboxKey();
    }
}

<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Connection;

use Wajub\PrestaShop\Api\ApiConnectionException;
use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\AuthenticationException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Api\PermissionException;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a save does with a key, wherever it comes from: pasted in the
 * Connection tab, or handed over by "Connect with Wajub" (Pairing\Pairing).
 * Both go through saveKey(), so a paired key passes the same checks as a
 * pasted one.
 *
 * - A new key must have its environment's prefix and be accepted by Wajub,
 *   otherwise the saved one stays and the merchant is told why. A key saved
 *   while Wajub could not be reached is kept, with a warning.
 * - The live key cannot be removed while Live is chosen: real payments use
 *   it. The sandbox key may always go: Sandbox then takes no payment.
 * - Live cannot be chosen without a live key; going back to Sandbox is
 *   refused while Sandbox has no key and a live key takes the payments.
 *
 * Each method answers notices for the merchant: ['type' => success | info |
 * warning | error, 'text' => …].
 */
final class KeyManager
{
    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var callable(string, bool): Client */
    private $clientFor;

    /** @var ConnectionStatus */
    private $status;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /** @var array<string, array{state: string, business: string, environment: string, message: string}> keys checked during this request, by fingerprint */
    private $verified = [];

    /**
     * @param callable(string, bool): Client $clientFor a client for this key, in Sandbox (true) or Live
     */
    public function __construct(Settings $settings, Environment $environment, callable $clientFor, ConnectionStatus $status, Translator $t, Logger $logger)
    {
        $this->settings = $settings;
        $this->environment = $environment;
        $this->clientFor = $clientFor;
        $this->status = $status;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * Asks Wajub whether it knows `key`, once per request. `accepted` and
     * `refused` are its answer; `unverified` means it could not be asked
     * (network, a 5xx, a rate limit), which says nothing about the key.
     *
     * @return array{state: string, business: string, environment: string, message: string}
     */
    public function verify(string $key, string $environment): array
    {
        $fingerprint = Keys::fingerprint($key);
        if (isset($this->verified[$fingerprint])) {
            return $this->verified[$fingerprint];
        }

        $result = ['state' => 'unverified', 'business' => '', 'environment' => '', 'message' => ''];
        try {
            $answer = ($this->clientFor)($key, $environment === Settings::SANDBOX)->checkKey();
            $result['business'] = $answer['business'];
            $result['environment'] = $answer['environment'];
            if ($answer['environment'] !== $environment) {
                $result['state'] = 'refused';
                $result['message'] = $environment === Settings::SANDBOX
                    ? $this->t->trans('Wajub says this key takes real payments. Use a sandbox key here.', [], 'Modules.Wajub.Admin')
                    : $this->t->trans('Wajub says this key only takes test payments. Use a live key here.', [], 'Modules.Wajub.Admin');
            } else {
                $result['state'] = 'accepted';
            }
        } catch (AuthenticationException $e) {
            $result['state'] = 'refused';
            $result['message'] = $this->t->trans('Wajub does not recognise this key. It may have been rolled or deleted, or limited to other IP addresses. Copy it again from the Wajub Dashboard.', [], 'Modules.Wajub.Admin');
        } catch (PermissionException $e) {
            $result['state'] = 'refused';
            $result['message'] = $this->t->trans('This key lacks the permissions the module needs. Use a secret key, or give this restricted key access to payments, refunds and webhooks.', [], 'Modules.Wajub.Admin');
        } catch (ApiException $e) {
            // Any other 4xx is about the key; a 429, a 5xx or the network
            // says nothing about it.
            $clientError = !$e instanceof ApiConnectionException && $e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429;
            if ($clientError) {
                $result['state'] = 'refused';
                $result['message'] = $this->t->trans('Wajub refused this key. Copy it again from the Wajub Dashboard.', [], 'Modules.Wajub.Admin');
            } else {
                $result['message'] = $this->t->trans('Wajub could not be reached to check this key. Try again in a moment.', [], 'Modules.Wajub.Admin');
            }
            $this->logger->error('Key check failed', ['status' => $e->httpStatus, 'code' => $e->errorCode, 'fingerprint' => $fingerprint]);
        } catch (\Throwable $e) {
            $result['message'] = $this->t->trans('Wajub could not be reached to check this key. Try again in a moment.', [], 'Modules.Wajub.Admin');
            $this->logger->error('Key check failed', ['error' => $e->getMessage(), 'fingerprint' => $fingerprint]);
        }

        if ($result['state'] === 'accepted') {
            $result['message'] = $result['business'] !== ''
                ? $this->t->trans('Wajub accepted the key, for %business%.', ['%business%' => $result['business']], 'Modules.Wajub.Admin')
                : $this->t->trans('Wajub accepted the key.', [], 'Modules.Wajub.Admin');
        }

        return $this->verified[$fingerprint] = $result;
    }

    /**
     * Saves `raw` as the secret key of `environment`, when its shape is
     * right and Wajub does not refuse it. Nothing typed, or the key saved
     * already, changes nothing.
     *
     * @return array{saved: bool, notices: list<array{type: string, text: string}>, check: array{state: string, business: string, environment: string, message: string}|null}
     */
    public function saveKey(string $environment, string $raw): array
    {
        $stored = $this->settings->secretKey($environment);
        $key = Keys::normalize($raw);
        if ($key === '' || $key === $stored) {
            return ['saved' => false, 'notices' => [], 'check' => null];
        }

        $label = $environment === Settings::LIVE
            ? $this->t->trans('Live key not saved.', [], 'Modules.Wajub.Admin')
            : $this->t->trans('Sandbox key not saved.', [], 'Modules.Wajub.Admin');
        $problem = Keys::formatProblem($key, $environment, $this->t);
        if ($problem !== '') {
            return ['saved' => false, 'notices' => [self::notice('error', $label . ' ' . $problem)], 'check' => null];
        }

        $check = $this->verify($key, $environment);
        if ($check['state'] === 'refused') {
            return ['saved' => false, 'notices' => [self::notice('error', $label . ' ' . $check['message'])], 'check' => $check];
        }

        $this->settings->setSecretKey($environment, $key);
        $this->logger->info('Key saved', ['environment' => $environment, 'fingerprint' => Keys::fingerprint($key)]);

        $saved = $environment === Settings::LIVE
            ? $this->t->trans('Live key saved.', [], 'Modules.Wajub.Admin')
            : $this->t->trans('Sandbox key saved.', [], 'Modules.Wajub.Admin');
        $detail = $check['state'] === 'accepted'
            ? $check['message']
            : $this->t->trans('Wajub could not be reached to check it: use “Test connection” in a moment.', [], 'Modules.Wajub.Admin');
        if ($check['state'] === 'accepted' && $environment === $this->environment->inUse()) {
            $this->status->remember($key, $environment, 'connected', $check['business']);
        }

        return [
            'saved' => true,
            'notices' => [self::notice($check['state'] === 'accepted' ? 'success' : 'warning', $saved . ' ' . $detail)],
            'check' => $check,
        ];
    }

    /**
     * Removes the secret key of `environment`, unless real payments use it:
     * the live key while Live stays chosen (`environmentAfter`, the choice
     * of the same save).
     *
     * @return list<array{type: string, text: string}>
     */
    public function removeKey(string $environment, string $environmentAfter): array
    {
        if ($this->settings->secretKey($environment) === '' && !$this->settings->secretKeyUnreadable($environment)) {
            return [];
        }
        if ($environment === Settings::LIVE && $environmentAfter === Settings::LIVE) {
            return [self::notice('error', $this->environment->hasSandboxKey()
                ? $this->t->trans('The live key was not removed: the shop takes payments with it. Switch to Sandbox first.', [], 'Modules.Wajub.Admin')
                : $this->t->trans('The live key was not removed: the shop takes payments with it. To stop using it, choose Sandbox and tick “Remove this key” in the same save, or add your sandbox key and switch to Sandbox first.', [], 'Modules.Wajub.Admin'))];
        }

        $this->settings->setSecretKey($environment, '');
        $this->logger->info('Key removed', ['environment' => $environment]);

        return [self::notice('info', $environment === Settings::LIVE
            ? $this->t->trans('Live key removed.', [], 'Modules.Wajub.Admin')
            : $this->t->trans('Sandbox key removed.', [], 'Modules.Wajub.Admin'))];
    }

    /**
     * Chooses the environment payments are taken in, after the keys of the
     * same save were saved or removed.
     *
     * - Live is refused while no live key is saved: the shop would stop
     *   taking payments.
     * - Leaving Live for Sandbox is refused while no sandbox key is saved
     *   but a live one is, for the same reason, unless the live key is
     *   removed in the same save: the merchant wants no payment at all.
     *
     * A shop or a group of a multistore that pays with keys of its own and
     * keeps the environment it reads from above saves it for itself: the
     * scope above may go Live later, with another business's live key.
     *
     * @return list<array{type: string, text: string}>
     */
    public function switchEnvironment(string $requested): array
    {
        $current = $this->settings->environment();
        if (!in_array($requested, Settings::ENVIRONMENTS, true)) {
            return [];
        }
        if ($requested === $current) {
            if ($this->settings->keysSavedFor() === $this->settings->scope()->slug()) {
                $this->settings->keepEnvironment();
            }

            return [];
        }

        if ($requested === Settings::LIVE && !$this->environment->hasLiveKey()) {
            return [self::notice('error', $this->t->trans('Live was not turned on: add your live secret key first. Your shop stays in Sandbox, where no real money moves.', [], 'Modules.Wajub.Admin'))];
        }
        if ($requested === Settings::SANDBOX && !$this->environment->hasSandboxKey() && $this->environment->hasLiveKey()) {
            return [self::notice('error', $this->t->trans('Sandbox was not turned on: no sandbox key is saved, and without one Wajub takes no payments. Add your sandbox secret key first. Your shop stays in Live and keeps taking real payments.', [], 'Modules.Wajub.Admin'))];
        }

        $this->settings->setEnvironment($requested);
        $this->logger->info('Environment changed', ['from' => $current, 'to' => $requested]);

        if ($requested === Settings::LIVE) {
            return [self::notice('success', $this->t->trans('Live is on: this shop now takes real payments.', [], 'Modules.Wajub.Admin'))];
        }

        return [self::notice('warning', $this->environment->hasSandboxKey()
            ? $this->t->trans('Sandbox is on: payments in this shop are test payments, and no real money moves.', [], 'Modules.Wajub.Admin')
            : $this->t->trans('Sandbox is on, but no sandbox key is saved yet: add it to take test payments.', [], 'Modules.Wajub.Admin'))];
    }

    /**
     * What to do when Sandbox is selected with no sandbox key but a live
     * key is saved (Environment::sandboxKeyMissing()).
     */
    public function sandboxKeyMissingMessage(): string
    {
        return $this->t->trans('Sandbox has no key: add your sandbox key, or switch to Live to use your live key.', [], 'Modules.Wajub.Admin');
    }

    /**
     * What to do when Live is selected with no live key but a sandbox key
     * is saved (Environment::liveKeyMissing()): a shop that connected on
     * its own in Sandbox while the scope above it was in Live.
     */
    public function liveKeyMissingMessage(): string
    {
        return $this->t->trans('Live is selected and this shop has no live key of its own, so it takes no payments: connect Live as well to take real payments, or switch to Sandbox to make test payments.', [], 'Modules.Wajub.Admin');
    }

    /**
     * @return array{type: string, text: string}
     */
    public static function notice(string $type, string $text): array
    {
        return ['type' => $type, 'text' => trim($text)];
    }
}

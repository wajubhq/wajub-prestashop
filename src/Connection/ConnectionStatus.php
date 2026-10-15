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
use Wajub\PrestaShop\Pairing\KeyOrigins;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Ago;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Whether the saved key reaches Wajub. The back office reads the last
 * answer, kept ten minutes, and never waits on the API while a page loads:
 * only "Test connection" asks again, and the settings page once by itself
 * when nothing at all is known about the key in use.
 *
 * The answer is kept per scope, environment and key, so a new key or a
 * switch of environment is never shown the old key's answer. The last
 * answer Wajub gave is also kept without expiry, so a page opened hours
 * later says "Last checked 3 hours ago" rather than nothing; it is only
 * ever shown, with its age, never taken for a check.
 */
final class ConnectionStatus
{
    private const TTL = 600;

    /** @var StateStore */
    private $state;

    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var KeyOrigins */
    private $origins;

    /** @var callable(string, bool): Client */
    private $clientFor;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(string, bool): Client $clientFor
     */
    public function __construct(StateStore $state, Settings $settings, Environment $environment, KeyOrigins $origins, callable $clientFor, Translator $t, Logger $logger)
    {
        $this->state = $state;
        $this->settings = $settings;
        $this->environment = $environment;
        $this->origins = $origins;
        $this->clientFor = $clientFor;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * The answer of the last ten minutes for the key in use, without calling
     * the API.
     *
     * - connected: Wajub accepted the key at `checked_at`;
     * - error: Wajub refused it, or could not be reached (`message` says which);
     * - not_configured: no key is saved for the environment in use;
     * - unknown: no answer in the last ten minutes.
     *
     * @return array{status: string, checked_at: int, business: string, message: string, stale: bool}
     */
    public function cached(): array
    {
        $key = $this->environment->activeKey();
        if ($key === '') {
            return self::state('not_configured', 0);
        }

        $stored = $this->state->get($this->recentName($this->environment->inUse(), $key));
        if (!is_array($stored) || !in_array($stored['status'] ?? '', ['connected', 'error'], true)) {
            return self::state('unknown', 0);
        }

        return self::state((string) $stored['status'], (int) ($stored['checked_at'] ?? 0), (string) ($stored['business'] ?? ''), (string) ($stored['message'] ?? ''));
    }

    /**
     * What a page shows, without calling the API: the answer of the last ten
     * minutes when there is one, else the last answer Wajub gave about the
     * key in use, however old, with `stale` set so it is worded with its age.
     *
     * @return array{status: string, checked_at: int, business: string, message: string, stale: bool}
     */
    public function lastKnown(): array
    {
        $state = $this->cached();
        if ($state['status'] !== 'unknown') {
            return $state;
        }

        $last = $this->state->get($this->lastName());
        if (
            !is_array($last)
            || !in_array($last['status'] ?? '', ['connected', 'error'], true)
            || (int) ($last['checked_at'] ?? 0) <= 0
            || ($last['environment'] ?? '') !== $this->environment->inUse()
            || !hash_equals(Keys::fingerprint($this->environment->activeKey()), (string) ($last['fingerprint'] ?? ''))
        ) {
            return $state;
        }

        return self::state((string) $last['status'], (int) $last['checked_at'], (string) ($last['business'] ?? ''), (string) ($last['message'] ?? ''), true);
    }

    /**
     * The business the last answer of `environment` named, whatever key it
     * was about: a pairing warns when a new key belongs to another one.
     */
    public function lastBusiness(string $environment): string
    {
        $last = $this->state->get($this->lastName());

        return is_array($last) && ($last['environment'] ?? '') === $environment ? (string) ($last['business'] ?? '') : '';
    }

    /**
     * Asks Wajub now, with the key payments use, and keeps the answer.
     * Blocking: for "Test connection" only.
     *
     * @return array{status: string, checked_at: int, business: string, message: string, stale: bool}
     */
    public function refresh(): array
    {
        $key = $this->environment->activeKey();
        $environment = $this->environment->inUse();
        if ($key === '') {
            return self::state('not_configured', 0);
        }

        $business = '';
        $message = '';
        try {
            $answer = ($this->clientFor)($key, $environment === Settings::SANDBOX)->checkKey();
            $status = 'connected';
            $business = $answer['business'];
        } catch (AuthenticationException $e) {
            $status = 'error';
            // A key "Connect with Wajub" created, past the day it expires:
            // connecting again is the way out, not copying it again.
            $expiredOn = $this->origins->expiredOn($environment, $key);
            $message = $expiredOn > 0
                ? $this->origins->expiredMessage($expiredOn)
                : $this->t->trans('Wajub refused the saved key. It may have been rolled or deleted: connect again, or copy it again from the Wajub Dashboard.', [], 'Modules.Wajub.Admin');
        } catch (PermissionException $e) {
            $status = 'error';
            $message = $this->t->trans('Wajub accepted the key but it lacks the permissions the module needs. Use a secret key.', [], 'Modules.Wajub.Admin');
        } catch (ApiConnectionException $e) {
            $status = 'error';
            $message = $this->t->trans('Wajub could not be reached from this server. Check its internet access, then try again.', [], 'Modules.Wajub.Admin');
            $this->logger->error('Connection test failed', ['error' => $e->getMessage()]);
        } catch (ApiException $e) {
            $status = 'error';
            $message = $e->httpStatus >= 500 || $e->httpStatus === 429
                ? $this->t->trans('Wajub is not answering right now. Try again in a few minutes.', [], 'Modules.Wajub.Admin')
                : $this->t->trans('Wajub refused the saved key. Connect again, or copy it again from the Wajub Dashboard.', [], 'Modules.Wajub.Admin');
            $this->logger->error('Connection test failed', ['status' => $e->httpStatus, 'code' => $e->errorCode]);
        } catch (\Throwable $e) {
            $status = 'error';
            $message = $this->t->trans('Wajub could not be reached from this server. Try again in a moment.', [], 'Modules.Wajub.Admin');
            $this->logger->error('Connection test failed', ['error' => $e->getMessage()]);
        }

        return $this->remember($key, $environment, $status, $business, $message);
    }

    /**
     * Keeps an answer about `key`, from refresh() or from a key a save just
     * had accepted: ten minutes as the current answer, and until the next
     * one as the last answer, for display.
     *
     * @return array{status: string, checked_at: int, business: string, message: string, stale: bool}
     */
    public function remember(string $key, string $environment, string $status, string $business = '', string $message = ''): array
    {
        $state = self::state($status, time(), $business, $message);
        $this->state->set($this->recentName($environment, $key), $state, self::TTL);

        // A paired key accepted past the day the shop thought it expired:
        // that day is wrong, and no longer said.
        if ($status === 'connected') {
            $this->origins->accepted($environment, $key);
        }

        if (in_array($status, ['connected', 'error'], true)) {
            $this->state->set($this->lastName(), [
                'status' => $status,
                'checked_at' => $state['checked_at'],
                'environment' => $environment,
                'fingerprint' => Keys::fingerprint($key),
                'business' => $business,
                'message' => $message,
            ]);
        }

        return $state;
    }

    /**
     * The words and tone of a state, for the page and for the button's
     * answer. A `stale` answer says how old it is first: it is what Wajub
     * said then, not a check made now.
     *
     * @param array{status: string, checked_at?: int, business?: string, message?: string, stale?: bool} $state
     *
     * @return array{label: string, detail: string, tone: string}
     */
    public function describe(array $state): array
    {
        $checkedAt = (int) ($state['checked_at'] ?? 0);
        $stale = !empty($state['stale']) && $checkedAt > 0 && in_array($state['status'], ['connected', 'error'], true);
        $checked = $checkedAt > 0
            ? $this->t->trans('Checked %time% ago.', ['%time%' => Ago::since($checkedAt, $this->t)], 'Modules.Wajub.Admin')
            : '';
        $business = (string) ($state['business'] ?? '');
        $message = (string) ($state['message'] ?? '');

        switch ($state['status']) {
            case 'connected':
                return [
                    'label' => $business !== ''
                        ? $this->t->trans('Connected to %business%', ['%business%' => $business], 'Modules.Wajub.Admin')
                        : $this->t->trans('Connected', [], 'Modules.Wajub.Admin'),
                    'detail' => $stale
                        ? $this->lastChecked($checkedAt, $this->t->trans('Wajub accepted the saved key.', [], 'Modules.Wajub.Admin'))
                        : trim($this->t->trans('Wajub accepts the saved key.', [], 'Modules.Wajub.Admin') . ' ' . $checked),
                    'tone' => 'success',
                ];
            case 'error':
                $detail = $message !== '' ? $message : $this->t->trans('Wajub refused the saved key.', [], 'Modules.Wajub.Admin');

                // Still red when old: nothing since says the problem is gone,
                // and payments fail while it lasts.
                return $stale
                    ? ['label' => $this->t->trans('Last check failed', [], 'Modules.Wajub.Admin'), 'detail' => $this->lastChecked($checkedAt, $detail), 'tone' => 'error']
                    : ['label' => $this->t->trans('Connection problem', [], 'Modules.Wajub.Admin'), 'detail' => trim($detail . ' ' . $checked), 'tone' => 'error'];
            case 'not_configured':
                // A live key saved does not connect Sandbox: each environment
                // pays with its own key only.
                if ($this->environment->sandboxKeyMissing()) {
                    $detail = $this->t->trans('Sandbox has no key: add your sandbox key, or switch to Live to use your live key.', [], 'Modules.Wajub.Admin');
                } elseif ($this->settings->secretKeyUnreadable($this->environment->inUse())) {
                    $detail = $this->t->trans('The saved key can no longer be read: the shop’s encryption key changed since it was saved, as when a shop is moved without its parameters file. Connect again, or paste the key again.', [], 'Modules.Wajub.Admin');
                } else {
                    $detail = $this->environment->inUse() === Settings::LIVE
                        ? $this->t->trans('Connect your live Wajub account, or add your live secret key, to take payments.', [], 'Modules.Wajub.Admin')
                        : $this->t->trans('Connect your Wajub account, or add your sandbox secret key, to take test payments.', [], 'Modules.Wajub.Admin');
                }

                return ['label' => $this->t->trans('Not connected', [], 'Modules.Wajub.Admin'), 'detail' => $detail, 'tone' => 'warning'];
            default:
                return [
                    'label' => $this->t->trans('Not checked yet', [], 'Modules.Wajub.Admin'),
                    'detail' => $this->t->trans('Use “Test connection” to check that Wajub accepts the saved key.', [], 'Modules.Wajub.Admin'),
                    'tone' => 'neutral',
                ];
        }
    }

    /** "Last checked 3 hours ago: Wajub accepted the saved key." */
    private function lastChecked(int $checkedAt, string $answer): string
    {
        return $this->t->trans('Last checked %time% ago: %answer%', ['%time%' => Ago::since($checkedAt, $this->t), '%answer%' => $answer], 'Modules.Wajub.Admin');
    }

    private function recentName(string $environment, string $key): string
    {
        return 'connection:' . $this->settings->scope()->slug() . ':' . $environment . ':' . Keys::fingerprint($key);
    }

    private function lastName(): string
    {
        return 'connection_last:' . $this->settings->scope()->slug();
    }

    /**
     * @return array{status: string, checked_at: int, business: string, message: string, stale: bool}
     */
    private static function state(string $status, int $checkedAt, string $business = '', string $message = '', bool $stale = false): array
    {
        return ['status' => $status, 'checked_at' => $checkedAt, 'business' => $business, 'message' => $message, 'stale' => $stale];
    }
}

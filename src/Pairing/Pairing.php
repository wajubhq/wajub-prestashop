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

use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\AuthenticationException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Connection\ConnectionStatus;
use Wajub\PrestaShop\Connection\KeyManager;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Keys;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Shop\ShopInfo;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Text;
use Wajub\PrestaShop\Support\Translator;
use Wajub\PrestaShop\Webhook\WebhookRegistration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * "Connect with Wajub": the merchant approves the shop in the Wajub
 * Dashboard, and Wajub creates its secret key. Nothing to copy. A port of
 * the WordPress plugin's Admin\Pairing.
 *
 * It runs the Dashboard's device pairing, the flow behind `wajub login`,
 * from the server, through the back office's AJAX actions
 * (AdminWajubConfigurationController):
 *
 * - start: the shop opens a session and gets a pairing code, a confirmation
 *   address for the merchant, and a polling token it keeps to itself;
 * - poll: the shop asks until the merchant approves or refuses. On approval
 *   the Dashboard hands the key over once; the shop saves it through the
 *   same checks as a pasted key (KeyManager::saveKey()), registers the
 *   webhook with it, and revokes the key a previous pairing gave it;
 * - cancel: the shop forgets the session, which then expires on its own,
 *   unless the page's code is no longer the session's.
 *
 * The key never reaches the browser, an answer, a log line or the state
 * store: only its fingerprint does. The session is kept per employee and
 * per shop scope (StateStore), so a page opened again resumes it, and a lock
 * per employee makes two tabs that poll at once save the key once.
 *
 * What the Dashboard does on its side: only the owner, an administrator or
 * a developer of the Wajub account approves, after a security code; its
 * page starts on Sandbox whatever the shop asked for, and the approver
 * chooses the environment, full access or some permissions, and a key that
 * lasts 90 days or does not expire; the key is handed over once, within 15
 * minutes of the approval; a key a pairing created can revoke itself
 * (`DELETE /keys/current`), a pasted key cannot, and is never asked to.
 *
 * No `return_url` is sent: the only page to come back to is the back
 * office's, whose address names the shop's secret admin folder. The page
 * that started the pairing keeps polling, and says when it is done.
 */
final class Pairing
{
    /** What the module calls: payments, refunds and its webhook. */
    public const PERMISSIONS = ['payment.read', 'payment.write', 'refund.read', 'refund.write', 'webhook.read', 'webhook.write'];

    /** The Dashboard's `DeviceClientType` for this module. */
    public const CLIENT_TYPE = 'prestashop';

    /**
     * The client types the shop may pair as: its own, then what an older
     * Dashboard that does not know it accepts.
     */
    private const CLIENT_TYPES = ['prestashop', 'other'];

    /** Kept a day once an older Dashboard refused `prestashop`. */
    private const CLIENT_TYPE_STATE = 'pairing_client_type';

    private const CLIENT_TYPE_TTL = 86400;

    /** Starts per employee and minute: each one creates a session on the Dashboard. */
    private const START_LIMIT = 5;

    /** Polls per employee and minute: one every `interval` seconds, plus one when the page comes back. */
    private const POLL_LIMIT = 40;

    /**
     * The Dashboard takes 60 polls a minute per session and 600 per address,
     * shared by every shop of a host: never more often than every 3 seconds.
     */
    private const MIN_INTERVAL = 3;

    private const MAX_INTERVAL = 30;

    /** Consecutive failed polls (429, 5xx, no answer) before giving up, as `wajub login` does. */
    private const MAX_ERRORS = 10;

    /** A lock older than this was left by a request that died: it lapses. */
    private const LOCK_TTL = 30;

    /** How long a session lasts on the Dashboard, when its own expiry cannot be trusted. */
    private const SESSION_LIFETIME = 900;

    /** How long the summary of a finished pairing is kept, for a tab that asks again. */
    private const DONE_TTL = 300;

    /** How long a poll that collected the key may take to save it before another poll stops waiting. */
    private const SAVING_GRACE = 120;

    /** The Dashboard's pairing codes: no 0, O, 1 or I. */
    public const CODE_PATTERN = '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/';

    /** Str::random(64): letters and digits only, so it is safe in a header. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{64}$/';

    /** @var PairingClient */
    private $dashboard;

    /** @var StateStore */
    private $state;

    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var KeyManager */
    private $keys;

    /** @var KeyOrigins */
    private $origins;

    /** @var ConnectionStatus */
    private $connection;

    /** @var WebhookRegistration */
    private $webhooks;

    /** @var ShopInfo */
    private $shop;

    /** @var callable(string, bool): Client */
    private $clientFor;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    /**
     * @param callable(string, bool): Client $clientFor
     */
    public function __construct(
        PairingClient $dashboard,
        StateStore $state,
        Settings $settings,
        Environment $environment,
        KeyManager $keys,
        KeyOrigins $origins,
        ConnectionStatus $connection,
        WebhookRegistration $webhooks,
        ShopInfo $shop,
        callable $clientFor,
        Translator $t,
        Logger $logger,
    ) {
        $this->dashboard = $dashboard;
        $this->state = $state;
        $this->settings = $settings;
        $this->environment = $environment;
        $this->keys = $keys;
        $this->origins = $origins;
        $this->connection = $connection;
        $this->webhooks = $webhooks;
        $this->shop = $shop;
        $this->clientFor = $clientFor;
        $this->t = $t;
        $this->logger = $logger;
    }

    // --- Start -----------------------------------------------------------------

    /**
     * Opens a pairing session for `environment` and keeps it for the employee.
     *
     * @return array{code: string, confirm_url: string, expires_at: int, expires_in: int, interval: int, environment: string}
     *
     * @throws PairingException when the session cannot be opened, with words for the merchant
     */
    public function start(string $environment, int $employeeId): array
    {
        if (!in_array($environment, Settings::ENVIRONMENTS, true)) {
            throw new PairingException('invalid_environment', $this->t->trans('Choose Sandbox or Live.', [], 'Modules.Wajub.Admin'), 400);
        }
        if ($this->state->increment('pairing_start:' . $employeeId . ':' . intdiv(time(), 60), 120) > self::START_LIMIT) {
            throw new PairingException('rate_limited', $this->t->trans('Too many attempts. Wait a minute, then try again.', [], 'Modules.Wajub.Admin'), 429);
        }

        $body = $this->sessionBody($environment);
        $answer = $this->dashboard->createSession($body);

        // Every field the Dashboard refused that the shop can do without is
        // changed or dropped in the same retry, and none twice: a retry that
        // would change nothing is not made.
        for ($retries = 0; $answer['http'] === 422 && $retries < 2; ++$retries) {
            $retry = $this->withoutRefused($body, $answer);
            if ($retry === $body) {
                break;
            }
            $body = $retry;
            $answer = $this->dashboard->createSession($body);
        }

        $session = $this->sessionFrom($answer, $environment, $body);
        if ($session === null) {
            $this->logger->error('Pairing session not opened', [
                'http' => $answer['http'],
                'errors' => is_array($answer['body']['errors'] ?? null) ? array_keys($answer['body']['errors']) : [],
            ]);
            if ($answer['http'] === 0) {
                throw new PairingException('unreachable', $this->endMessage('error'), 502);
            }
            if ($answer['http'] >= 500) {
                // Wajub answered, so it was reached: it failed, most often
                // for a moment (a deploy, a database being updated).
                throw new PairingException('unavailable', $this->endMessage('unavailable'), 503);
            }
            if ($answer['http'] === 429) {
                throw new PairingException('busy', $this->t->trans('Wajub is receiving too many requests from this server. Wait a minute, then try again.', [], 'Modules.Wajub.Admin'), 503);
            }

            throw new PairingException('refused', $this->t->trans('Wajub did not accept the request to connect this shop. Try again, or paste your keys instead.', [], 'Modules.Wajub.Admin'), 502);
        }

        $this->keep($employeeId, $session);

        return self::browserSession($session);
    }

    /**
     * What the Dashboard is asked to create: a session named after the shop,
     * for `environment`, asking for what the module calls.
     *
     * @return array<string, mixed>
     */
    private function sessionBody(string $environment): array
    {
        return [
            'device_name' => $this->deviceName(),
            'client_type' => $this->clientType(),
            'environment' => $environment,
            // Tells the sessions of one shop apart without naming its address.
            'client_key' => substr(hash('sha256', $this->shop->baseUrl()), 0, 32),
            // What the confirmation page starts with ticked; the approver
            // decides.
            'permissions' => self::PERMISSIONS,
        ];
    }

    /**
     * The session body without what a 422 refused that the shop can do
     * without: `client_type` (a Dashboard that does not know `prestashop`
     * yet, asked again as `other`, for a day) and `permissions` (only the
     * confirmation page's starting choice). The same body when there is
     * nothing left to change.
     *
     * @param array<string, mixed> $body
     * @param array{http: int, body: array<string, mixed>} $answer
     *
     * @return array<string, mixed>
     */
    private function withoutRefused(array $body, array $answer): array
    {
        $errors = is_array($answer['body']['errors'] ?? null) ? $answer['body']['errors'] : [];
        $fields = array_map(static function ($field): string {
            return explode('.', (string) $field)[0];
        }, array_keys($errors));

        if (in_array('client_type', $fields, true) && $body['client_type'] !== 'other') {
            $body['client_type'] = 'other';
            $this->state->set(self::CLIENT_TYPE_STATE, 'other', self::CLIENT_TYPE_TTL);
        }
        if (in_array('permissions', $fields, true)) {
            unset($body['permissions']);
        }

        return $body;
    }

    private function clientType(): string
    {
        $cached = $this->state->get(self::CLIENT_TYPE_STATE);

        return is_string($cached) && in_array($cached, self::CLIENT_TYPES, true) ? $cached : self::CLIENT_TYPE;
    }

    /**
     * The session the Dashboard opened, once its answer is checked, or null.
     * The confirmation address is the one the merchant is sent to: it must
     * be the Dashboard's own `/connect` page, over HTTPS.
     *
     * @param array{http: int, body: array<string, mixed>} $answer
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    private function sessionFrom(array $answer, string $environment, array $body): ?array
    {
        $data = $answer['body'];
        $code = is_string($data['pairing_code'] ?? null) ? $data['pairing_code'] : '';
        $token = is_string($data['polling_token'] ?? null) ? $data['polling_token'] : '';
        $confirmUrl = is_string($data['confirm_url'] ?? null) ? $data['confirm_url'] : '';
        $expires = is_string($data['expires_at'] ?? null) ? strtotime($data['expires_at']) : false;
        if (
            $answer['http'] !== 201
            || preg_match(self::CODE_PATTERN, $code) !== 1
            || preg_match(self::TOKEN_PATTERN, $token) !== 1
            || !self::isConfirmUrl($confirmUrl)
            || $expires === false
        ) {
            return null;
        }

        // The Dashboard's clock and the shop's may differ: a lifetime that
        // makes no sense becomes the Dashboard's documented one.
        $now = time();
        $lifetime = $expires - $now;
        if ($lifetime < 60 || $lifetime > 3600) {
            $lifetime = self::SESSION_LIFETIME;
        }
        $type = Text::code($data['client_type'] ?? '', 32);

        return [
            'state' => 'pending',
            'token' => $token,
            'code' => $code,
            'confirm_url' => $confirmUrl,
            'environment' => $environment,
            'client_type' => in_array($type, self::CLIENT_TYPES, true) ? $type : (string) $body['client_type'],
            'device_name' => (string) $body['device_name'],
            'interval' => min(self::MAX_INTERVAL, max(self::MIN_INTERVAL, (int) ($data['interval'] ?? 0))),
            'expires_at' => $now + $lifetime,
            'errors' => 0,
        ];
    }

    public static function isConfirmUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        // No user, password or port: the address the Dashboard gives has
        // none, so one that has them was not written by it.
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === (string) parse_url(PairingClient::DASHBOARD, PHP_URL_HOST)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port'])
            && ($parts['path'] ?? '') === '/connect';
    }

    // --- Poll ------------------------------------------------------------------

    /**
     * Asks the Dashboard once whether the merchant decided, for the
     * employee's session. `status` is pending, authorized, denied, expired,
     * consumed, replaced or error; every status but pending ends the session
     * (replaced ends the page's, not the new one) and carries a `message`.
     *
     * `code` is the pairing code the page shows, '' when it does not say: a
     * page whose code is no longer the session's was replaced by a start in
     * another tab, and is told so.
     *
     * @return array<string, mixed>
     */
    public function poll(int $employeeId, string $code = ''): array
    {
        // Too many polls, or another tab polling right now: the browser asks
        // again at its next turn, and the Dashboard is not asked.
        if ($this->state->increment('pairing_poll:' . $employeeId . ':' . intdiv(time(), 60), 120) > self::POLL_LIMIT) {
            return ['status' => 'pending'];
        }
        if (!$this->state->add($this->lockName($employeeId), time(), self::LOCK_TTL)) {
            return ['status' => 'pending'];
        }

        try {
            // Read under the lock only: what another tab did before it is
            // then known, the key it saved included.
            return $this->pollLocked($employeeId, $code);
        } finally {
            $this->state->delete($this->lockName($employeeId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pollLocked(int $employeeId, string $code): array
    {
        $session = $this->state->get($this->sessionName($employeeId));
        if (!is_array($session)) {
            return $this->ended('expired');
        }

        $state = (string) ($session['state'] ?? 'pending');
        if ($state === 'done') {
            // Another tab collected the key: its summary, without asking the
            // Dashboard, which would only say the session was used.
            return is_array($session['summary'] ?? null) ? $session['summary'] : $this->ended('consumed');
        }
        if ($code !== '' && !hash_equals((string) ($session['code'] ?? ''), $code)) {
            return $this->ended('replaced');
        }
        if ($state === 'saving') {
            if (time() - (int) ($session['saving_at'] ?? 0) < self::SAVING_GRACE) {
                return ['status' => 'pending'];
            }
            // The request that collected the key stopped before it said how
            // the save went.
            $this->cancel($employeeId);

            return $this->ended('consumed');
        }

        $token = (string) ($session['token'] ?? '');
        if ($token === '') {
            $this->cancel($employeeId);

            return $this->ended('expired');
        }

        $answer = $this->dashboard->poll($token);
        $http = $answer['http'];
        $status = is_string($answer['body']['status'] ?? null) ? $answer['body']['status'] : '';

        if ($http === 200 && $status === 'authorized') {
            return $this->collect($employeeId, $session, $answer['body']);
        }
        if ($http === 200 && $status === 'pending') {
            $session['errors'] = 0;
            $this->keep($employeeId, $session);

            return ['status' => 'pending'];
        }
        if ($http === 403 && $status === 'denied') {
            $this->cancel($employeeId);

            return $this->ended('denied');
        }
        if ($http === 404 || ($http === 410 && $status !== 'consumed')) {
            $this->cancel($employeeId);

            return $this->ended('expired');
        }
        if ($http === 410) {
            return $this->consumed($employeeId, $token);
        }

        // Throttled (429), a 5xx, no answer, or an answer without a pairing
        // status: the session may still be approved. Asked again, up to the
        // tenth failure in a row.
        $session['errors'] = (int) ($session['errors'] ?? 0) + 1;
        if ($session['errors'] >= self::MAX_ERRORS) {
            $this->logger->error('Pairing given up', ['http' => $http, 'errors' => $session['errors']]);
            $this->cancel($employeeId);

            return $this->ended('error');
        }
        $this->keep($employeeId, $session);

        return ['status' => 'pending'];
    }

    /**
     * The Dashboard handed the key over already, most often to another poll
     * of this shop that went past the lock (two requests that took it at
     * once). The session, read again, says what became of it.
     *
     * @return array<string, mixed>
     */
    private function consumed(int $employeeId, string $token): array
    {
        $now = $this->state->get($this->sessionName($employeeId));
        if (!is_array($now)) {
            return $this->ended('consumed');
        }

        $state = (string) ($now['state'] ?? 'pending');
        if ($state === 'done') {
            return is_array($now['summary'] ?? null) ? $now['summary'] : $this->ended('consumed');
        }
        if ($state === 'saving' && time() - (int) ($now['saving_at'] ?? 0) < self::SAVING_GRACE) {
            return ['status' => 'pending'];
        }
        if (hash_equals((string) ($now['token'] ?? ''), $token)) {
            $this->cancel($employeeId);
        }

        return $this->ended('consumed');
    }

    /**
     * The merchant approved: save the key the Dashboard handed over, then
     * register the webhook with it, then revoke the key it replaces.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function collect(int $employeeId, array $session, array $body): array
    {
        $key = Keys::normalize(is_string($body['key'] ?? null) ? $body['key'] : '');
        $requested = (string) ($session['environment'] ?? '');
        $environment = is_string($body['environment'] ?? null) ? $body['environment'] : '';
        $business = Text::line($body['business'] ?? '', 255);
        $device = (string) ($session['device_name'] ?? '') !== '' ? (string) $session['device_name'] : $this->deviceName();

        // The Dashboard handed the key over once and will not again. While it
        // is being saved, a tab that broke a stale lock waits rather than
        // reading the session as used.
        $session['state'] = 'saving';
        $session['saving_at'] = time();
        $this->keep($employeeId, $session);

        if ($key === '') {
            $this->cancel($employeeId);

            return ['status' => 'error', 'message' => $this->t->trans('Wajub approved the request but sent no key. Start again.', [], 'Modules.Wajub.Admin'), 'messages' => []];
        }

        // a. The environment Wajub names, which the merchant may have changed
        // on the confirmation page, must be the one the key's prefix says.
        if (!in_array($environment, Settings::ENVIRONMENTS, true) || Keys::environmentOf($key) !== $environment) {
            $this->logger->error('Paired key refused', ['fingerprint' => Keys::fingerprint($key), 'environment' => $environment, 'reason' => 'environment']);
            $this->cancel($employeeId);

            return $this->keyRefused([$this->t->trans('Its prefix does not match the environment Wajub gave.', [], 'Modules.Wajub.Admin')], $device);
        }

        // b. What the save replaces, read before it: the key, how it came,
        // and the business the last connection check named.
        $previous = $this->settings->secretKey($environment);
        $previousOrigin = $this->origins->origin($environment);
        $lastBusiness = $this->connection->lastBusiness($environment);

        // c. Saved as a pasted key is: its prefix checked, and Wajub asked.
        // What it refuses stays unsaved, and its words come back.
        $saved = null;
        if (!hash_equals($previous, $key)) {
            $saved = $this->keys->saveKey($environment, $key);
            if (!$saved['saved']) {
                $this->logger->error('Paired key refused', ['fingerprint' => Keys::fingerprint($key), 'environment' => $environment, 'reason' => 'checks']);
                $this->cancel($employeeId);

                return $this->keyRefused(array_map(static function (array $notice): string {
                    return $notice['text'];
                }, $saved['notices']), $device);
            }
        }
        $this->logger->info('Paired key saved', ['fingerprint' => Keys::fingerprint($key), 'environment' => $environment]);

        $warnings = [];
        // Saved while Wajub could not be asked about it: said, as for a
        // pasted key.
        if (isset($saved['check']['state']) && $saved['check']['state'] !== 'accepted') {
            foreach ($saved['notices'] as $notice) {
                $warnings[] = $notice['text'];
            }
        }
        if ($environment !== $requested) {
            $warnings[] = $this->t->trans('You approved %environment% instead: that key was saved.', ['%environment%' => $this->environmentLabel($environment)], 'Modules.Wajub.Admin');
        }
        if ($this->environment->sandboxKeyMissing()) {
            $warnings[] = $this->t->trans('Sandbox has no key, so Wajub takes no payments yet. Connect Sandbox as well to make test payments, or switch to Live to take real payments with this key.', [], 'Modules.Wajub.Admin');
        }
        if ($this->environment->liveKeyMissing()) {
            // A shop of a multistore that connected on its own in Sandbox
            // while it read Live from above: it keeps Live (it took real
            // payments), but never with the live key saved above, which may
            // be another business's (Settings::secretKey()).
            $warnings[] = $this->keys->liveKeyMissingMessage();
        }

        // d. A restricted key reaches `GET /` whatever it lacks, so the save
        // could not tell. What the Dashboard says it granted; the prefix
        // when it does not say, or says otherwise.
        $keyType = $this->keyTypeOf($key, $body);
        $permissions = is_array($body['permissions'] ?? null)
            ? array_values(array_filter(array_map(static function ($permission): string {
                return Text::code($permission, 64);
            }, $body['permissions'])))
            : null;
        if ($keyType === 'restricted' && $permissions !== null) {
            $missing = array_values(array_diff(self::PERMISSIONS, $permissions));
            if ($missing !== []) {
                $warnings[] = $this->t->trans('This key is restricted and lacks these permissions: %permissions%. If payments fail, connect again with full access, or with the payments, refunds and webhooks permissions.', ['%permissions%' => implode(', ', $missing)], 'Modules.Wajub.Admin');
            }
        } elseif ($keyType === 'restricted') {
            $warnings[] = $this->t->trans('This is a restricted key. If payments fail, connect again with full access, or with the payments, refunds and webhooks permissions.', [], 'Modules.Wajub.Admin');
        }

        // e. Where this environment's key came from, so a later pairing knows
        // it may revoke it and the page can say so; when it expires; and
        // where the shop was, so a copy of it knows it is one.
        $expiresAt = self::expiryOf($body);
        $this->settings->setKeyOrigin($environment, [
            'via' => 'pairing',
            'business' => $business,
            'client_type' => (string) ($session['client_type'] ?? self::CLIENT_TYPE),
            'device_name' => (string) ($session['device_name'] ?? ''),
            'fp' => Keys::fingerprint($key),
            'at' => time(),
            'environment' => $environment,
            'key_type' => $keyType,
            'permissions' => $keyType === 'restricted' ? $permissions : null,
            'home' => $this->siteAddress(),
            'site_type' => $this->shop->isProduction() ? 'production' : 'development',
        ] + ($expiresAt !== null ? ['expires_at' => $expiresAt] : []));

        // f. The merchant approved from another business than the one the
        // shop was connected to: its payments do not follow.
        if ($lastBusiness !== '' && $business !== '' && $lastBusiness !== $business) {
            $warnings[] = $this->t->trans('This key belongs to %new%, not %old%. Payments already taken stay in %old%.', ['%new%' => $business, '%old%' => $lastBusiness], 'Modules.Wajub.Admin');
        }

        // g. The webhook, with the new key: it may belong to another business.
        $webhook = $this->webhooks->register(($this->clientFor)($key, $environment === Settings::SANDBOX), $environment);

        // h. Only now that the new key is saved: the one it replaces, when a
        // pairing gave it. A pasted key is never touched.
        $revoke = $this->revokeReplaced($environment, $previous, $key, $previousOrigin);
        if ($revoke !== '') {
            $warnings[] = $revoke;
        }

        // i. Kept a few minutes for another tab, without the token or the key.
        $connected = $business !== ''
            ? $this->t->trans('Connected to %business% in %environment%. The key is saved in this shop.', ['%business%' => $business, '%environment%' => $this->environmentLabel($environment)], 'Modules.Wajub.Admin')
            : $this->t->trans('Connected in %environment%. The key is saved in this shop.', ['%environment%' => $this->environmentLabel($environment)], 'Modules.Wajub.Admin');
        $summary = [
            'status' => 'authorized',
            'environment' => $environment,
            'requested_environment' => $requested,
            'business' => $business,
            'message' => trim($connected . ' ' . $this->origins->expirySentence($expiresAt)),
            'webhook' => ['status' => $webhook['status'], 'message' => $webhook['message']],
            'warnings' => $warnings,
        ];
        $this->state->set($this->sessionName($employeeId), ['state' => 'done', 'summary' => $summary], self::DONE_TTL);

        return $summary;
    }

    /**
     * Revokes the key `key` replaced, when a pairing gave it, and says what
     * the merchant must do when Wajub would not: '' when nothing is left.
     *
     * A copy of the shop (a staging shop cloned from the store, with its
     * database) carries the store's key and the origin that says a pairing
     * gave it: revoked from the copy, the key would stop the store's
     * payments. So the key is revoked only from a production shop at the
     * address it was paired from. Otherwise it is left, and the merchant is
     * told where to delete it if no other copy uses it.
     *
     * In a multistore, the key a shop or a group replaced may be the one
     * saved for all shops (or for its group), which it only inherited: the
     * new key is saved for this scope alone, and the other shops still pay
     * with the old one. A key still saved anywhere is never revoked.
     *
     * @param array<string, mixed>|null $previousOrigin KeyOrigins::origin()
     */
    private function revokeReplaced(string $environment, string $previous, string $key, ?array $previousOrigin): string
    {
        if (
            $previous === ''
            || hash_equals($previous, $key)
            || $previousOrigin === null
            || !hash_equals((string) $previousOrigin['fp'], Keys::fingerprint($previous))
        ) {
            return '';
        }

        if ($this->settings->secretKeySavedAnywhere($environment, $previous)) {
            $this->logger->info('Replaced paired key left: other shops still use it', [
                'fingerprint' => Keys::fingerprint($previous),
                'environment' => $environment,
                'scope' => $this->settings->scope()->slug(),
            ]);

            return $this->t->trans('The key this shop used before was not deleted: other shops of this PrestaShop still use it.', [], 'Modules.Wajub.Admin');
        }

        $home = (string) $previousOrigin['home'];
        if (!$this->shop->isProduction() || ($home !== '' && !hash_equals($home, $this->siteAddress()))) {
            $this->logger->info('Replaced paired key left: this may be a copy of the shop that paired it', [
                'fingerprint' => Keys::fingerprint($previous),
                'environment' => $environment,
                'production' => $this->shop->isProduction(),
                'same_address' => $home === '' ? null : hash_equals($home, $this->siteAddress()),
            ]);

            return $this->t->trans('The key this shop used before was not deleted: another copy of this shop, such as the shop it was copied from, may still use it. If none does, delete it in the Wajub Dashboard, under Developers, API keys.', [], 'Modules.Wajub.Admin');
        }

        try {
            ($this->clientFor)($previous, $environment === Settings::SANDBOX)->revokeCurrentKey();
            $this->logger->info('Replaced paired key revoked', ['fingerprint' => Keys::fingerprint($previous), 'environment' => $environment]);

            return '';
        } catch (AuthenticationException $e) {
            // Wajub no longer knows it: deleted in the Dashboard already.
            return '';
        } catch (\Throwable $e) {
            // A 409 (Wajub does not know it as a key a pairing created), or
            // Wajub not reached: either way the old key still works. Read by
            // its status only, never by its words.
            $this->logger->error('Replaced paired key not revoked', [
                'fingerprint' => Keys::fingerprint($previous),
                'environment' => $environment,
                'status' => $e instanceof ApiException ? $e->httpStatus : 0,
            ]);

            return $this->t->trans('The key this shop used before is still active. Delete it in the Wajub Dashboard, under Developers, API keys.', [], 'Modules.Wajub.Admin');
        }
    }

    /**
     * @param list<string> $reasons
     *
     * @return array{status: string, message: string, messages: list<string>}
     */
    private function keyRefused(array $reasons, string $device): array
    {
        return [
            'status' => 'error',
            'message' => $this->t->trans('Wajub created a key, but this shop could not use it: %reasons% In the Wajub Dashboard, under Developers, API keys, delete the key Wajub created for “%device%”, then connect again.', [
                '%reasons%' => $reasons !== [] ? implode(' ', $reasons) : $this->t->trans('Wajub refused it.', [], 'Modules.Wajub.Admin'),
                '%device%' => $device,
            ], 'Modules.Wajub.Admin'),
            'messages' => $reasons,
        ];
    }

    /** What the shop calls itself in Wajub: its name and its host, "Boutique (boutique.cm)". */
    private function deviceName(): string
    {
        $host = (string) parse_url($this->shop->baseUrl(), PHP_URL_HOST);
        $name = Text::line(html_entity_decode($this->shop->name(), ENT_QUOTES, 'UTF-8'));

        return mb_substr($name !== '' ? $name . ' (' . $host . ')' : $host, 0, 120);
    }

    /**
     * The shop's address as a copy of it would not share it: host, port and
     * path, without the scheme (a move to HTTPS is the same shop) or `www.`.
     */
    private function siteAddress(): string
    {
        return self::addressOf($this->shop->baseUrl());
    }

    /**
     * An address as a copy of the shop would not share it: host, port and
     * path, without the scheme or `www.`. '' for no address.
     */
    public static function addressOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || (string) ($parts['host'] ?? '') === '') {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * @return array{status: string, message: string}
     */
    private function ended(string $status): array
    {
        return ['status' => $status, 'message' => $this->endMessage($status)];
    }

    public function endMessage(string $status): string
    {
        switch ($status) {
            case 'denied':
                return $this->t->trans('The request was refused in Wajub. No key was created. Try again, or paste your keys instead.', [], 'Modules.Wajub.Admin');
            case 'expired':
                // The Dashboard answers the same 410 for both: a request not
                // approved in time, and a key not collected in time.
                return $this->t->trans('The request expired: it was not approved within 15 minutes, or this shop did not collect the key within 15 minutes of the approval. Start again.', [], 'Modules.Wajub.Admin');
            case 'consumed':
                return $this->t->trans('This request was already used. Start again to connect this shop.', [], 'Modules.Wajub.Admin');
            case 'replaced':
                return $this->t->trans('You started a new request in another tab or window, so this code no longer works. Use the new code there, or start again here.', [], 'Modules.Wajub.Admin');
            case 'unavailable':
                return $this->t->trans('Wajub is not available right now, maybe because it is being updated. Try again in a few minutes, or paste your keys instead.', [], 'Modules.Wajub.Admin');
            default:
                return $this->t->trans('Wajub could not be reached. Check that this server can make outgoing HTTPS requests, then try again, or paste your keys instead.', [], 'Modules.Wajub.Admin');
        }
    }

    public function environmentLabel(string $environment): string
    {
        return $environment === Settings::LIVE
            ? $this->t->trans('Live', [], 'Modules.Wajub.Admin')
            : $this->t->trans('Sandbox', [], 'Modules.Wajub.Admin');
    }

    // --- Session ----------------------------------------------------------------

    public function cancel(int $employeeId): void
    {
        $this->state->delete($this->sessionName($employeeId));
    }

    /**
     * "Cancel" on a page that shows the code `code` ('' when it does not
     * say): the session is forgotten, unless a start in another tab replaced
     * it, which is left to that tab, or another tab collected the key
     * already: its summary then, as a poll gives it.
     *
     * @return array<string, mixed>
     */
    public function cancelShown(int $employeeId, string $code = ''): array
    {
        $session = $this->state->get($this->sessionName($employeeId));
        if (is_array($session) && ($session['state'] ?? '') === 'done' && is_array($session['summary'] ?? null)) {
            return $session['summary'];
        }
        if ($code !== '' && is_array($session) && !hash_equals((string) ($session['code'] ?? ''), $code)) {
            return $this->ended('replaced');
        }
        $this->cancel($employeeId);

        return ['status' => 'cancelled'];
    }

    /**
     * The employee's session waiting for approval, as the browser may see
     * it: never the polling token. Null when there is none.
     *
     * @return array{code: string, confirm_url: string, expires_at: int, expires_in: int, interval: int, environment: string}|null
     */
    public function pending(int $employeeId): ?array
    {
        $session = $this->state->get($this->sessionName($employeeId));
        if (!is_array($session) || !in_array($session['state'] ?? '', ['pending', 'saving'], true)) {
            return null;
        }

        return self::browserSession($session);
    }

    /**
     * What the Connection tab says of one environment: whether its key came
     * from a pairing (and when), its expiry, and whether its button connects
     * or reconnects.
     *
     * @return array{environment: string, label: string, connected_on: string, expiry: string, expiry_state: string, button: string, has_key: bool}
     */
    public function environmentState(string $environment): array
    {
        $hasKey = $this->settings->secretKey($environment) !== '';
        $origin = $this->origins->pairedKey($environment);
        $expiryState = $this->origins->expiryState($environment);

        if ($environment === Settings::LIVE) {
            $button = $hasKey ? $this->t->trans('Reconnect Live', [], 'Modules.Wajub.Admin') : $this->t->trans('Connect Live', [], 'Modules.Wajub.Admin');
        } else {
            $button = $hasKey ? $this->t->trans('Reconnect Sandbox', [], 'Modules.Wajub.Admin') : $this->t->trans('Connect Sandbox', [], 'Modules.Wajub.Admin');
        }

        return [
            'environment' => $environment,
            'label' => $this->environmentLabel($environment),
            'connected_on' => $origin !== null && $origin['at'] > 0
                ? $this->t->trans('Connected with Wajub on %date%', ['%date%' => $this->t->date($origin['at'])], 'Modules.Wajub.Admin')
                : '',
            'expiry' => $expiryState !== '' && $origin !== null
                ? $this->origins->expiryWarning($environment, $expiryState, (int) $origin['expires_at'])
                : $this->origins->expiryNote($environment),
            'expiry_state' => $expiryState,
            'button' => $button,
            'has_key' => $hasKey,
        ];
    }

    /**
     * When the key the poll handed over stops working: a time, 0 for a key
     * that does not expire, null when the Dashboard did not say.
     * `expires_at` first, then `key_expires_at`, the same value under the
     * name `wajub login` reads.
     *
     * @param array<string, mixed> $body
     */
    private static function expiryOf(array $body): ?int
    {
        foreach (['expires_at', 'key_expires_at'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null || $body[$field] === '') {
                return 0;
            }
            $time = is_string($body[$field]) ? strtotime($body[$field]) : false;

            return $time !== false && $time > 0 ? $time : null;
        }

        return null;
    }

    /**
     * 'private' (full access) or 'restricted': what the Dashboard says,
     * checked against the prefix (`rk` for restricted). When they disagree,
     * the key is taken as restricted, so the merchant is told what it may
     * lack rather than nothing.
     *
     * @param array<string, mixed> $body
     */
    private function keyTypeOf(string $key, array $body): string
    {
        $byPrefix = Keys::typeOf($key);
        $said = is_string($body['key_type'] ?? null) ? $body['key_type'] : '';
        if (!in_array($said, ['private', 'restricted'], true)) {
            return $byPrefix;
        }
        if ($said !== $byPrefix) {
            $this->logger->error('Paired key type does not match its prefix', ['fingerprint' => Keys::fingerprint($key), 'key_type' => $said]);

            return 'restricted';
        }

        return $said;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array{code: string, confirm_url: string, expires_at: int, expires_in: int, interval: int, environment: string}
     */
    private static function browserSession(array $session): array
    {
        $expiresAt = (int) ($session['expires_at'] ?? 0);

        return [
            'code' => (string) ($session['code'] ?? ''),
            'confirm_url' => (string) ($session['confirm_url'] ?? ''),
            'expires_at' => $expiresAt,
            // The browser counts down from this, not from its own clock.
            'expires_in' => max(0, $expiresAt - time()),
            'interval' => (int) ($session['interval'] ?? self::MIN_INTERVAL),
            'environment' => (string) ($session['environment'] ?? ''),
        ];
    }

    /**
     * Keeps the session until an hour after the Dashboard's expiry: a
     * session approved late can still be collected, and its key is not left
     * behind.
     *
     * @param array<string, mixed> $session
     */
    private function keep(int $employeeId, array $session): void
    {
        $left = max(60, (int) ($session['expires_at'] ?? 0) - time());
        $this->state->set($this->sessionName($employeeId), $session, $left + 3600);
    }

    /** An employee has one session per shop scope: each scope saves its own keys. */
    private function sessionName(int $employeeId): string
    {
        return 'pairing:' . $employeeId . ':' . $this->settings->scope()->slug();
    }

    private function lockName(int $employeeId): string
    {
        return 'pairing_lock:' . $employeeId;
    }
}

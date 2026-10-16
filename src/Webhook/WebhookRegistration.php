<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

use Wajub\PrestaShop\Api\ApiConnectionException;
use Wajub\PrestaShop\Api\ApiException;
use Wajub\PrestaShop\Api\AuthenticationException;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Api\InvalidRequestException;
use Wajub\PrestaShop\Api\PermissionException;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Shop\ShopInfo;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Registers the shop's webhook address with Wajub, through the API, so the
 * merchant never copies an address in and a signing secret out by hand.
 *
 * For each environment with a key: find an endpoint with the shop's address
 * (`GET /webhooks?search=`), or create one (`POST /webhooks`). A found one
 * is switched back on and given the events it lacks. Its signing secret is
 * only shown when it is created or rotated, so a found endpoint whose secret
 * the shop does not have gets a new one (`POST /webhooks/{id}/rotate-secret`).
 *
 * Wajub refuses a private or local address, and a non-https one in Live: the
 * merchant then gets the manual steps, with the reason.
 */
final class WebhookRegistration
{
    /**
     * What the module acts on: payments, refunds and disputes. Named one by
     * one rather than `*`, so the shop is not sent transfer, balance and
     * customer events it has nothing to do with.
     *
     * The plugin's, and the events that carry what the module's handlers
     * keep besides: a payment paid in instalments is `partial` between two
     * of them, which no `payment.partial` says (Wajub has no such event:
     * payments/lifecycle), but each instalment's `payment.split.*` does,
     * with the whole payment; and a live refund under way sends
     * `refund.processing`, which a refund asked again reads
     * (Refund\RefundSync). Wajub accepts any name and sends nothing for one
     * it does not have: only names of its catalogue
     * (developer-tools/webhooks/events). A shop registered before has its
     * endpoint given those it lacks (WebhookRegistration::adopt(), and
     * upgrade/upgrade-1.0.1.php).
     */
    public const EVENTS = [
        'payment.succeeded',
        'payment.failed',
        'payment.expired',
        'payment.cancelled',
        'payment.processing',
        'payment.split.processing',
        'payment.split.succeeded',
        'payment.split.failed',
        'refund.created',
        'refund.processing',
        'refund.succeeded',
        'refund.failed',
        'refund.cancelled',
        'dispute.created',
        'dispute.updated',
        'dispute.won',
        'dispute.lost',
        'dispute.closed',
    ];

    /** The statuses of a registration that left the webhook working. */
    public const WORKING = ['registered', 'updated', 'existing', 'rotated'];

    /** @var Settings */
    private $settings;

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
    public function __construct(Settings $settings, ShopInfo $shop, callable $clientFor, Translator $t, Logger $logger)
    {
        $this->settings = $settings;
        $this->shop = $shop;
        $this->clientFor = $clientFor;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * Registers the webhook for every environment that has a key.
     *
     * @param bool $rotate give the endpoint a new signing secret even when one is saved
     *
     * @return array<string, array{environment: string, status: string, reason: string, message: string, endpoint_id: string}>
     */
    public function registerAll(bool $rotate = false): array
    {
        $results = [];
        foreach (Settings::ENVIRONMENTS as $environment) {
            $key = $this->settings->secretKey($environment);
            if ($key !== '') {
                $results[$environment] = $this->register(($this->clientFor)($key, $environment === Settings::SANDBOX), $environment, $rotate);
            }
        }

        return $results;
    }

    /**
     * @return array{environment: string, status: string, reason: string, message: string, endpoint_id: string}
     *                                                                                                          status: registered | updated | existing | rotated | manual | error
     */
    public function register(Client $client, string $environment, bool $rotate = false): array
    {
        $url = $this->shop->webhookUrl();
        $local = self::localAddressProblem($url, $environment);
        if ($local !== '') {
            return self::result($environment, 'manual', $local, $this->manualReason($local, $environment, $url));
        }

        try {
            $endpoint = $this->findEndpoint($client, $url);
            if ($endpoint === null) {
                return $this->create($client, $environment, $url);
            }

            return $this->adopt($client, $environment, $url, $endpoint, $rotate);
        } catch (AuthenticationException|PermissionException $e) {
            return self::result($environment, 'manual', 'key', $this->t->trans('Your key cannot manage webhooks (a restricted key may lack that permission). Set the webhook up by hand, or use a secret key.', [], 'Modules.Wajub.Admin'));
        } catch (InvalidRequestException $e) {
            $reason = self::refusalReason($e);

            return self::result($environment, 'manual', $reason, $this->manualReason($reason, $environment, $url, self::apiMessage($e)));
        } catch (ApiConnectionException $e) {
            $this->logger->error('Webhook registration failed', ['environment' => $environment, 'error' => $e->getMessage()]);

            return self::result($environment, 'error', 'network', $this->t->trans('Wajub could not be reached. Try again in a moment.', [], 'Modules.Wajub.Admin'));
        } catch (ApiException $e) {
            $this->logger->error('Webhook registration failed', ['environment' => $environment, 'status' => $e->httpStatus, 'code' => $e->errorCode]);

            return self::result($environment, 'error', 'api', $this->t->trans('Wajub could not register the webhook right now. Try again in a moment, or set it up by hand.', [], 'Modules.Wajub.Admin'));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook registration failed', ['environment' => $environment, 'error' => $e->getMessage()]);

            return self::result($environment, 'error', 'unexpected', $this->t->trans('The webhook could not be registered. Try again, or set it up by hand.', [], 'Modules.Wajub.Admin'));
        }
    }

    /**
     * Gives the endpoint this scope registered itself, in each environment
     * with a key, the events of EVENTS it lacks: a version of the module
     * that listens to more events updates the shops registered before it
     * (upgrade/upgrade-1.0.1.php). Nothing else: never an endpoint created,
     * never a new secret, nothing for an endpoint Wajub no longer has with
     * the shop's address (the Webhooks tab says to register it again).
     * By environment: 'updated', 'current' (it had them all, or `*`),
     * 'none' (no endpoint of its own here) or 'error' (Wajub did not answer:
     * "Configure automatically" does the same later).
     *
     * @return array<string, string>
     */
    public function addMissingEvents(): array
    {
        $results = [];
        foreach (Settings::ENVIRONMENTS as $environment) {
            $saved = $this->settings->webhookEndpointSavedHere($environment);
            $key = $this->settings->secretKey($environment);
            if ($saved === null || $saved['id'] === '' || $key === '') {
                $results[$environment] = 'none';
                continue;
            }
            try {
                $client = ($this->clientFor)($key, $environment === Settings::SANDBOX);
                $endpoint = null;
                foreach ($client->listWebhookEndpoints($saved['url']) as $found) {
                    if ((string) ($found['id'] ?? '') === $saved['id']) {
                        $endpoint = $found;
                        break;
                    }
                }
                if ($endpoint === null) {
                    $results[$environment] = 'none';
                    continue;
                }
                $events = is_array($endpoint['events'] ?? null) ? array_map('strval', $endpoint['events']) : [];
                $missing = in_array('*', $events, true) || $events === [] ? [] : array_diff(self::EVENTS, $events);
                if ($missing === []) {
                    $results[$environment] = 'current';
                    continue;
                }
                $client->updateWebhookEndpoint($saved['id'], ['events' => array_values(array_unique(array_merge($events, $missing)))]);
                $results[$environment] = 'updated';
            } catch (\Throwable $e) {
                $this->logger->error('The webhook could not be given the events it lacks', ['environment' => $environment, 'error' => $e->getMessage()]);
                $results[$environment] = 'error';
            }
        }

        return $results;
    }

    /**
     * Why Wajub cannot reach `url`, before asking it: a local or private host
     * ('private_url'), or http in Live ('https_required'). '' when it may.
     */
    public static function localAddressProblem(string $url, string $environment): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($host === '') {
            return 'invalid_url';
        }

        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'private_url';
            }
        } elseif ($host === 'localhost' || !str_contains($host, '.')
            || preg_match('/\.(localhost|local|test|invalid|example|internal|lan|home|lndo\.site|ddev\.site)$/', $host) === 1) {
            return 'private_url';
        }

        if ($environment === Settings::LIVE && $scheme !== 'https') {
            return 'https_required';
        }

        return '';
    }

    /**
     * The Wajub CLI's command that forwards the account's Sandbox events to
     * `url`, for a shop Wajub cannot reach (a local address): the address
     * quoted when a shell would read it otherwise (`?`, `&` in the address
     * of a shop without friendly URLs).
     */
    public static function listenCommand(string $url): string
    {
        $quoted = preg_match('#^[A-Za-z0-9._~:/%+=-]+$#', $url) === 1 ? $url : "'" . str_replace("'", '%27', $url) . "'";

        return 'wajub listen --forward-to ' . $quoted;
    }

    /**
     * Whether the endpoint registered for `environment` no longer has the
     * shop's webhook address: friendly URLs, the domain or the default
     * language changed since. The merchant registers it again.
     *
     * Only the endpoint this scope registered itself: one a shop of a
     * multistore uses from the scope above has that scope's address
     * (`?scope=all`), which is never this shop's, and registering again
     * would give the shop a second endpoint on the same account.
     */
    public function moved(string $environment): bool
    {
        $endpoint = $this->settings->webhookEndpointSavedHere($environment);

        return $endpoint !== null && $endpoint['url'] !== '' && self::comparable($endpoint['url']) !== self::comparable($this->shop->webhookUrl());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEndpoint(Client $client, string $url): ?array
    {
        $wanted = self::comparable($url);
        foreach ($client->listWebhookEndpoints($url) as $endpoint) {
            if (self::comparable((string) ($endpoint['url'] ?? '')) === $wanted) {
                return $endpoint;
            }
        }

        return null;
    }

    /**
     * @return array{environment: string, status: string, reason: string, message: string, endpoint_id: string}
     */
    private function create(Client $client, string $environment, string $url): array
    {
        $endpoint = $client->createWebhookEndpoint($url, self::EVENTS, $this->description());
        $id = (string) ($endpoint['id'] ?? '');
        $secret = (string) ($endpoint['secret'] ?? '');
        if ($id === '' || $secret === '') {
            return self::result($environment, 'error', 'no_secret', $this->t->trans('Wajub registered the webhook but did not return its signing secret. Copy it from the Wajub Dashboard into the signing secret field.', [], 'Modules.Wajub.Admin'), $id);
        }

        $this->keep($environment, $id, $url, $secret);

        return self::result($environment, 'registered', '', $this->t->trans('Webhook registered, and its signing secret saved.', [], 'Modules.Wajub.Admin'), $id);
    }

    /**
     * An endpoint with the shop's address exists already: turn it back on,
     * give it the events it lacks, and make sure the shop has its secret.
     *
     * @param array<string, mixed> $endpoint
     *
     * @return array{environment: string, status: string, reason: string, message: string, endpoint_id: string}
     */
    private function adopt(Client $client, string $environment, string $url, array $endpoint, bool $rotate): array
    {
        $id = (string) ($endpoint['id'] ?? '');
        $status = 'existing';

        $events = is_array($endpoint['events'] ?? null) ? array_map('strval', $endpoint['events']) : [];
        $missing = in_array('*', $events, true) || $events === [] ? [] : array_diff(self::EVENTS, $events);
        $inactive = array_key_exists('active', $endpoint) && !$endpoint['active'];
        if ($missing !== [] || $inactive) {
            $client->updateWebhookEndpoint($id, ['events' => array_values(array_unique(array_merge($events, $missing))), 'active' => true]);
            $status = 'updated';
        }

        if (!$this->hasSecretOf($environment, $id) || $rotate) {
            $secret = (string) ($client->rotateWebhookSecret($id)['secret'] ?? '');
            if ($secret === '') {
                return self::result($environment, 'error', 'no_secret', $this->t->trans('Wajub did not return a new signing secret. Copy it from the Wajub Dashboard into the signing secret field.', [], 'Modules.Wajub.Admin'), $id);
            }
            $this->keep($environment, $id, $url, $secret);

            return self::result($environment, 'rotated', '', $this->t->trans('Wajub already had this webhook. It was given a new signing secret, saved here.', [], 'Modules.Wajub.Admin'), $id);
        }

        $this->keep($environment, $id, $url, '');

        return self::result(
            $environment,
            $status,
            '',
            $status === 'updated'
                ? $this->t->trans('Webhook already registered. It was switched on and subscribed to every event the module needs.', [], 'Modules.Wajub.Admin')
                : $this->t->trans('Webhook already registered, with its signing secret saved.', [], 'Modules.Wajub.Admin'),
            $id
        );
    }

    /**
     * Whether the signing secret the shop reads for `environment` is that of
     * the endpoint `id`, which Wajub shows only once. It is when it was saved
     * for this shop (or group, or all shops) itself, and the endpoint saved
     * beside it, if any, is that one: a shop of a multistore inherits the
     * secret of the address of the shop that saved it, and an endpoint kept
     * for an address the shop no longer has had a secret of its own. A
     * secret pasted by hand, with no endpoint kept, is taken as this one's.
     */
    private function hasSecretOf(string $environment, string $id): bool
    {
        if ($this->settings->webhookSecret($environment) === '' || !$this->settings->webhookSecretSavedHere($environment)) {
            return false;
        }
        $kept = $this->settings->webhookEndpointSavedHere($environment);

        return $kept === null || $kept['id'] === $id;
    }

    /** Saves what Wajub returned: the signing secret, when it sent one, and the endpoint. */
    private function keep(string $environment, string $id, string $url, string $secret): void
    {
        if ($secret !== '') {
            $this->settings->setWebhookSecret($environment, $secret);
        }
        $this->settings->setWebhookEndpoint($environment, ['id' => $id, 'url' => $url, 'at' => time()]);
    }

    /** 'exists', 'private_url', 'https_required' or 'invalid_url', from the API's 422. */
    private static function refusalReason(InvalidRequestException $e): string
    {
        $message = strtolower(self::apiMessage($e));
        if (str_contains($message, 'already exists')) {
            return 'exists';
        }
        if (str_contains($message, 'private') || str_contains($message, 'internal')) {
            return 'private_url';
        }
        if (str_contains($message, 'https')) {
            return 'https_required';
        }

        return 'invalid_url';
    }

    private static function apiMessage(ApiException $e): string
    {
        $errors = is_array($e->body['errors'] ?? null) ? $e->body['errors'] : [];
        $first = $errors['url'][0] ?? reset($errors);
        if (is_array($first)) {
            $first = reset($first);
        }

        return is_string($first) && $first !== '' ? $first : $e->getMessage();
    }

    private function manualReason(string $reason, string $environment, string $url, string $apiMessage = ''): string
    {
        switch ($reason) {
            case 'private_url':
                // `wajub listen` is no tunnel: it reads the account's events
                // from Wajub and posts them to the address it is given, signed
                // with a secret of its own. No endpoint is added in the
                // Dashboard, which would refuse a local address anyway.
                return $environment === Settings::LIVE
                    ? $this->t->trans('Wajub cannot reach %host%: it is a local or private address. Live webhooks work once the shop is online, at an https:// address.', ['%host%' => (string) parse_url($url, PHP_URL_HOST)], 'Modules.Wajub.Admin')
                    : $this->t->trans('Wajub cannot reach %host%: it is a local or private address. Automatic setup works once the shop is online. Meanwhile, to receive Sandbox events on this computer, run “%command%” with the Wajub CLI, and paste the secret that “wajub listen --print-secret” shows into Sandbox signing secret, under Set the webhook up by hand: no endpoint is added in the Wajub Dashboard.', ['%host%' => (string) parse_url($url, PHP_URL_HOST), '%command%' => self::listenCommand($url)], 'Modules.Wajub.Admin');
            case 'https_required':
                return $environment === Settings::LIVE
                    ? $this->t->trans('Live webhooks need an https:// address. Turn on SSL for this shop, then try again.', [], 'Modules.Wajub.Admin')
                    : $this->t->trans('This address needs https://. Turn on SSL for this shop, then try again.', [], 'Modules.Wajub.Admin');
            case 'exists':
                return $this->t->trans('Wajub already has this webhook, but the module could not find it. Copy its signing secret from the Wajub Dashboard into the signing secret field.', [], 'Modules.Wajub.Admin');
            default:
                return $apiMessage !== ''
                    ? $this->t->trans('Wajub refused the webhook address: %reason%', ['%reason%' => $apiMessage], 'Modules.Wajub.Admin')
                    : $this->t->trans('Wajub refused the webhook address. Set it up by hand.', [], 'Modules.Wajub.Admin');
        }
    }

    private function description(): string
    {
        $name = $this->shop->name();

        return $name !== '' ? 'PrestaShop: ' . $name : 'PrestaShop';
    }

    /** A URL as the API stores it, give or take the case of its host and a trailing slash. */
    public static function comparable(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim(trim($url), '/'));
        }

        return strtolower(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''))
            . rtrim((string) ($parts['path'] ?? ''), '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @return array{environment: string, status: string, reason: string, message: string, endpoint_id: string}
     */
    private static function result(string $environment, string $status, string $reason, string $message, string $endpointId = ''): array
    {
        return ['environment' => $environment, 'status' => $status, 'reason' => $reason, 'message' => $message, 'endpoint_id' => $endpointId];
    }
}

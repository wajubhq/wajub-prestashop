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

use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Shop\ShopInfo;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the webhook does with a delivery (controllers/front/webhook.php): the
 * WordPress plugin's Webhooks\Handler::processWebhook(), ported.
 *
 * 1. The signature is checked over the raw body, with both environments'
 *    signing secrets, the one in use first: both endpoints point here, a
 *    shop back in Sandbox still gets the live deliveries of the orders it
 *    took in Live, and the secret that verifies a delivery says which
 *    environment it comes from. Nothing in the body is read before that.
 * 2. A refused delivery is answered 403 and acts on nothing; its reason is
 *    noted for the webhook's health (WebhookHealth), when the request
 *    carries Wajub's signature headers at all.
 * 3. A verified event is claimed once (EventStore): a second delivery of it
 *    is answered as received and does nothing. The claim is for the whole
 *    PrestaShop: Wajub sends an event, under one id, to every endpoint of
 *    the account, and the shops of a multistore share the account, so one
 *    shop acts on it, on the shop of the order it is for (lots 2 and 3).
 * 4. A Sandbox delivery while Live is in use is answered as received but
 *    acts on nothing, nor does one whose body says another environment than
 *    its signature: a test payment never pays, fails or refunds a real
 *    order. A Live delivery is acted on even while Sandbox is in use (real
 *    money, for orders taken in Live before), except in a copy of the shop
 *    (ShopInfo::isProduction()), which asks Wajub to send it again later
 *    (503): a shop the public uses that is only in debug mode for a while
 *    then still gets it, for 72 hours. Either way the claim is released, as
 *    if the delivery never came: the same event, delivered to another shop
 *    of this PrestaShop, or here again, is acted on. "In use" is the
 *    environment of the shop whose order the event's payment is for
 *    (EventShops), which in a multistore may differ from the one of the
 *    scope whose endpoint received it; the scope's own for an event of no
 *    order of this PrestaShop.
 * 5. The event goes to its handlers (Dispatcher). A type nobody handles is
 *    answered as received; a handler that fails leaves the event to be
 *    delivered again (500).
 */
final class Receiver
{
    /** How long the shop remembers an event: longer than Wajub retries one. */
    private const KEEP_EVENTS_DAYS = 30;

    /** One delivery in this many also forgets the events older than that. */
    private const PURGE_ONE_IN = 100;

    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var ShopInfo */
    private $shop;

    /** @var EventStore */
    private $events;

    /** @var WebhookHealth */
    private $health;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var Logger */
    private $logger;

    /** @var EventShops|null */
    private $shops;

    /**
     * @param EventShops|null $shops the environment of the shop of an event's order; without it, the receiving scope's
     */
    public function __construct(Settings $settings, Environment $environment, ShopInfo $shop, EventStore $events, WebhookHealth $health, Dispatcher $dispatcher, Logger $logger, ?EventShops $shops = null)
    {
        $this->settings = $settings;
        $this->environment = $environment;
        $this->shop = $shop;
        $this->events = $events;
        $this->health = $health;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->shops = $shops;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function receive(string $raw, string $signature, string $timestamp, ?int $now = null): array
    {
        if ($raw === '') {
            return self::answer(400, ['received' => false]);
        }

        $inUse = $this->environment->isLive() ? Settings::LIVE : Settings::SANDBOX;
        $secrets = [];
        foreach ($inUse === Settings::LIVE ? [Settings::LIVE, Settings::SANDBOX] : [Settings::SANDBOX, Settings::LIVE] as $environment) {
            $secret = $this->settings->webhookSecret($environment);
            if ($secret !== '') {
                $secrets[$environment] = $secret;
            }
        }

        $verified = null;
        foreach ($secrets as $environment => $secret) {
            if (Signature::verify($raw, $signature, $timestamp, $secret, $now)) {
                $verified = $environment;
                break;
            }
        }

        if ($verified === null) {
            [$reason, $claimed] = $this->refusalReason($raw, $signature, $timestamp, $secrets);
            // Noted only when the request carries Wajub's signature headers:
            // the address is public, and a crawler posting to it must not
            // show the merchant a failing webhook.
            if ($timestamp !== '' && str_starts_with($signature, 'v1=')) {
                $this->health->recordRejected($reason, $claimed, $now);
            }
            $this->logger->info('Webhook refused', ['reason' => $reason]);

            return self::answer(403, ['received' => false]);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->health->recordRejected('invalid_payload', $verified, $now);

            return self::answer(400, ['received' => false]);
        }

        $type = $payload['type'] ?? $payload['event'] ?? '';
        $type = is_string($type) ? substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $type), 0, 64) : '';
        $data = $payload['data'] ?? $payload['transaction'] ?? $payload;
        $data = is_array($data) ? $data : [];
        if ($type === '') {
            $status = is_string($data['status'] ?? null) ? (string) preg_replace('/[^a-z_]/', '', strtolower($data['status'])) : '';
            $type = $status !== '' ? 'payment.' . $status : 'unknown';
        }

        // Signed by Wajub with this shop's secret: webhooks reach the shop.
        $this->health->recordReceived($type, $verified, $now);

        $eventId = is_string($payload['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $payload['id']) === 1
            ? $payload['id']
            : 'hash_' . hash('sha256', $raw);
        $event = new Event($eventId, $type, $data, $verified);

        $claim = $this->events->claim($eventId, $this->shop->id(), $verified, $type, $event->objectId());
        if ($claim === EventStore::DUPLICATE) {
            $this->logger->info('Webhook duplicate skipped', ['event' => $eventId, 'type' => $type]);

            return self::answer(200, ['received' => true, 'duplicate' => true]);
        }
        if ($claim === EventStore::BUSY) {
            // Another request acts on it right now: Wajub sends it again.
            return self::answer(409, ['received' => false, 'retry' => true]);
        }

        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            $this->events->purge(self::KEEP_EVENTS_DAYS);
        }

        $claimed = self::sandboxClaimOf($data);
        $contradicts = $claimed !== null && $claimed !== $verified;
        $inUse = $this->inUseFor($event, $inUse);
        $testWhileLive = $verified === Settings::SANDBOX && $inUse === Settings::LIVE;
        $liveInCopy = $verified === Settings::LIVE && $inUse === Settings::SANDBOX && !$this->shop->isProduction();
        if ($contradicts || $testWhileLive || $liveInCopy) {
            // Not this shop's to act on, nor to keep as received: another
            // shop of this PrestaShop may get the same event and act on it.
            $this->events->release($eventId);
            $later = $liveInCopy && !$contradicts;
            $this->logger->info($later ? 'Live webhook not acted on in a copy of the shop: left for Wajub to send again' : 'Webhook from another environment not acted on', [
                'event' => $eventId,
                'type' => $type,
                'signed_for' => $verified,
                'in_use' => $inUse,
                'says' => $claimed ?? '',
            ]);

            return $later
                ? self::answer(503, ['received' => false, 'retry' => true])
                : self::answer(200, ['received' => true]);
        }

        try {
            $handled = $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook handling failed', ['event' => $eventId, 'type' => $type, 'error' => $e->getMessage()]);
            $this->events->finish($eventId, EventStore::FAILED, $e->getMessage());

            return self::answer(500, ['received' => false, 'retry' => true]);
        }

        $this->events->finish($eventId, $handled ? EventStore::PROCESSED : EventStore::IGNORED);

        return self::answer(200, ['received' => true]);
    }

    /**
     * The environment the shop of the event's order uses, or `scope`'s (the
     * receiving scope's) when the event is about no order of this
     * PrestaShop, or the shop cannot be read.
     */
    private function inUseFor(Event $event, string $scope): string
    {
        if ($this->shops === null) {
            return $scope;
        }
        try {
            return $this->shops->environmentOf($event) ?? $scope;
        } catch (\Throwable $e) {
            $this->logger->error('Webhook: the shop of the event could not be read', ['event' => $event->id, 'error' => $e->getMessage()]);

            return $scope;
        }
    }

    /**
     * Why a delivery no saved secret verifies was refused, and the
     * environment to name in the advice: 'missing_secret' when the
     * environment it says it came from has no secret saved, 'stale' when a
     * secret matches but the signature is over five minutes old (the
     * environment is then that secret's), else 'invalid_signature'. Except
     * for 'stale', the environment is only what an unverified body claims:
     * it words the advice, nothing more.
     *
     * @param array<string, string> $secrets
     *
     * @return array{0: string, 1: string}
     */
    private function refusalReason(string $raw, string $signature, string $timestamp, array $secrets): array
    {
        $claimed = self::claimedEnvironment($raw) ?? '';
        if ($secrets === [] || ($claimed !== '' && !isset($secrets[$claimed]))) {
            return ['missing_secret', $claimed];
        }
        foreach ($secrets as $environment => $secret) {
            if (Signature::matches($raw, $signature, $timestamp, $secret)) {
                return ['stale', $environment];
            }
        }

        return ['invalid_signature', $claimed];
    }

    /**
     * 'sandbox' or 'live' as an unverified body says, or null. Used only to
     * word the reason a delivery was refused.
     */
    private static function claimedEnvironment(string $raw): ?string
    {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }
        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : (is_array($payload['transaction'] ?? null) ? $payload['transaction'] : $payload);

        $environment = self::sandboxClaimOf($data);
        if ($environment === null && is_string($data['id'] ?? null) && $data['id'] !== '') {
            $environment = preg_match('/^[a-z]+_test_/', $data['id']) === 1 ? Settings::SANDBOX : Settings::LIVE;
        }
        if ($environment === null && array_key_exists('livemode', $payload) && $payload['livemode'] !== null) {
            $environment = filter_var($payload['livemode'], FILTER_VALIDATE_BOOLEAN) ? Settings::LIVE : Settings::SANDBOX;
        }

        return $environment;
    }

    /**
     * What a delivery's object says of its environment (its `sandbox` flag),
     * or null when it says nothing.
     *
     * @param array<string, mixed> $data
     */
    private static function sandboxClaimOf(array $data): ?string
    {
        if (!array_key_exists('sandbox', $data) || $data['sandbox'] === null) {
            return null;
        }

        return filter_var($data['sandbox'], FILTER_VALIDATE_BOOLEAN) ? Settings::SANDBOX : Settings::LIVE;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function answer(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}

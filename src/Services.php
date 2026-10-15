<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop;

use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Checkout\CheckoutOptions;
use Wajub\PrestaShop\Checkout\Offer;
use Wajub\PrestaShop\Checkout\PreparedPayments;
use Wajub\PrestaShop\Checkout\PrepareEndpoint;
use Wajub\PrestaShop\Checkout\PrestaShopStorefront;
use Wajub\PrestaShop\Checkout\Release;
use Wajub\PrestaShop\Checkout\ReturnFlow;
use Wajub\PrestaShop\Checkout\Starter;
use Wajub\PrestaShop\Checkout\StatusAnswer;
use Wajub\PrestaShop\Checkout\Storefront;
use Wajub\PrestaShop\Checkout\Tokens;
use Wajub\PrestaShop\Connection\ConnectionStatus;
use Wajub\PrestaShop\Connection\KeyManager;
use Wajub\PrestaShop\Connection\KeyReminders;
use Wajub\PrestaShop\Http\CurlTransport;
use Wajub\PrestaShop\Http\Transport;
use Wajub\PrestaShop\Order\Cleanup;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Order\Instalments;
use Wajub\PrestaShop\Order\Ledger;
use Wajub\PrestaShop\Order\OpenPayments;
use Wajub\PrestaShop\Order\PrestaShopOrders;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Order\ShopOrders;
use Wajub\PrestaShop\Pairing\KeyOrigins;
use Wajub\PrestaShop\Pairing\Pairing;
use Wajub\PrestaShop\Pairing\PairingClient;
use Wajub\PrestaShop\Payment\DbPaymentStore;
use Wajub\PrestaShop\Payment\DbRefundStore;
use Wajub\PrestaShop\Payment\PaymentStore;
use Wajub\PrestaShop\Payment\RefundStore;
use Wajub\PrestaShop\Privacy\CustomerData;
use Wajub\PrestaShop\Refund\RefundSync;
use Wajub\PrestaShop\Settings\ConfigStore;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\PhpEncryptionCipher;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\SecretCipher;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Settings\ShopConfigStore;
use Wajub\PrestaShop\Shop\PrestaShopShopInfo;
use Wajub\PrestaShop\Shop\ShopInfo;
use Wajub\PrestaShop\Storage\DbStateStore;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\DbLock;
use Wajub\PrestaShop\Support\Lock;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Mailer;
use Wajub\PrestaShop\Support\MoneyFormatter;
use Wajub\PrestaShop\Support\ShopLogger;
use Wajub\PrestaShop\Support\ShopMailer;
use Wajub\PrestaShop\Support\ShopMoneyFormatter;
use Wajub\PrestaShop\Support\ShopTranslator;
use Wajub\PrestaShop\Support\Translator;
use Wajub\PrestaShop\Webhook\DbEventStore;
use Wajub\PrestaShop\Webhook\Dispatcher;
use Wajub\PrestaShop\Webhook\EventHandler;
use Wajub\PrestaShop\Webhook\EventShops;
use Wajub\PrestaShop\Webhook\EventStore;
use Wajub\PrestaShop\Webhook\Receiver;
use Wajub\PrestaShop\Webhook\WebhookHealth;
use Wajub\PrestaShop\Webhook\WebhookRegistration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's objects for one scope (a shop, a group of shops, or all of
 * them), each built once, on first use, from PrestaShop's own: what the
 * module class, the controllers and the hooks ask for. The tests build it
 * with stand-ins for what reaches outside PHP (the network, the database,
 * PrestaShop's translator).
 */
final class Services
{
    /** @var Scope */
    private $scope;

    /** @var string */
    private $moduleVersion;

    /** @var array<string, object> */
    private $built = [];

    /** @var list<EventHandler> */
    private $handlers = [];

    /** @var (callable(int): Services)|null the services of each shop, for the webhook (Wajub::servicesFor()) */
    private $servicesFor;

    /**
     * @param array<string, object> $given stand-ins, by the name of the method that would build them: transport, state, configStore, cipher, translator, logger, shop, events, payments, refunds, orders, lock, money, storefront, heldConfirmations, mailer
     */
    public function __construct(Scope $scope, string $moduleVersion, array $given = [])
    {
        $this->scope = $scope;
        $this->moduleVersion = $moduleVersion;
        $this->built = $given;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function transport(): Transport
    {
        return $this->once('transport', static function (): Transport {
            return new CurlTransport();
        });
    }

    public function state(): StateStore
    {
        return $this->once('state', static function (): StateStore {
            return new DbStateStore();
        });
    }

    public function configStore(): ConfigStore
    {
        return $this->once('configStore', function (): ConfigStore {
            return new ShopConfigStore($this->scope);
        });
    }

    public function cipher(): SecretCipher
    {
        return $this->once('cipher', static function (): SecretCipher {
            return new PhpEncryptionCipher();
        });
    }

    public function translator(): Translator
    {
        return $this->once('translator', static function (): Translator {
            return new ShopTranslator();
        });
    }

    public function logger(): Logger
    {
        return $this->once('logger', function (): Logger {
            return new ShopLogger(function (): bool {
                return $this->settings()->debugLog();
            });
        });
    }

    public function shop(): ShopInfo
    {
        return $this->once('shop', function (): ShopInfo {
            return new PrestaShopShopInfo($this->scope, $this->moduleVersion);
        });
    }

    public function settings(): Settings
    {
        return $this->once('settings', function (): Settings {
            return new Settings($this->configStore(), $this->cipher());
        });
    }

    public function environment(): Environment
    {
        return $this->once('environment', function (): Environment {
            return new Environment($this->settings());
        });
    }

    public function userAgent(): string
    {
        return Client::userAgent($this->shop()->moduleVersion(), $this->shop()->prestashopVersion());
    }

    /**
     * A client for this key, in Sandbox (true) or Live: for checking a key,
     * registering a webhook, revoking a replaced key.
     *
     * @return callable(string, bool): Client
     */
    public function clientFor(): callable
    {
        return function (string $key, bool $sandbox): Client {
            return new Client($key, $sandbox, $this->transport(), $this->userAgent(), $this->logger(), $this->built['pause'] ?? null, $this->apiUrl());
        };
    }

    /**
     * Wajub's API, or, on a shop in debug mode whose configuration defines
     * _WAJUB_API_URL_ (config/defines_custom.inc.php), a stand-in a
     * developer runs to test without Wajub. Never on a shop the public
     * uses: a key sent elsewhere than Wajub is a key given away.
     */
    public function apiUrl(): string
    {
        if (defined('_WAJUB_API_URL_') && defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            $url = (string) constant('_WAJUB_API_URL_');
            if (preg_match('#^https?://[^/\s]+#', $url) === 1) {
                return rtrim($url, '/');
            }
        }

        return Client::BASE_URL;
    }

    /**
     * The client of the environment in use, with its key only: Sandbox
     * without its key gets a client that sends nothing.
     */
    public function client(): Client
    {
        return ($this->clientFor())($this->environment()->activeKey(), $this->environment()->sandboxSelected());
    }

    /**
     * The shop's client of one environment, with that environment's key
     * only: a payment is read, cancelled or refunded with the key of the
     * environment it was made in, whatever environment the shop uses now,
     * never with the other one's.
     *
     * @return callable(string): Client
     */
    public function clientOf(): callable
    {
        return function (string $environment): Client {
            $sandbox = $environment !== Settings::LIVE;

            return ($this->clientFor())($this->settings()->secretKey($sandbox ? Settings::SANDBOX : Settings::LIVE), $sandbox);
        };
    }

    public function keyOrigins(): KeyOrigins
    {
        return $this->once('keyOrigins', function (): KeyOrigins {
            return new KeyOrigins($this->settings(), $this->translator(), $this->logger());
        });
    }

    public function connectionStatus(): ConnectionStatus
    {
        return $this->once('connectionStatus', function (): ConnectionStatus {
            return new ConnectionStatus($this->state(), $this->settings(), $this->environment(), $this->keyOrigins(), $this->clientFor(), $this->translator(), $this->logger());
        });
    }

    public function keyManager(): KeyManager
    {
        return $this->once('keyManager', function (): KeyManager {
            return new KeyManager($this->settings(), $this->environment(), $this->clientFor(), $this->connectionStatus(), $this->translator(), $this->logger());
        });
    }

    public function mailer(): Mailer
    {
        return $this->once('mailer', static function (): Mailer {
            return new ShopMailer(dirname(__DIR__) . '/mails/');
        });
    }

    /**
     * The warnings of keys that expire, for every scope of the PrestaShop
     * (Connection\KeyReminders).
     *
     * @param callable(string): ?Services $servicesOf the services of a scope, by its slug (Wajub::servicesOfSlug())
     * @param list<string> $shops in a multistore, every shop's slug
     * @param (callable(string, Translator): string)|null $scopeName what the shop selector calls a scope
     */
    public function keyReminders(callable $servicesOf, array $shops = [], ?callable $scopeName = null): KeyReminders
    {
        return $this->once('keyReminders', function () use ($servicesOf, $shops, $scopeName): KeyReminders {
            return new KeyReminders($this->settings(), $servicesOf, $this->mailer(), $this->logger(), $shops, $scopeName);
        });
    }

    public function webhookRegistration(): WebhookRegistration
    {
        return $this->once('webhookRegistration', function (): WebhookRegistration {
            return new WebhookRegistration($this->settings(), $this->shop(), $this->clientFor(), $this->translator(), $this->logger());
        });
    }

    /**
     * What the webhook of this scope writes down (WebhookHealth), or, with
     * `slug`, that of the scope a shop inherits its webhook from.
     */
    public function webhookHealth(?string $slug = null): WebhookHealth
    {
        if ($slug !== null && $slug !== $this->scope->slug()) {
            return new WebhookHealth($this->state(), $slug);
        }

        return $this->once('webhookHealth', function (): WebhookHealth {
            return new WebhookHealth($this->state(), $this->scope->slug());
        });
    }

    public function pairing(): Pairing
    {
        return $this->once('pairing', function (): Pairing {
            return new Pairing(
                new PairingClient($this->transport(), $this->userAgent(), $this->logger(), (string) ($this->built['dashboardUrl'] ?? PairingClient::DASHBOARD)),
                $this->state(),
                $this->settings(),
                $this->environment(),
                $this->keyManager(),
                $this->keyOrigins(),
                $this->connectionStatus(),
                $this->webhookRegistration(),
                $this->shop(),
                $this->clientFor(),
                $this->translator(),
                $this->logger()
            );
        });
    }

    // --- Payments -------------------------------------------------------------------

    public function payments(): PaymentStore
    {
        return $this->once('payments', static function (): PaymentStore {
            return new DbPaymentStore();
        });
    }

    public function refunds(): RefundStore
    {
        return $this->once('refunds', static function (): RefundStore {
            return new DbRefundStore();
        });
    }

    public function orders(): ShopOrders
    {
        return $this->once('orders', static function (): ShopOrders {
            return new PrestaShopOrders();
        });
    }

    public function lock(): Lock
    {
        return $this->once('lock', static function (): Lock {
            return new DbLock();
        });
    }

    public function money(): MoneyFormatter
    {
        return $this->once('money', static function (): MoneyFormatter {
            return new ShopMoneyFormatter();
        });
    }

    public function tokens(): Tokens
    {
        return $this->once('tokens', static function (): Tokens {
            return new Tokens(defined('_COOKIE_KEY_') ? (string) _COOKIE_KEY_ : '');
        });
    }

    public function storefront(): Storefront
    {
        return $this->once('storefront', function (): Storefront {
            return new PrestaShopStorefront($this->tokens());
        });
    }

    public function ledger(): Ledger
    {
        return $this->once('ledger', function (): Ledger {
            return new Ledger($this->payments(), $this->refunds());
        });
    }

    public function settlement(): Settlement
    {
        return $this->once('settlement', function (): Settlement {
            return new Settlement($this->orders(), $this->payments(), $this->refunds(), $this->ledger(), $this->clientOf(), $this->state(), $this->translator(), $this->money(), $this->logger(), $this->settings()->partialState());
        });
    }

    public function offer(): Offer
    {
        return $this->once('offer', function (): Offer {
            return new Offer($this->settings(), $this->environment(), $this->state(), function (): Client {
                return $this->client();
            }, $this->logger(), defined('_PS_ADMIN_DIR_'));
        });
    }

    public function checkoutOptions(): CheckoutOptions
    {
        return $this->once('checkoutOptions', function (): CheckoutOptions {
            return new CheckoutOptions($this->settings(), $this->environment(), $this->offer(), $this->translator());
        });
    }

    public function preparedPayments(): PreparedPayments
    {
        return $this->once('preparedPayments', function (): PreparedPayments {
            return new PreparedPayments($this->payments(), function (): Client {
                return $this->client();
            }, $this->environment(), $this->shop()->name(), $this->translator(), $this->logger());
        });
    }

    public function prepareEndpoint(): PrepareEndpoint
    {
        return $this->once('prepareEndpoint', function (): PrepareEndpoint {
            return new PrepareEndpoint($this->preparedPayments(), $this->settings(), $this->environment(), $this->state(), $this->translator(), $this->logger());
        });
    }

    public function starter(): Starter
    {
        return $this->once('starter', function (): Starter {
            return new Starter($this->orders(), $this->payments(), $this->openPayments(), $this->preparedPayments(), $this->settlement(), $this->ledger(), $this->offer(), $this->storefront(), function (): Client {
                return $this->client();
            }, $this->environment(), $this->translator(), $this->logger(), $this->instalments(), $this->money());
        });
    }

    public function release(): Release
    {
        return $this->once('release', function (): Release {
            return new Release($this->orders(), $this->payments(), $this->openPayments(), $this->ledger(), $this->storefront(), $this->translator(), $this->logger());
        });
    }

    public function heldConfirmations(): HeldConfirmations
    {
        return $this->once('heldConfirmations', function (): HeldConfirmations {
            return new HeldConfirmations($this->state());
        });
    }

    public function customerData(): CustomerData
    {
        return $this->once('customerData', function (): CustomerData {
            return new CustomerData($this->payments(), $this->refunds(), $this->heldConfirmations(), $this->translator());
        });
    }

    public function refundSync(): RefundSync
    {
        return $this->once('refundSync', function (): RefundSync {
            return new RefundSync($this->orders(), $this->payments(), $this->refunds(), $this->ledger(), $this->settlement(), $this->clientOf(), $this->environment(), $this->translator(), $this->money(), $this->logger());
        });
    }

    /**
     * What a run of the scheduled task does (Order\Cleanup), acting on each
     * order with the services of its own shop.
     *
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function cleanup(callable $servicesFor): Cleanup
    {
        return $this->once('cleanup', function () use ($servicesFor): Cleanup {
            return new Cleanup($servicesFor, $this->orders(), $this->payments(), $this->state(), $this->lock(), $this->logger(), $this->settings()->awaitingState());
        });
    }

    public function instalments(): Instalments
    {
        return $this->once('instalments', function (): Instalments {
            return new Instalments($this->orders(), $this->payments(), $this->settlement(), $this->ledger(), $this->clientOf(), $this->translator(), $this->money(), $this->logger());
        });
    }

    public function openPayments(): OpenPayments
    {
        return $this->once('openPayments', function (): OpenPayments {
            return new OpenPayments($this->orders(), $this->payments(), $this->settlement(), $this->ledger(), $this->clientOf(), $this->logger());
        });
    }

    public function statusAnswer(): StatusAnswer
    {
        return $this->once('statusAnswer', function (): StatusAnswer {
            return new StatusAnswer($this->orders(), $this->payments(), $this->settlement(), $this->ledger(), $this->storefront(), $this->translator(), $this->money());
        });
    }

    public function returnFlow(): ReturnFlow
    {
        return $this->once('returnFlow', function (): ReturnFlow {
            return new ReturnFlow($this->orders(), $this->payments(), $this->settlement(), $this->release(), $this->storefront(), $this->translator());
        });
    }

    // --- Webhooks --------------------------------------------------------------------

    public function events(): EventStore
    {
        return $this->once('events', static function (): EventStore {
            return new DbEventStore();
        });
    }

    /**
     * Adds a handler of webhook events: the payments' and the refunds'
     * handlers, from the lots that bring them.
     */
    public function addEventHandler(EventHandler $handler): void
    {
        $this->handlers[] = $handler;
        unset($this->built['receiver']);
    }

    /**
     * The services of each shop, which the webhook reads the environment of
     * the shop of an event's order with (Webhook\EventShops): the scope
     * whose endpoint received it may use another one.
     *
     * @param callable(int): Services $servicesFor the services of a shop (Wajub::servicesFor())
     */
    public function useShopServices(callable $servicesFor): void
    {
        $this->servicesFor = $servicesFor;
        unset($this->built['receiver']);
    }

    public function receiver(): Receiver
    {
        return $this->once('receiver', function (): Receiver {
            $shops = $this->servicesFor !== null ? new EventShops($this->payments(), $this->servicesFor) : null;

            return new Receiver($this->settings(), $this->environment(), $this->shop(), $this->events(), $this->webhookHealth(), new Dispatcher($this->handlers), $this->logger(), $shops);
        });
    }

    /**
     * @template T of object
     *
     * @param callable(): T $build
     *
     * @return T
     */
    private function once(string $name, callable $build): object
    {
        if (!isset($this->built[$name])) {
            $this->built[$name] = $build();
        }

        /* @var T */
        return $this->built[$name];
    }
}

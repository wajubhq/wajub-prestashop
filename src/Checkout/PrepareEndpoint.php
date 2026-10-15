<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Checkout;

use Wajub\PrestaShop\Payment\Methods;
use Wajub\PrestaShop\Settings\Environment;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Where the payment box of Mobile Money or Card asks for the payment its
 * form runs on (controllers/front/prepare.php, PreparedPayments::forCart()).
 * A port of the WordPress plugin's Fields\PrepareEndpoint.
 *
 * It answers the payment's id, the session the form loads and its currency,
 * and nothing else: no key, no reference. Every answer can cost the merchant
 * API calls, so it is limited per visitor and for the whole shop, below the
 * plan's allowance (120 calls a minute on the base plan), which webhooks,
 * status reads and refunds share. A shop with heavy traffic, or a higher
 * plan, raises the limits with the `actionWajubPrepareLimits` hook.
 */
final class PrepareEndpoint
{
    /** Prepares a minute: per visitor's address, and for the whole shop. */
    public const LIMITS = ['ip' => 10, 'site' => 30];

    /** Seconds a refused visitor is asked to wait. */
    public const RETRY_AFTER = 30;

    /** @var PreparedPayments */
    private $prepared;

    /** @var Settings */
    private $settings;

    /** @var Environment */
    private $environment;

    /** @var StateStore */
    private $state;

    /** @var Translator */
    private $t;

    /** @var Logger */
    private $logger;

    public function __construct(PreparedPayments $prepared, Settings $settings, Environment $environment, StateStore $state, Translator $t, Logger $logger)
    {
        $this->prepared = $prepared;
        $this->settings = $settings;
        $this->environment = $environment;
        $this->state = $state;
        $this->t = $t;
        $this->logger = $logger;
    }

    /**
     * The answer to the payment box: `kind`, `token` (Tokens::checkout() of
     * the cart) and `renew` ('1' for a new payment, after the form's session
     * ended), for the payer's cart (null without one), from the visitor at
     * `$ip`.
     *
     * @param array<string, string> $request
     * @param string $expectedToken the cart's checkout token
     * @param array{ip: int, site: int} $limits
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function answer(array $request, ?CartView $cart, string $expectedToken, string $ip, array $limits = self::LIMITS, ?int $now = null): array
    {
        $kind = $request['kind'] ?? '';
        $words = CheckoutStrings::fields(Methods::isFields($kind) ? $kind : '', $this->t);

        if ($cart === null || !Tokens::matches($expectedToken, $request['token'] ?? '')) {
            return self::error(403, 'unavailable', $words['fallback']);
        }

        $now = $now ?? time();
        $minute = intdiv($now, 60);
        if ($this->state->increment('prepare_ip:' . md5($ip) . ':' . $minute, 60) > max(1, $limits['ip'])
            || $this->state->increment('prepare_site:' . $minute, 60) > max(1, $limits['site'])) {
            return self::error(429, 'unavailable', $words['temporarilyUnavailable']);
        }

        if (!Methods::isFields($kind) || !$this->settings->methodEnabled($kind)) {
            return self::error(400, 'unavailable', $words['fallback']);
        }
        if (!$this->environment->isConfigured()) {
            return self::error(503, 'not_configured', $words['notConfigured']);
        }
        if (!$cart->payable()) {
            return self::error(400, 'unavailable', $words['fallback']);
        }

        try {
            $slot = $this->prepared->forCart($cart, $kind, ($request['renew'] ?? '0') === '1');
        } catch (MethodUnavailableException $e) {
            return self::error(409, 'method_unavailable', $words['unavailable']);
        } catch (AmountOutOfRangeException $e) {
            return self::error(409, 'amount_out_of_range', $words['amountOutOfRange']);
        } catch (\Throwable $e) {
            $this->logger->error('Payment form: a payment could not be prepared', ['kind' => $kind, 'error' => $e->getMessage()]);

            return self::error(503, 'unavailable', $words['temporarilyUnavailable']);
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'data' => [
                    'kind' => $kind,
                    'paymentId' => $slot->paymentId,
                    'sessionId' => $slot->sessionToken(),
                    'currency' => $slot->currency,
                ],
            ],
        ];
    }

    /**
     * The limits a hook gave, each at least one, else the defaults.
     *
     * @param array<mixed> $limits
     *
     * @return array{ip: int, site: int}
     */
    public static function limits(array $limits): array
    {
        return [
            'ip' => max(1, (int) ($limits['ip'] ?? self::LIMITS['ip'])),
            'site' => max(1, (int) ($limits['site'] ?? self::LIMITS['site'])),
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['success' => false, 'data' => ['code' => $code, 'message' => $message]]];
    }
}

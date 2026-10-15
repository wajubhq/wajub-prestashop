<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One Wajub payment the shop started: a row of `wajub_payment`
 * (Install\Schema). What binds a payment to its order: a webhook or a
 * payer's return names a payment by its Wajub id, and only a payment the
 * shop recorded here, for that order, settles it. Its metadata, which anyone
 * could write on a payment, only says where to look.
 *
 * Its life (`state`):
 * - `prepared`: prepared for a cart, so the payment form can load while the
 *   payer fills in the checkout; nobody can pay it, and no order holds it;
 * - `active`: the order's, activated (the form's) or created (Wajub's hosted
 *   page), with Wajub until it ends;
 * - `final`: ended, as Wajub said (`status`), counted toward the order or not;
 * - `dropped`: a prepared payment the checkout no longer uses (the cart's
 *   currency or the shop's key changed, it was about to expire): never
 *   activated, it sends no webhook, and Wajub prunes it;
 * - `uncounted`: it went through, but cannot count toward the order yet (its
 *   refunds could not be read): the order is never cancelled meanwhile.
 *
 * `counted` is what counted toward the order, in the order's currency: what
 * the payment collected (Support\Amount::collected()), written once, with
 * the PrestaShop payment (OrderPayment) that says it on the order.
 */
final class PaymentRecord
{
    public const PREPARED = 'prepared';

    public const ACTIVE = 'active';

    public const FINAL = 'final';

    public const DROPPED = 'dropped';

    public const UNCOUNTED = 'uncounted';

    /** The payment form in the checkout (Mobile Money, Card). */
    public const MODE_FIELDS = 'fields';

    /** Wajub's hosted page (Other payment methods, or a form that could not load). */
    public const MODE_HOSTED = 'hosted';

    public int $id = 0;

    public string $paymentId = '';

    public string $reference = '';

    public int $idShop = 0;

    public int $idCart = 0;

    public int $idOrder = 0;

    public int $idCustomer = 0;

    /** 'sandbox' or 'live': the environment whose key made it, and the only one that reads it. */
    public string $environment = '';

    /** Payment\Methods kind: mobile_money, card or other. */
    public string $method = '';

    public string $mode = '';

    public string $state = self::PREPARED;

    /** Wajub's status, as last read: pending, processing, partial, succeeded, failed… */
    public string $status = 'pending';

    public string $currency = '';

    public float $amount = 0.0;

    public float $amountPaid = 0.0;

    public float $amountRefunded = 0.0;

    public float $counted = 0.0;

    public int $idOrderPayment = 0;

    /** How it was paid: Wajub's channel (`cm.mtn`, `card`). */
    public string $channel = '';

    /** sha256 of the activation body (Api\Client::activationBody()), sent again as it was. */
    public string $activationHash = '';

    /** Unix time it expires at, for a prepared payment: a little before Wajub's own. */
    public int $expiresAt = 0;

    /**
     * What the module keeps of it beyond the columns: `token` (the session
     * the payment form loads), `authorization_url` (its hosted page), `key`
     * (the fingerprint of the key that made it, never the key).
     *
     * @var array<string, string>
     */
    public array $metadata = [];

    public string $dateAdd = '';

    public string $dateUpd = '';

    public function meta(string $key): string
    {
        return $this->metadata[$key] ?? '';
    }

    public function setMeta(string $key, string $value): void
    {
        if ($value === '') {
            unset($this->metadata[$key]);

            return;
        }
        $this->metadata[$key] = $value;
    }

    public function isCounted(): bool
    {
        return $this->counted > 0;
    }

    /** The session the payment form loads, for a payment of the form. */
    public function sessionToken(): string
    {
        return $this->meta('token');
    }

    public function authorizationUrl(): string
    {
        return $this->meta('authorization_url');
    }
}

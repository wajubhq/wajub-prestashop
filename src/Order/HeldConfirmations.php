<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Order;

use Wajub\PrestaShop\Storage\StateStore;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's "Order confirmation" e-mail of an order placed with Wajub,
 * held back until the order is paid.
 *
 * The order is created as the payer places it, before any payment
 * (PaymentModule::validateOrder(), in "Awaiting Wajub payment"), and
 * PrestaShop e-mails its confirmation to the customer then. A payment
 * declined, or left for another method, gives the order back as a cart, and
 * placing it again would e-mail a confirmation each time, for orders that
 * were never paid. So the e-mail PrestaShop builds as the order is placed
 * is kept (`actionEmailSendBefore`, while controllers/front/validation.php
 * places it), whole, as PrestaShop wrote it, and sent once the order is
 * paid (`actionOrderStatusPostUpdate`), whoever paid it: a Wajub payment,
 * or the merchant marking it paid. As WooCommerce, which the WordPress
 * plugin follows, tells the customer of an order once it is paid.
 *
 * An e-mail PrestaShop would send with an attachment (an invoice, for a
 * state that makes one) is never held: it goes as PrestaShop sends it.
 *
 * Kept TTL at most, and forgotten as soon as the order can no longer be
 * paid because the module cancelled it (forgetOrder()), or the customer's
 * data is erased (forget()).
 */
final class HeldConfirmations
{
    /** PrestaShop's e-mail template of an order's confirmation. */
    public const TEMPLATE = 'order_conf';

    /** Seconds a confirmation is kept for an order that is not paid: an order paid later than this gets none. */
    private const TTL = 2592000;

    /** What of Mail::send()'s arguments is kept, as `actionEmailSendBefore` names them. */
    private const KEPT = ['idLang', 'subject', 'templateVars', 'to', 'toName', 'from', 'fromName', 'templatePath', 'idShop', 'bcc', 'replyTo', 'replyToName'];

    /** @var StateStore */
    private $state;

    /** @var callable(array<string, mixed>): bool */
    private $send;

    /**
     * @param callable(array<string, mixed>): bool|null $send sends an e-mail kept by hold() (PrestaShop's Mail::send() unless given)
     */
    public function __construct(StateStore $state, ?callable $send = null)
    {
        $this->state = $state;
        $this->send = $send ?? [self::class, 'mail'];
    }

    /**
     * Keeps the confirmation e-mail of an order being placed, from the
     * arguments `actionEmailSendBefore` gives. True when it was kept, and
     * PrestaShop must not send it now.
     *
     * @param array<string, mixed> $mail
     */
    public function hold(array $mail): bool
    {
        if (($mail['template'] ?? '') !== self::TEMPLATE || !empty($mail['fileAttachment'])) {
            return false;
        }
        $vars = is_array($mail['templateVars'] ?? null) ? $mail['templateVars'] : [];
        $idOrder = is_numeric($vars['{id_order}'] ?? null) ? (int) $vars['{id_order}'] : 0;
        if ($idOrder <= 0) {
            return false;
        }

        $kept = [];
        foreach (self::KEPT as $name) {
            $kept[$name] = $mail[$name] ?? null;
        }
        $this->state->set(self::key($idOrder), $kept, self::TTL);

        return true;
    }

    /**
     * Sends the order's confirmation, once, if one was held: the order is
     * paid. `$payment`: how it was paid (the order's payment title), which
     * PrestaShop wrote "waiting for validation" beside as the order was
     * placed. True when it was sent.
     */
    public function release(int $idOrder, string $payment): bool
    {
        $mail = $this->state->get(self::key($idOrder));
        if (!is_array($mail) || !is_array($mail['templateVars'] ?? null)) {
            return false;
        }
        // Gone before it is sent: two requests paying the order at once send it once.
        $this->state->delete(self::key($idOrder));
        if ($payment !== '') {
            $mail['templateVars']['{payment}'] = mb_substr($payment, 0, 255);
        }
        $mail['template'] = self::TEMPLATE;

        return (bool) ($this->send)($mail);
    }

    /**
     * Forgets the confirmation held for the order, unsent: it holds the
     * customer's name, e-mail address and addresses, which an erasure of
     * their data removes (Privacy\CustomerData).
     */
    public function forget(int $idOrder): bool
    {
        if (!$this->holds($idOrder)) {
            return false;
        }
        $this->state->delete(self::key($idOrder));

        return true;
    }

    /**
     * Forgets the confirmations held for an order the module cancelled
     * itself, unpaid, and for every order its cart became: given back as a
     * cart (Checkout\Release), whose next order has a confirmation of its
     * own, or left unpaid past the delay (Order\Cleanup). Neither is paid
     * from then on, so their e-mails would never be sent, and the customer's
     * name, e-mail address and addresses they hold are kept no longer. An
     * order the merchant cancels keeps its own: a payment still under way
     * may pay it after all, and its confirmation goes then.
     */
    public function forgetOrder(OrderView $order): void
    {
        foreach ($order->ids !== [] ? $order->ids : [$order->id] as $idOrder) {
            $this->forget((int) $idOrder);
        }
    }

    /** Whether a confirmation is held for the order. */
    public function holds(int $idOrder): bool
    {
        return is_array($this->state->get(self::key($idOrder)));
    }

    /**
     * PrestaShop's Mail::send() of an e-mail kept by hold().
     *
     * @param array<string, mixed> $mail
     */
    public static function mail(array $mail): bool
    {
        return (bool) \Mail::send(
            (int) $mail['idLang'],
            self::TEMPLATE,
            (string) $mail['subject'],
            $mail['templateVars'],
            $mail['to'],
            $mail['toName'],
            $mail['from'],
            $mail['fromName'],
            null,
            null,
            is_string($mail['templatePath']) && $mail['templatePath'] !== '' ? $mail['templatePath'] : _PS_MAIL_DIR_,
            false,
            $mail['idShop'] !== null ? (int) $mail['idShop'] : null,
            $mail['bcc'],
            $mail['replyTo'],
            $mail['replyToName']
        );
    }

    private static function key(int $idOrder): string
    {
        return 'order_conf:' . $idOrder;
    }
}

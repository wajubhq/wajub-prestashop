<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

/*
 * What an order becomes once Wajub says where its payment stands
 * (Order\Ledger, Order\Settlement), and the paths that ask: the webhook
 * (Webhook\PaymentEventHandler), the payer's return (Checkout\ReturnFlow),
 * the status the pages poll (Checkout\StatusAnswer), the payer leaving the
 * payment (Checkout\Release) and the back office's "Check status"
 * (Admin\OrderPanel).
 *
 * A payment counts once, for what it collected, in the order's currency and
 * its own environment, as one PrestaShop payment with its id; the order is
 * paid once its payments cover it, "Partially paid with Wajub" short of it;
 * one in another currency counts nothing; a second payment on a paid order
 * is told to the merchant; a refused payment fails the order, a cancelled
 * or expired one leaves it, and an order is never cancelled while a payment
 * may still be charged, or holds money.
 *
 *     php tests/run-settlement-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';

use Wajub\PrestaShop\Admin\OrderPanel;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Checkout\Release;
use Wajub\PrestaShop\Order\HeldConfirmations;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\PaymentEventHandler;

const API = 'https://api.wajub.com';

/** A shop with order 9 (15 000 FCFA) and its Mobile Money payment trx_test_1, active. */
function shop_with_order(string $environment = 'sandbox', float $total = 15000.0): Services
{
    $s = payment_services($environment);
    $GLOBALS['orders']->add(9, $total, 'XAF', ['reference' => 'XKBKNABJK']);
    saved_payment(['paymentId' => 'trx_test_1', 'idOrder' => 9, 'idCart' => 109, 'amount' => $total, 'reference' => 'Order #XKBKNABJK']);

    return $s;
}

function read_as(string $id, array $payment): void
{
    $GLOBALS['http']->routes['GET ' . API . '/payments/' . $id] = [[200, $payment]];
}

function refunds_as(string $id, mixed $answer): void
{
    $GLOBALS['http']->routes['GET ' . API . '/payments/' . $id . '/refunds'] = [$answer];
}

function apply_read(Services $s, string $id, string $seen = Settlement::SEEN_NOW): string
{
    $record = $GLOBALS['payments']->find($id);
    $order = $GLOBALS['orders']->find($record->idOrder);

    return $s->settlement()->apply($order, $record, $s->settlement()->outcome($record, true), $seen);
}

function webhook(Services $s, string $type, array $data, string $environment = 'sandbox', string $id = ''): void
{
    $handler = new PaymentEventHandler($GLOBALS['payments'], static function (int $idShop) use ($s): Services {
        return $s;
    }, $GLOBALS['logger']);
    check($handler->handles($type), "the handler acts on {$type}");
    $handler->handle(new Event($id !== '' ? $id : 'evt_' . bin2hex(random_bytes(4)), $type, $data, $environment));
}

// --- Paid in full ---------------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded', 15000, ['metadata' => ['order_id' => '9']]));
check(apply_read($s, 'trx_test_1') === Client::OUTCOME_SUCCEEDED, 'a payment of the order\'s total pays it');
check($GLOBALS['orders']->state(9) === FakeShopOrders::PAID, 'the order moves to "Payment accepted"');
check(end($GLOBALS['orders']->history)['mail'] === true, 'with PrestaShop\'s e-mail of the state');
$paid = $GLOBALS['orders']->paymentsOf(9);
check(count($paid) === 1 && $paid[0]['amount'] === 15000.0 && $paid[0]['transaction'] === 'trx_test_1', 'one PrestaShop payment of what it collected, the Wajub payment id its transaction id');
$record = $GLOBALS['payments']->find('trx_test_1');
check($record->counted === 15000.0 && $record->idOrderPayment === 1 && $record->state === PaymentRecord::FINAL && $record->status === 'succeeded' && $record->channel === 'cm.mtn', 'the payment counted, with how it was paid');
check($GLOBALS['orders']->noted(9, 'Wajub confirmed the payment (MTN Mobile Money).'), 'a note: confirmed, and how');
check(!in_array('GET ' . API . '/payments/trx_test_1/refunds', $GLOBALS['http']->routes(), true), 'the order\'s own payment, read right after it went through in full: no refunds to read');

apply_read($s, 'trx_test_1');
check(count($GLOBALS['orders']->paymentsOf(9)) === 1 && count(array_filter($GLOBALS['orders']->history, static fn (array $h): bool => $h['order'] === 9)) === 2, 'read again: nothing counted twice, no second state');

// --- Paid short, then the rest --------------------------------------------------------------

$s = shop_with_order('sandbox', 10000.0);
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded', 10000, ['amount_paid' => 4000]));
refunds_as('trx_test_1', [200, ['items' => []]]);
check(apply_read($s, 'trx_test_1') === Settlement::OUTCOME_SHORT, 'an instalment payment closed early (4 000 of 10 000) does not pay the order');
check($GLOBALS['orders']->state(9) === FakeShopOrders::PARTIAL, 'the order is "Partially paid with Wajub"');
check($GLOBALS['orders']->paymentsOf(9)[0]['amount'] === 4000.0, 'it counts for what it collected');
check($GLOBALS['orders']->noted(9, 'collected 4 000 FCFA') && $GLOBALS['orders']->noted(9, '6 000 FCFA of its total of 10 000 FCFA is left to pay'), 'a note of what is left to pay');
check(in_array('GET ' . API . '/payments/trx_test_1/refunds', $GLOBALS['http']->routes(), true), 'one that collected less than it asked: its refunds read before it counts');
check(spaced($s->statusAnswer()->payerLine($GLOBALS['orders']->find(9), 'sandbox')) === '4 000 FCFA already received; 6 000 FCFA left to pay.', 'the payer reads what was received and what is left');
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'amount' => 6000.0, 'mode' => PaymentRecord::MODE_HOSTED]);
read_as('trx_test_2', payment_data('trx_test_2', 'succeeded', 6000, ['metadata' => ['order_id' => '9']]));
check(apply_read($s, 'trx_test_2') === Client::OUTCOME_SUCCEEDED && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID, 'the rest paid: the order is paid');
check($GLOBALS['orders']->noted(9, 'paid in full by Wajub payments trx_test_1, trx_test_2'), 'by the two payments, which the note names');

// --- Another currency, another order --------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded', 23, ['currency' => 'EUR']));
check(apply_read($s, 'trx_test_1') === Settlement::OUTCOME_SHORT && $GLOBALS['orders']->paymentsOf(9) === [], 'a payment in another currency counts nothing');
check($GLOBALS['orders']->noted(9, 'in another currency than this order\'s (XAF)') && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING, 'the merchant is told to refund it; the order waits');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded', 15000, ['metadata' => ['order_id' => '77']]));
check(apply_read($s, 'trx_test_1') === Client::OUTCOME_MISMATCH && $GLOBALS['orders']->paymentsOf(9) === [], 'a payment Wajub holds for another order settles nothing');

// --- Unpaid ---------------------------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'failed', 15000, ['failure_message' => 'Insufficient funds']));
check(apply_read($s, 'trx_test_1') === Client::OUTCOME_FAILED && $GLOBALS['orders']->state(9) === FakeShopOrders::ERROR, 'a refused payment: "Payment error"');
check(end($GLOBALS['orders']->history)['mail'] === false, 'without PrestaShop\'s e-mail: the payer read it on screen');
check($GLOBALS['orders']->noted(9, 'did not go through (Wajub status: failed). Reason: Insufficient funds'), 'with Wajub\'s reason, for the merchant');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'expired'));
apply_read($s, 'trx_test_1');
check($GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['orders']->noted(9, 'did not complete Wajub payment trx_test_1 (Wajub status: expired)'), 'an expired payment leaves the order as it is, with a note');

$s = shop_with_order();
$GLOBALS['orders']->setState($GLOBALS['orders']->find(9), FakeShopOrders::CANCELED, false);
read_as('trx_test_1', payment_data('trx_test_1', 'failed'));
apply_read($s, 'trx_test_1');
check($GLOBALS['orders']->state(9) === FakeShopOrders::CANCELED, 'a refused payment leaves a cancelled order cancelled');
$GLOBALS['store']->rows = [];
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
apply_read($s, 'trx_test_1', Settlement::SEEN_LATER);
check($GLOBALS['orders']->state(9) === FakeShopOrders::PAID && $GLOBALS['orders']->noted(9, 'had been cancelled when Wajub payment trx_test_1 paid it'), 'one that went through pays a cancelled order, the merchant told the customer may have paid twice');

// --- Refunds read before a late count ---------------------------------------------------------------

$s = shop_with_order();
$record = $GLOBALS['payments']->find('trx_test_1');
read_as('trx_test_1', payment_data('trx_test_1', 'partially_refunded', 15000, ['amount_paid' => 15000]));
refunds_as('trx_test_1', network_down());
$order = $GLOBALS['orders']->find(9);
check($s->settlement()->apply($order, $record, $s->settlement()->outcome($record, true), Settlement::SEEN_LATER) === Client::OUTCOME_PENDING, 'a payment refunded since, whose refunds Wajub does not give: not counted yet');
check($GLOBALS['payments']->find('trx_test_1')->state === PaymentRecord::UNCOUNTED && $GLOBALS['orders']->paymentsOf(9) === [], 'kept as uncounted');
check($s->ledger()->uncounted($GLOBALS['orders']->find(9)) !== [] && !$GLOBALS['orders']->noted(9, 'its refunds could not be read'), 'the order keeps it, nothing noted yet: it is read again later');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'partially_refunded', 15000, ['amount_paid' => 15000]));
refunds_as('trx_test_1', [200, ['items' => [['id' => 'rfd_test_1', 'amount' => 5000, 'currency' => 'XAF', 'status' => 'succeeded']]]]);
apply_read($s, 'trx_test_1', Settlement::SEEN_LATER);
check($GLOBALS['orders']->paymentsOf(9)[0]['amount'] === 15000.0 && $s->ledger()->held($GLOBALS['orders']->find(9), 'sandbox') === 10000.0, 'refunded 5 000 of 15 000: it counts 15 000, and holds 10 000');
check($GLOBALS['orders']->state(9) === FakeShopOrders::PARTIAL, 'which does not cover the order');

// --- The environment of each payment ---------------------------------------------------------------

$s = shop_with_order('live');
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
apply_read($s, 'trx_test_1');
check(str_starts_with((string) $GLOBALS['http']->sent[0]['headers']['Authorization'], 'sk_test.'), 'a Sandbox payment is read with the Sandbox key, the shop in Live or not');
$s->settings()->setSecretKey('sandbox', '');
$GLOBALS['http']->reset();
$read = $s->settlement()->outcome($GLOBALS['payments']->find('trx_test_1'), true);
check($read['outcome'] === Client::OUTCOME_PENDING && !empty($read['unreachable']) && $GLOBALS['http']->sent === [], 'never with the live key in its place: without the Sandbox key, it is not read');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$record = $GLOBALS['payments']->find('trx_test_1');
$s->settlement()->outcome($record);
$s->settlement()->outcome($record);
check(count($GLOBALS['http']->sent) === 1, 'a payment under way is read once for every page that asks within a few seconds');
$GLOBALS['store']->now = time() + 11;
$s->settlement()->outcome($record);
check(count($GLOBALS['http']->sent) === 2, 'then read again');
$GLOBALS['store']->now = 0;

// --- The webhook ---------------------------------------------------------------------------------------

$s = shop_with_order();
webhook($s, 'payment.succeeded', payment_data('trx_test_1', 'succeeded'));
check($GLOBALS['orders']->state(9) === FakeShopOrders::PAID && count($GLOBALS['orders']->paymentsOf(9)) === 1, 'the webhook of a payment that went through pays its order');
check($GLOBALS['http']->sent === [], 'from what it carries, without asking Wajub');
check($GLOBALS['locks']->taken === ['order:9'] && $GLOBALS['locks']->held === [], 'under the order\'s lock, released after');
webhook($s, 'payment.succeeded', payment_data('trx_test_1', 'succeeded'));
check(count($GLOBALS['orders']->paymentsOf(9)) === 1 && !$GLOBALS['orders']->noted(9, 'second payment'), 'delivered again: nothing more');

$s = shop_with_order();
webhook($s, 'payment.succeeded', payment_data('trx_test_unknown', 'succeeded'));
webhook($s, 'payment.succeeded', ['id' => 'trx_test_1', 'status' => 'succeeded', 'amount' => 15000, 'amount_paid' => 15000, 'currency' => 'XAF', 'sandbox' => false], 'live');
check($GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['orders']->paymentsOf(9) === [], 'a payment the shop never started, or signed in the other environment, settles nothing');

$s = shop_with_order();
$GLOBALS['locks']->busy['order:9'] = true;
check(thrown(static function () use ($s): void {
    webhook($s, 'payment.succeeded', payment_data('trx_test_1', 'succeeded'));
}) instanceof RuntimeException, 'the payer\'s return holds the order: the delivery fails, and Wajub sends it again');
check($GLOBALS['orders']->paymentsOf(9) === [], 'having done nothing');

$s = shop_with_order();
webhook($s, 'payment.succeeded', payment_data('trx_test_1', 'succeeded'));
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'amount' => 15000.0]);
webhook($s, 'payment.succeeded', payment_data('trx_test_2', 'succeeded'));
check(count($GLOBALS['orders']->paymentsOf(9)) === 1 && $GLOBALS['orders']->noted(9, 'Wajub received a second payment for this order, already paid: trx_test_2 (15 000 FCFA)'), 'a second payment on a paid order: not counted, the merchant told to refund it');

$s = shop_with_order();
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'amount' => 15000.0, 'mode' => PaymentRecord::MODE_HOSTED]);
refunds_as('trx_test_1', [200, ['items' => []]]);
webhook($s, 'payment.succeeded', payment_data('trx_test_1', 'succeeded'));
check($GLOBALS['orders']->state(9) === FakeShopOrders::PAID && $GLOBALS['orders']->paymentsOf(9)[0]['transaction'] === 'trx_test_1', 'an earlier attempt that went through late pays the order');
check(in_array('GET ' . API . '/payments/trx_test_1/refunds', $GLOBALS['http']->routes(), true), 'its refunds read first');

$s = shop_with_order();
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'amount' => 15000.0]);
webhook($s, 'payment.failed', payment_data('trx_test_1', 'failed'));
check($GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING, 'the failure of an earlier attempt leaves the order to the payment it holds now');
webhook($s, 'payment.failed', payment_data('trx_test_2', 'failed'));
check($GLOBALS['orders']->state(9) === FakeShopOrders::ERROR, 'its own payment refused fails it');
webhook($s, 'payment.partial', payment_data('trx_test_2', 'partial', 15000, ['amount_paid' => 5000]));
check($GLOBALS['payments']->find('trx_test_2')->status === 'partial' && $GLOBALS['payments']->find('trx_test_2')->amountPaid === 5000.0, 'an instalment collected is kept on the payment');

// --- The status the pages poll ----------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$answer = $s->statusAnswer()->answer($GLOBALS['orders']->find(9));
check($answer['status'] === 'pending' && $answer['processing'] === false && str_contains($answer['redirect'], 'order-confirmation'), 'pending: not under way yet');
$GLOBALS['store']->rows = [];
read_as('trx_test_1', payment_data('trx_test_1', 'processing'));
check($s->statusAnswer()->answer($GLOBALS['orders']->find(9))['processing'] === true, 'processing: under way, the payer is not offered a second payment');
$GLOBALS['store']->rows = [];
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
check($s->statusAnswer()->answer($GLOBALS['orders']->find(9))['status'] === 'paid' && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID, 'paid: settled by the check itself, before the confirmation page opens');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'failed'));
$answer = $s->statusAnswer()->answer($GLOBALS['orders']->find(9));
check($answer['status'] === 'failed' && $answer['reason'] === 'failed' && ($answer['retry'] ?? false) === true, 'refused: failed, the payer may pay again');
$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'cancelled'));
check($s->statusAnswer()->answer($GLOBALS['orders']->find(9))['reason'] === 'cancelled', 'cancelled: said so');

$s = shop_with_order('sandbox', 10000.0);
read_as('trx_test_1', payment_data('trx_test_1', 'partial', 10000, ['amount_paid' => 4000]));
$answer = $s->statusAnswer()->answer($GLOBALS['orders']->find(9));
check(($answer['partial'] ?? false) === true && spaced($answer['message']) === '4 000 FCFA already received; 6 000 FCFA left to pay.', 'between two instalments: what was received and what is left');

$s = shop_with_order();
$GLOBALS['orders']->setState($GLOBALS['orders']->find(9), FakeShopOrders::CANCELED, false);
$answer = $s->statusAnswer()->answer($GLOBALS['orders']->find(9));
check($answer['status'] === 'failed' && $answer['reason'] === 'order_cancelled' && !isset($answer['retry']), 'an order cancelled: no retry from here');

// --- The payer's return ------------------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
$where = $s->returnFlow()->handle($GLOBALS['orders']->find(9));
check(str_contains($where['url'], 'order-confirmation') && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID, 'back from Wajub, paid: the order is paid, the payer sees its confirmation');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'cancelled'));
$where = $s->returnFlow()->handle($GLOBALS['orders']->find(9));
check($where['url'] === 'https://shop.test/order' && $where['tone'] === 'info' && str_contains($where['message'], 'You cancelled the payment. Your cart is back'), 'cancelled on Wajub\'s page: back to the checkout, with the cart, said kindly');
check($GLOBALS['orders']->state(9) === FakeShopOrders::CANCELED && $GLOBALS['storefront']->restored === [9], 'the order is cancelled and its cart given back');
check(end($GLOBALS['orders']->history)['mail'] === false && $GLOBALS['orders']->noted(9, 'left Wajub payment trx_test_1 without paying'), 'without PrestaShop\'s e-mail, with a note for the merchant');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'failed'));
$where = $s->returnFlow()->handle($GLOBALS['orders']->find(9));
check($where['tone'] === 'error' && str_contains($where['message'], 'declined or failed'), 'refused on Wajub\'s page: said so');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$where = $s->returnFlow()->handle($GLOBALS['orders']->find(9));
check(str_contains($where['url'], 'order-confirmation') && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['storefront']->restored === [], 'not final yet: the confirmation page follows it, the order kept');
check($GLOBALS['orders']->noted(9, 'has not confirmed payment trx_test_1 yet'), 'with a note for the merchant');

$s = shop_with_order();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_1'] = [network_down()];
$where = $s->returnFlow()->handle($GLOBALS['orders']->find(9));
check(str_contains($where['url'], 'order-confirmation') && $GLOBALS['storefront']->restored === [], 'Wajub out of reach: never a cancelled order, the confirmation page follows it');

// --- Leaving the payment ------------------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[200, ['id' => 'trx_test_1', 'status' => 'cancelled']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CHECKOUT && $left['message'] === 'Your cart is back: choose another payment method.', '"Use another payment method": the cart back at the checkout');
check(in_array('DELETE ' . API . '/payments/trx_test_1', $GLOBALS['http']->routes(), true) && $GLOBALS['payments']->find('trx_test_1')->status === 'cancelled', 'the payment cancelled with Wajub first: it can never be approved afterwards');
check($GLOBALS['orders']->state(9) === FakeShopOrders::CANCELED && $GLOBALS['storefront']->restored === [9], 'the order cancelled, its cart given back');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'processing'));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING, 'Wajub is taking the payment: never a second one, the confirmation page follows it');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [network_down()];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::STAY && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['storefront']->restored === [], 'the payment cannot be cancelled (Wajub out of reach): nothing changes, the payer may ask again');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[409, ['message' => 'In progress']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING, 'Wajub refuses to cancel it (it is being paid): the confirmation page follows it');

$s = shop_with_order('sandbox', 10000.0);
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded', 10000, ['amount_paid' => 4000]));
refunds_as('trx_test_1', [200, ['items' => []]]);
apply_read($s, 'trx_test_1');
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'amount' => 6000.0]);
read_as('trx_test_2', payment_data('trx_test_2', 'expired', 6000));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'expired');
check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state(9) === FakeShopOrders::PARTIAL, 'an order Wajub holds money for is never cancelled');

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID, 'paid a moment before the payer left: the order is paid');

$s = shop_with_order();
$GLOBALS['orders']->setState($GLOBALS['orders']->find(9), FakeShopOrders::CANCELED, false);
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CHECKOUT && $left['message'] === '' && $GLOBALS['storefront']->restored === [] && $GLOBALS['http']->sent === [], 'given back already (a second click): the checkout, nothing done twice');

// Two payments open: the Card one the checkout leaves, and a Mobile Money
// one a second tab started, still being approved.
$s = shop_with_order();
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'idCart' => 109, 'amount' => 15000.0, 'method' => 'card']);
read_as('trx_test_1', payment_data('trx_test_1', 'processing'));
read_as('trx_test_2', payment_data('trx_test_2', 'pending'));
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_2'] = [[200, ['id' => 'trx_test_2', 'status' => 'cancelled']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['storefront']->restored === [], 'another payment of the order still being approved: never cancelled, the confirmation page follows it');
check(!in_array('DELETE ' . API . '/payments/trx_test_1', $GLOBALS['http']->routes(), true) && $GLOBALS['payments']->find('trx_test_2')->status === 'cancelled', 'that one left alone; the one the payer left cancelled');

$s = shop_with_order();
saved_payment(['paymentId' => 'trx_test_2', 'idOrder' => 9, 'idCart' => 109, 'amount' => 15000.0, 'method' => 'card']);
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
read_as('trx_test_2', payment_data('trx_test_2', 'failed'));
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[200, ['id' => 'trx_test_1', 'status' => 'cancelled']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'failed');
check($left['to'] === Release::TO_CHECKOUT && $GLOBALS['orders']->state(9) === FakeShopOrders::CANCELED && $GLOBALS['payments']->find('trx_test_1')->status === 'cancelled', 'an older payment nobody paid: cancelled too before the order is');
check(str_contains($left['message'], 'declined or failed'), 'the payer reads why the payment they left ended');

// Two leaves at once (the checkout's "Use another payment method", "Try
// again" in another tab): each reads the order under the lock.
$s = shop_with_order();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_1'] = [[200, payment_data('trx_test_1', 'pending')], [200, payment_data('trx_test_1', 'cancelled')]];
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[200, ['id' => 'trx_test_1', 'status' => 'cancelled']]];
$first = $GLOBALS['orders']->find(9);
$second = $GLOBALS['orders']->find(9);
$s->release()->leave($first, 'switch');
$left = $s->release()->leave($second, 'failed');
check($left['to'] === Release::TO_CHECKOUT && $GLOBALS['storefront']->restored === [9], 'the second, which read the order before the first gave it back: the cart given back once');

// Wajub no longer has the payment, or will not let the key touch it.
$s = shop_with_order();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_1'] = [[404, ['message' => 'Not found']]];
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[404, ['message' => 'Not found']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CHECKOUT && $GLOBALS['orders']->state(9) === FakeShopOrders::CANCELED && $GLOBALS['storefront']->restored === [9], 'a payment Wajub no longer has can no longer be charged: the cart given back, not a confirmation page that never moves');
check($GLOBALS['payments']->find('trx_test_1')->state === PaymentRecord::FINAL, 'and it is set aside');

$s = shop_with_order();
$GLOBALS['http']->routes['GET ' . API . '/payments/trx_test_1'] = [[401, ['message' => 'Unauthenticated.']]];
$GLOBALS['http']->routes['DELETE ' . API . '/payments/trx_test_1'] = [[401, ['message' => 'Unauthenticated.']]];
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::STAY && $GLOBALS['orders']->state(9) === FakeShopOrders::AWAITING && $GLOBALS['storefront']->restored === [], 'a key Wajub refuses: the payment may still be charged, nothing changes');

// A visitor whose session is not the order's customer's: a guest PrestaShop
// signed out on the confirmation page, the link opened in another browser.
$s = shop_with_order();
$GLOBALS['storefront']->visitorIsCustomer = false;
read_as('trx_test_1', payment_data('trx_test_1', 'failed'));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'failed');
check($left['to'] === Release::TO_CHECKOUT && !str_contains($left['message'], 'Your cart is back') && str_contains($left['message'], 'could not be restored here'), '"Try again" where the cart cannot come back: the payer is told so, never that it is back');
check($GLOBALS['orders']->state(9) !== FakeShopOrders::CANCELED && $GLOBALS['orders']->noted(9, 'could not be given back to them as a cart'), 'the order is left as it is, with a note for the merchant');

$s = shop_with_order();
$GLOBALS['storefront']->visitorIsCustomer = false;
read_as('trx_test_1', payment_data('trx_test_1', 'pending'));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'switch');
check($left['to'] === Release::TO_CONFIRMATION && !in_array('DELETE ' . API . '/payments/trx_test_1', $GLOBALS['http']->routes(), true), 'nor is a payment nobody paid yet cancelled for them: the confirmation page follows it');

$s = shop_with_order();
$GLOBALS['storefront']->copyFails = true;
read_as('trx_test_1', payment_data('trx_test_1', 'cancelled'));
$left = $s->release()->leave($GLOBALS['orders']->find(9), 'cancelled');
check($left['to'] === Release::TO_CHECKOUT && str_contains($left['message'], 'could not be restored here') && $left['tone'] === 'error', 'a cart that could not be copied: the payer is not told it is back');

// --- What counts as paid: the order's total as Wajub counts it -----------------------------------

$s = payment_services();
$GLOBALS['orders']->add(40, 14999.4, 'XAF');
saved_payment(['paymentId' => 'trx_test_r1', 'idOrder' => 40, 'amount' => 14999.0]);
check($s->starter()->activationParams($GLOBALS['orders']->find(40), cart_view(140, 14999.4), 'mobile_money', 'Order #X')['amount'] === 14999.0, 'a total PrestaShop keeps with decimals XAF has not: asked as Wajub counts it');
$applied = $s->settlement()->apply($GLOBALS['orders']->find(40), $GLOBALS['payments']->find('trx_test_r1'), ['outcome' => 'succeeded', 'status' => 'succeeded', 'transaction' => payment_data('trx_test_r1', 'succeeded', 14999.0)], Settlement::SEEN_NOW);
check($applied === 'succeeded' && $GLOBALS['orders']->state(40) === FakeShopOrders::PAID && !$GLOBALS['orders']->noted(40, 'left to pay') && !$GLOBALS['orders']->noted(40, 'more than its total'), 'paid what was asked: the order is paid, nothing said left to pay, nor held in excess');
$GLOBALS['orders']->add(41, 10.124, 'TND');
saved_payment(['paymentId' => 'trx_test_r2', 'idOrder' => 41, 'amount' => 10.12, 'currency' => 'TND']);
$s->settlement()->apply($GLOBALS['orders']->find(41), $GLOBALS['payments']->find('trx_test_r2'), ['outcome' => 'succeeded', 'status' => 'succeeded', 'transaction' => payment_data('trx_test_r2', 'succeeded', 10.12, ['currency' => 'TND'])], Settlement::SEEN_NOW);
check($GLOBALS['orders']->state(41) === FakeShopOrders::PAID && $s->ledger()->due($GLOBALS['orders']->find(41), 'sandbox') === 0.0, 'a currency of three decimals, paid to the cent: paid');

// --- The order confirmation e-mail, held until the order is paid -------------------------------

$sentMails = [];
$held = new HeldConfirmations(new MemoryStateStore(), static function (array $mail) use (&$sentMails): bool {
    $sentMails[] = $mail;

    return true;
});
$conf = ['template' => 'order_conf', 'idLang' => 1, 'subject' => 'Order confirmation', 'templateVars' => ['{id_order}' => 9, '{payment}' => 'Mobile Money&nbsp;(waiting for validation)', '{products}' => '<tr>…</tr>'], 'to' => 'payer@example.cm', 'toName' => 'Amina Diallo', 'from' => null, 'fromName' => null, 'fileAttachment' => null, 'templatePath' => '/mails/', 'idShop' => 1, 'bcc' => null, 'replyTo' => null];
check(!$held->hold(['template' => 'payment'] + $conf) && !$held->hold(['fileAttachment' => ['content' => '%PDF']] + $conf) && !$held->holds(9), 'only the order confirmation, never one with an invoice attached');
check($held->hold($conf) && $held->holds(9) && $sentMails === [], 'the confirmation of an order being placed is held: PrestaShop sends nothing now');
check($held->release(9, 'Mobile Money') && count($sentMails) === 1, 'sent once the order is paid');
$mail = $sentMails[0] ?? ['templateVars' => [], 'to' => '', 'idShop' => 0];
check(($mail['templateVars']['{payment}'] ?? '') === 'Mobile Money' && ($mail['templateVars']['{products}'] ?? '') === '<tr>…</tr>' && $mail['to'] === 'payer@example.cm' && $mail['idShop'] === 1, 'as PrestaShop wrote it, without "waiting for validation"');
check(!$held->release(9, 'Mobile Money') && count($sentMails) === 1, 'once only');

// --- "Check status" ------------------------------------------------------------------------------------

$s = shop_with_order();
read_as('trx_test_1', payment_data('trx_test_1', 'succeeded'));
$checked = (new OrderPanel($s))->check(9);
check($checked['ok'] && $checked['message'] === 'Wajub confirmed the payment: the order is now marked as paid.' && $checked['tone'] === 'success' && $checked['reload'] && $GLOBALS['orders']->state(9) === FakeShopOrders::PAID, '"Check status" reads the payment and settles the order, saying so');
$view = (new OrderPanel($s))->view(9);
check($view !== null && $view['payments'][0]['id'] === 'trx_test_1' && $view['payments'][0]['environment_label'] === 'Sandbox' && $view['payments'][0]['url'] === 'https://dashboard.wajub.com/payments/trx_test_1', 'the panel: the payment, its environment, its link in the Wajub Dashboard');
check($view['payments'][0]['method'] === 'Mobile Money · MTN Mobile Money' && $view['notes'] !== [], 'how it was paid, and the notes');
$GLOBALS['locks']->busy['order:9'] = true;
check(!(new OrderPanel($s))->check(9)['ok'], 'the order being settled elsewhere: try again in a moment');

done('settlement');

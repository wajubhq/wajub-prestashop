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
 * The money of an order paid in more than one payment: a port of the
 * WordPress plugin's tests/run-remainder-tests.php, its scenarios under
 * their own letters and numbers.
 *
 * A. One sum rule: what the order's payments collected, less their
 *    refunds, in one environment and the order's currency (Order\Ledger,
 *    Order\Settlement).
 * B. "Pay the rest" (Checkout\Starter::rest()): what the order's payment
 *    becomes first (counted, taken up again, waited for, closed keeping what
 *    it collected, cancelled), then a payment for what is left.
 * C. The checkout's form, once part of the order is paid: the rest on
 *    Wajub's page.
 * D. Webhooks of the order's payments (Webhook\PaymentEventHandler), and of
 *    their disputes (Webhook\DisputeEventHandler, D10).
 * F. What the payer reads (Checkout\StatusAnswer).
 * G. The order page's panel and "Check status" (Admin\OrderPanel).
 * I. The payer's return (Checkout\ReturnFlow).
 * J. Two of everything at once.
 * L. Follow-ups.
 * P. An order paid once, refunded or cancelled by the merchant since: never
 *    paid, counted toward or checked again.
 *
 * The refunds' scenarios (E, and the refunds of D) are in
 * run-refund-tests.php, the scheduled task's (F8, L1) in
 * run-cleanup-tests.php.
 *
 *     php tests/run-remainder-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';
require_once __DIR__ . '/fakes/money.php';

use Wajub\PrestaShop\Admin\OrderPanel;
use Wajub\PrestaShop\Api\Client;
use Wajub\PrestaShop\Checkout\Release;
use Wajub\PrestaShop\Order\Settlement;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Webhook\DisputeEventHandler;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\PaymentEventHandler;

/**
 * Settlement::apply() of a payment that went through, as a read of it gives it.
 *
 * @param array<string, mixed> $payment
 */
function apply_succeeded(Services $s, int $idOrder, array $payment, string $seen = Settlement::SEEN_LATER): string
{
    $record = $s->payments()->find((string) $payment['id']) ?? money_payment($idOrder, (string) $payment['id'], ['amount' => (float) ($payment['amount'] ?? 10000)]);

    return $s->settlement()->apply($s->orders()->find($idOrder), $record, ['outcome' => Client::OUTCOME_SUCCEEDED, 'status' => (string) ($payment['status'] ?? 'succeeded'), 'transaction' => $payment], $seen);
}

/**
 * A payment as Wajub reads it, for an order: in Live, 10 000 FCFA, succeeded,
 * unless `$fields` says otherwise.
 *
 * @param array<string, mixed> $fields
 *
 * @return array<string, mixed>
 */
function payment(string $id, int $idOrder, string $status = 'succeeded', array $fields = []): array
{
    $amount = $fields['amount'] ?? 10000;

    return $fields + [
        'id' => $id,
        'status' => $status,
        'amount' => $amount,
        'amount_paid' => in_array($status, ['succeeded', 'partially_refunded', 'refunded'], true) ? $amount : 0,
        'currency' => 'XAF',
        'sandbox' => str_starts_with($id, 'trx_test_'),
        'channel' => 'cm.mtn',
        'metadata' => ['order_id' => (string) $idOrder],
    ];
}

/**
 * "Pay the rest" of the order, as controllers/front/pay.php asks it, under
 * the order's lock.
 *
 * @return array{ok: bool, data: array<string, mixed>}
 */
function pay_rest(Services $s, int $idOrder): array
{
    return $s->starter()->rest($s->orders()->find($idOrder), cart_view(500 + $idOrder, 10000.0), 'en');
}

/**
 * A payment's webhook, as Wajub signs it in `$environment`.
 *
 * @param array<string, mixed> $data
 */
function deliver(Services $s, string $type, array $data, string $environment = 'live'): void
{
    $handler = new PaymentEventHandler($s->payments(), static fn (int $idShop): Services => $s, $GLOBALS['logger']);
    $handler->handle(new Event('evt_' . bin2hex(random_bytes(4)), $type, $data, $environment));
}

function held(Services $s, int $idOrder, string $environment = 'live'): float
{
    return $s->ledger()->held($s->orders()->find($idOrder), $environment);
}

function due(Services $s, int $idOrder, string $environment = 'live'): float
{
    return $s->ledger()->due($s->orders()->find($idOrder), $environment);
}

function is_paid(int $idOrder): bool
{
    return $GLOBALS['orders']->find($idOrder)->paid;
}

function record(string $paymentId): ?PaymentRecord
{
    return $GLOBALS['payments']->find($paymentId);
}

// === A. One sum rule: what the order's payments collected =========================

// A1 A payment in full, read right after it went through: counted for what it
// collected, which pays the order. Its refunds are not read.
$s = money_shop();
money_order(100, 'trx_a1');
$outcome = apply_succeeded($s, 100, payment('trx_a1', 100), Settlement::SEEN_NOW);
check($outcome === Client::OUTCOME_SUCCEEDED && is_paid(100), 'A1: a payment in full pays the order: ' . $outcome);
check(record('trx_a1')->counted === 10000.0 && $GLOBALS['orders']->paymentsOf(100)[0]['transaction'] === 'trx_a1', 'A1: the order counts it, for what it collected, one PrestaShop payment named by its id');
check(notes_of(100) === 'Wajub confirmed the payment (MTN Mobile Money).' && sent() === '', 'A1: one note, no refunds read: ' . notes_of(100) . ' ' . sent());

// A2 Short: an instalment payment closed early, 4 000 of 10 000.
$s = money_shop();
money_order(101, 'trx_a2');
$short = payment('trx_a2', 101, 'succeeded', ['amount_paid' => 4000]);
$outcome = apply_succeeded($s, 101, $short, Settlement::SEEN_NOW);
check($outcome === Settlement::OUTCOME_SHORT && !is_paid(101) && record('trx_a2')->counted === 4000.0, 'A2: it counts for its 4 000, and leaves the order unpaid: ' . $outcome);
check(sent() === 'GET /payments/trx_a2/refunds', 'A2: closed early, it may have been closed giving its money back: its refunds are read first: ' . sent());
check($GLOBALS['orders']->state(101) === FakeShopOrders::PARTIAL, 'A2: the order is "Partially paid with Wajub"');
check(noted(101, 'trx_a2 collected ' . fcfa(4000)) === 1 && noted(101, fcfa(6000) . ' of its total of ' . fcfa(10000) . ' is left to pay. The customer pays it with "Pay the rest"') === 1, 'A2: the merchant reads what it collected, what is left, and how the customer pays it: ' . notes_of(101));
$GLOBALS['api']->calls();
$again = apply_succeeded($s, 101, $short, Settlement::SEEN_NOW);
check($again === Settlement::OUTCOME_SHORT && noted(101, 'is left to pay') === 1 && sent() === '' && held($s, 101) === 4000.0, 'A2: read again, nothing more: no second note, no second read of its refunds, counted once');
check(due($s, 101) === 6000.0, 'A2: 6 000 is left to pay');

// A3 The rest, paid: the sum covers the order.
$outcome = apply_succeeded($s, 101, payment('trx_a3', 101, 'succeeded', ['amount' => 6000]));
check($outcome === Client::OUTCOME_SUCCEEDED && is_paid(101) && held($s, 101) === 10000.0, 'A3: the payment of the 6 000 left pays the order: ' . $outcome);
check(noted(101, 'The order is paid in full by Wajub payments trx_a2, trx_a3.') === 1, 'A3: the merchant reads which payments paid it: ' . notes_of(101));
check(count($GLOBALS['orders']->paymentsOf(101)) === 2, 'A3: two PrestaShop payments, one per Wajub payment');

// A4 Another currency counts nothing.
$s = money_shop();
money_order(102, 'trx_a4');
$outcome = apply_succeeded($s, 102, payment('trx_a4', 102, 'succeeded', ['currency' => 'EUR', 'amount' => 15]));
check($outcome === Settlement::OUTCOME_SHORT && $GLOBALS['orders']->paymentsOf(102) === [] && due($s, 102) === 10000.0, 'A4: a payment in euros counts nothing toward an order in FCFA');
check(noted(102, 'Wajub payment trx_a4 collected ' . money_of(15, 'EUR') . ', in another currency than this order\'s (XAF): it does not count toward the order. Refund it from the Wajub Dashboard.') === 1 && sent() === '', 'A4: the merchant reads what it collected, in its own currency, and to refund it: ' . notes_of(102));

// A5 A refund of a counted payment, pending: it takes its money back, until it fails.
$s = money_shop();
money_order(103);
$record = counted(103, 'trx_a5', 4000);
$s->refunds()->note($record, ['id' => 'rfd_a5', 'amount' => 1000, 'currency' => 'XAF', 'status' => 'pending'], 'wajub');
check(held($s, 103) === 3000.0 && due($s, 103) === 7000.0, 'A5: a pending refund of 1 000 leaves 3 000 held, 7 000 to pay');
$s->refunds()->note($record, ['id' => 'rfd_a5', 'status' => 'failed'], 'wajub');
check(held($s, 103) === 4000.0 && $s->refunds()->find('rfd_a5')->status === 'failed', 'A5: once it failed, the 4 000 are held again');
$s->refunds()->note($record, ['id' => 'rfd_a5', 'status' => 'pending'], 'wajub');
check($s->refunds()->find('rfd_a5')->status === 'failed', 'A5: a status further back never replaces it');
$s->refunds()->note($record, ['id' => 'rfd_a5b', 'amount' => 500, 'currency' => 'XAF', 'status' => 'canceled'], 'wajub');
check($s->refunds()->find('rfd_a5b')->status === 'cancelled' && held($s, 103) === 4000.0, 'A5: "canceled" is "cancelled": it takes nothing back');
$s->refunds()->note($record, ['id' => 'not_a_refund', 'amount' => 500, 'currency' => 'XAF', 'status' => 'succeeded'], 'wajub');
check(held($s, 103) === 4000.0, 'A5: a refund id that is not one of Wajub is never kept');

// A6, A12 The total lowered after a short payment: its payments cover it, and
// the order is paid with no payment. Wajub holding more than the total is
// pointed out, once.
$s = money_shop();
money_order(104, 'trx_a6');
counted(104, 'trx_a6', 4000);
$GLOBALS['orders']->orders[104]['total'] = 3500.0;
check($s->settlement()->completeIfCovered($s->orders()->find(104), 'live') && is_paid(104) && sent() === '', 'A6: a total lowered to 3 500 is paid by the 4 000 counted, with no API call');
check(noted(104, 'Wajub holds ' . fcfa(4000) . ' for this order, ' . fcfa(500) . ' more than its total of ' . fcfa(3500)) === 1, 'A12: the merchant reads that Wajub holds 500 more than the total: ' . notes_of(104));
check($s->settlement()->completeIfCovered($s->orders()->find(104), 'live') === false && noted(104, 'more than its total') === 1, 'A12: once');
// A refund pending when the rest was started fails, and the rest pays:
// Wajub holds more than the order's total.
$s = money_shop();
money_order(105);
$record = counted(105, 'trx_a12p', 4000);
$s->refunds()->note($record, ['id' => 'rfd_a12', 'amount' => 4000, 'currency' => 'XAF', 'status' => 'pending'], 'wajub');
$s->refunds()->note($record, ['id' => 'rfd_a12', 'status' => 'cancelled'], 'wajub');
money_payment(105, 'trx_a12r');
apply_succeeded($s, 105, payment('trx_a12r', 105), Settlement::SEEN_NOW);
check(is_paid(105) && noted(105, fcfa(4000) . ' more than its total') === 1, 'A12: the rest asked while a refund was pending, which then failed: the surplus is pointed out once paid: ' . notes_of(105));

// A8 Each environment counts on its own: a Sandbox payment never pays for a live one.
$s = money_shop();
money_order(108, 'trx_a8r', 10000.0, ['amount' => 6000.0]);
counted(108, 'trx_test_a8p', 4000, 'sandbox');
$outcome = apply_succeeded($s, 108, payment('trx_a8r', 108, 'succeeded', ['amount' => 6000]), Settlement::SEEN_NOW);
check($outcome === Settlement::OUTCOME_SHORT && !is_paid(108), 'A8: a live payment of 6 000 does not complete an order 4 000 of which were paid in Sandbox: ' . $outcome);
check(held($s, 108, 'sandbox') === 4000.0 && held($s, 108, 'live') === 6000.0, 'A8: each environment holds its own');
check(noted(108, 'trx_a8r collected') === 1, 'A8: noted');

// A9 The order's currency changed since: what was paid in the old one counts nothing.
$s = money_shop();
money_order(109);
counted(109, 'trx_a9', 4000);
$GLOBALS['orders']->orders[109]['currency'] = 'EUR';
check(held($s, 109) === 0.0 && due($s, 109) === 10000.0, 'A9: an order now in euros counts nothing paid in FCFA');
$view = (new OrderPanel($s))->view(109);
check($view['payments'][0]['not_counted'] === true, 'A9: the order page says so');

// A11 Its refunds cannot be read: it does not count yet, and the order is left
// as it is. Read later, it counts.
$s = money_shop();
money_order(111, 'trx_a11');
$GLOBALS['api']->next['GET /payments/trx_a11/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$late = payment('trx_a11', 111, 'succeeded', ['amount_paid' => 4000]);
check(apply_succeeded($s, 111, $late) === Client::OUTCOME_PENDING && $GLOBALS['orders']->paymentsOf(111) === [] && notes_of(111) === '', 'A11: refunds Wajub does not answer for: not counted, nothing noted');
check(record('trx_a11')->state === PaymentRecord::UNCOUNTED, 'A11: kept as a payment that went through, not counted yet');
$second = apply_succeeded($s, 111, $late);
check($second === Settlement::OUTCOME_SHORT && held($s, 111) === 4000.0 && record('trx_a11')->state === PaymentRecord::FINAL, 'A11: read again, it counts: ' . $second . ' ' . sent());
// A key that may not read refunds (a restricted key made by hand): counted without them.
money_order(112, 'trx_a11b');
$GLOBALS['api']->next['GET /payments/trx_a11b/refunds'] = [[403, ['message' => 'Forbidden']]];
check(apply_succeeded($s, 112, payment('trx_a11b', 112, 'succeeded', ['amount_paid' => 4000])) === Settlement::OUTCOME_SHORT && held($s, 112) === 4000.0, 'A11: refunds this key may not read: counted without them');
// Unless the payment reads as refunded. Refunded in full, it holds nothing:
// it counts nothing, and never pays the order.
money_order(114, 'trx_a11c');
$GLOBALS['api']->next['GET /payments/trx_a11c/refunds'] = [[403, ['message' => 'Forbidden']]];
check(apply_succeeded($s, 114, payment('trx_a11c', 114, 'refunded', ['amount_paid' => 10000])) === Settlement::OUTCOME_SHORT && !is_paid(114) && $GLOBALS['orders']->paymentsOf(114) === [] && due($s, 114) === 10000.0, 'A11: refunded in full, its refunds refused to this key: it counts nothing, the order left to pay in full');
// Refunded in part, how much is not known: not counted, and the merchant reads why, once.
money_order(115, 'trx_a11d');
$GLOBALS['api']->next['GET /payments/trx_a11d/refunds'] = [[403, ['message' => 'Forbidden']], [403, ['message' => 'Forbidden']]];
$partly = payment('trx_a11d', 115, 'partially_refunded', ['amount_paid' => 10000]);
check(apply_succeeded($s, 115, $partly) === Client::OUTCOME_PENDING && apply_succeeded($s, 115, $partly) === Client::OUTCOME_PENDING && !is_paid(115) && $GLOBALS['orders']->paymentsOf(115) === [], 'A11: refunded in part, its refunds refused to this key: not counted');
check(noted(115, 'Wajub payment trx_a11d was partly refunded, and this shop\'s key may not read its refunds (refund.read)') === 1 && count($GLOBALS['orders']->notes[115]) === 1, 'A11: the merchant reads why, once: ' . notes_of(115));

// A13 Refunds read before a late count take what they gave back off, and the
// merchant reads it.
$s = money_shop();
money_order(113, 'trx_a13');
$GLOBALS['api']->refunds['trx_a13'] = [['id' => 'rfd_a13', 'transaction' => 'trx_a13', 'amount' => 3000, 'currency' => 'XAF', 'status' => 'succeeded']];
apply_succeeded($s, 113, payment('trx_a13', 113, 'partially_refunded', ['amount_paid' => 10000]));
check(!is_paid(113) && held($s, 113) === 7000.0 && $s->refunds()->find('rfd_a13')->orderUnpaid, 'A13: a payment refunded 3 000 before it counts holds 7 000');
check(noted(113, 'trx_a13, which paid part of this order, has a refund of ' . fcfa(3000) . '. ' . fcfa(3000) . ' of the order is now left to pay.') === 1, 'A13: and the merchant reads the refund, and what is left: ' . notes_of(113));

// === B. "Pay the rest": what the order's payment becomes, then the rest ===========

// B1 A payment short of the total, counted: the payer pays the rest only, and
// Wajub's page says so.
$s = money_shop();
money_order(200, 'trx_b1');
counted(200, 'trx_b1', 4000);
$GLOBALS['orders']->setState($s->orders()->find(200), FakeShopOrders::PARTIAL, false);
$result = pay_rest($s, 200);
$body = $GLOBALS['api']->bodies('POST /payments')[0] ?? [];
check(sent() === 'POST /payments' && $result['ok'] && str_starts_with((string) ($result['data']['redirect'] ?? ''), 'https://pay.wajub.com/trx_new_1'), 'B1: an order 4 000 of which are counted starts a new payment, with nothing to settle: ' . sent() . ' ' . json_encode($result));
check((float) ($body['amount'] ?? 0) === 6000.0 && spaced((string) ($body['description'] ?? '')) === 'Order #R200: ' . fcfa(4000) . ' already received; ' . fcfa(6000) . ' left to pay', 'B1: for the 6 000 left, which Wajub\'s page says: ' . json_encode($body));
check(($body['metadata']['order_id'] ?? '') === '200' && ($body['reference'] ?? '') === 'Order #R200-2' && ($body['accept'] ?? null) === ['types' => ['mobile_money', 'mobile']], 'B1: bound to the order, numbered after it, of the kind the order was placed with: ' . json_encode($body));
check($s->payments()->current(200)->paymentId === 'trx_new_1' && noted(200, 'Wajub payment trx_new_1 was started for the ' . fcfa(6000) . ' left to pay (' . fcfa(4000) . ' already received).') === 1, 'B1: the order holds it, and the merchant reads it: ' . notes_of(200));

// B3 A payment paid in instalments, between two: taken up again, on its own
// page, where the payer pays the next one. Nothing cancelled, nothing new.
$s = money_shop();
money_order(201, 'trx_b3');
$GLOBALS['api']->add('trx_b3', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '201']]);
$result = pay_rest($s, 201);
check(sent() === 'GET /payments/trx_b3' && $result === ['ok' => true, 'data' => ['redirect' => 'https://pay.wajub.com/trx_b3?locale=en']], 'B3: the payer goes back to its hosted page: ' . json_encode($result) . ' ' . sent());
check(record('trx_b3')->status === 'partial' && $GLOBALS['api']->payments['trx_b3']['status'] === 'partial' && $s->ledger()->instalmentsOpen($s->orders()->find(201)) !== null, 'B3: the order knows it is paid in instalments, the payment left open');
check(noted(201, 'The customer went back to Wajub payment trx_b3 to pay its next instalments (' . fcfa(4000) . ' of ' . fcfa(10000) . ' received so far).') === 1, 'B3: the merchant reads it: ' . notes_of(201));
$GLOBALS['api']->calls();
pay_rest($s, 201);
check(noted(201, 'went back to Wajub payment') === 1 && sent() === 'GET /payments/trx_b3', 'B3: again with nothing new paid: no second note');

// B5 Its session runs out in 5 minutes: it cannot be taken up again. It is
// closed, keeping what it collected, which counts, and the rest is asked for.
$s = money_shop();
money_order(206, 'trx_b5');
$GLOBALS['api']->add('trx_b5', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 300), 'metadata' => ['order_id' => '206']]);
$result = pay_rest($s, 206);
check(sent() === 'GET /payments/trx_b5, POST /payments/trx_b5/close, POST /payments' && ($GLOBALS['api']->bodies('POST /payments/trx_b5/close')[0] ?? []) === ['outcome' => 'keep'], 'B5: closed keeping what it collected, then the rest: ' . sent());
check((float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 6000.0 && held($s, 206) === 4000.0 && $result['ok'], 'B5: the new payment asks for the 6 000 left');
check(noted(206, 'trx_b5, paid in instalments, could no longer be completed: it was closed, keeping the ' . fcfa(4000)) === 1 && noted(206, 'trx_new_1 was started for the ' . fcfa(6000)) === 1 && noted(206, 'is left to pay') === 0, 'B5: the merchant reads why, and the new payment, not a second "left to pay": ' . notes_of(206));

// B6 The total raised since the plan started: it cannot pay what is left.
$s = money_shop();
money_order(207, 'trx_b6', 12000.0, ['amount' => 10000.0]);
$GLOBALS['api']->add('trx_b6', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '207']]);
pay_rest($s, 207);
check(sent() === 'GET /payments/trx_b6, POST /payments/trx_b6/close, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 8000.0, 'B6: a total raised to 12 000: closed, then the 8 000 left: ' . sent());

// B7, B18 An instalment with the payer right now: waited for. Nothing
// cancelled, nothing started; the payer follows the order.
$s = money_shop();
money_order(208, 'trx_b7');
$GLOBALS['api']->add('trx_b7', ['status' => 'processing', 'amount_paid' => 4000, 'updated_at' => gmdate('c', time() - 3600), 'metadata' => ['order_id' => '208']]);
$result = pay_rest($s, 208);
check(sent() === 'GET /payments/trx_b7' && !$result['ok'] && $result['data']['code'] === 'pending' && $result['data']['redirect'] === $GLOBALS['storefront']->confirmation($s->orders()->find(208)) && notes_of(208) === '', 'B7: an instalment being paid is waited for, on the order\'s page: ' . json_encode($result) . ' ' . notes_of(208));
$GLOBALS['api']->payments['trx_b7']['updated_at'] = gmdate('c', time() - 7 * 3600);
pay_rest($s, 208);
pay_rest($s, 208);
check(noted(208, 'An instalment of Wajub payment trx_b7 has been in progress since') === 1, 'B18: one in progress for 7 hours is pointed out to the merchant, once: ' . notes_of(208));

// B8 Closing refused while an instalment is in progress: waited for.
$s = money_shop();
money_order(209, 'trx_b8');
$GLOBALS['api']->add('trx_b8', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '209']]);
$GLOBALS['api']->next['POST /payments/trx_b8/close'] = [[409, ['message' => 'An instalment is in progress.', 'error_code' => 'payment_in_progress']]];
$result = pay_rest($s, 209);
check(!$result['ok'] && $result['data']['code'] === 'pending' && !str_contains(sent(), 'POST /payments,') && !str_ends_with(sent(), 'POST /payments'), 'B8: Wajub taking an instalment as it is closed: waited for: ' . sent());

// B9 Closing refused otherwise (a key that may not close it): nothing new
// starts, the merchant is told what to do.
$s = money_shop();
money_order(210, 'trx_b9');
$GLOBALS['api']->add('trx_b9', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '210']]);
$GLOBALS['api']->next['POST /payments/trx_b9/close'] = [[403, ['message' => 'Missing permission payment.write', 'error_code' => 'forbidden']], [403, ['message' => 'Missing permission payment.write', 'error_code' => 'forbidden']]];
$result = pay_rest($s, 210);
check(!$result['ok'] && $result['data']['code'] === 'busy' && $result['data']['message'] === 'Your payment could not be started. Please try again in a moment.' && !in_array('POST /payments', $GLOBALS['api']->sent(), true), 'B9: closing refused: the payer cannot start another payment: ' . json_encode($result));
pay_rest($s, 210);
check(noted(210, 'trx_b9, paid in instalments, collected ' . fcfa(4000) . ' but could not be closed (Missing permission payment.write (forbidden, HTTP 403))') === 1, 'B9: the merchant reads why, once: ' . notes_of(210));

// B10 Read open with nothing collected, then refused its cancellation because
// it collected an instalment meanwhile: read again, and taken up.
$s = money_shop();
money_order(211, 'trx_b10', 10000.0, ['amount' => 9000.0]);
$GLOBALS['api']->add('trx_b10', ['status' => 'pending', 'metadata' => ['order_id' => '211']]);
$GLOBALS['api']->next['GET /payments/trx_b10'] = [[200, ['transaction' => $GLOBALS['api']->payments['trx_b10']]]];
$GLOBALS['api']->next['DELETE /payments/trx_b10'] = [[409, ['message' => 'This payment has collected instalments.', 'error_code' => 'payment_partly_paid']]];
$GLOBALS['api']->payments['trx_b10']['status'] = 'partial';
$GLOBALS['api']->payments['trx_b10']['amount_paid'] = 4000;
$result = pay_rest($s, 211);
check(($result['data']['redirect'] ?? '') === 'https://pay.wajub.com/trx_b10?locale=en' && str_contains(sent(), 'DELETE /payments/trx_b10') && !in_array('POST /payments', $GLOBALS['api']->sent(), true), 'B10: its cancellation refused, it is read again, found paid in instalments, and taken up: ' . sent() . ' ' . json_encode($result));

// B11 Counted short, the total lowered since: the order is paid, nothing new.
$s = money_shop();
money_order(212, 'trx_b11');
counted(212, 'trx_b11', 4000);
$GLOBALS['orders']->orders[212]['total'] = 4000.0;
$result = pay_rest($s, 212);
check(sent() === '' && is_paid(212) && !$result['ok'] && $result['data']['code'] === 'paid', 'B11: a total lowered to what was paid: paid, no payment started: ' . json_encode($result));

// B12 Refunded since it went through (its status says so): it counts for what
// it still holds, never cancelled.
$s = money_shop();
money_order(213, 'trx_b12');
$GLOBALS['api']->add('trx_b12', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '213']]);
$GLOBALS['api']->refunds['trx_b12'] = [['id' => 'rfd_b12', 'transaction' => 'trx_b12', 'amount' => 3000, 'currency' => 'XAF', 'status' => 'succeeded']];
pay_rest($s, 213);
check(sent() === 'GET /payments/trx_b12, GET /payments/trx_b12/refunds, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 3000.0, 'B12: a payment partly refunded counts for its 7 000, and the 3 000 left are asked: ' . sent());

// B13 The rest's payment left open, nobody having paid it: the payer goes
// back to it, for the same 6 000 left (the plugin cancels it and asks again;
// one payment open either way). One for another amount (the total changed
// since) is cancelled, never counted, and the rest asked again.
$s = money_shop();
money_order(214);
counted(214, 'trx_b13p', 4000);
money_payment(214, 'trx_b13r', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_b13r', ['status' => 'pending', 'amount' => 6000, 'metadata' => ['order_id' => '214']]);
$result = pay_rest($s, 214);
check(sent() === 'GET /payments/trx_b13r' && $result === ['ok' => true, 'data' => ['redirect' => 'https://pay.wajub.com/trx_b13r?locale=en']] && held($s, 214) === 4000.0, 'B13: the open payment of the rest, still for what is left: the payer goes back to it, nothing new: ' . sent());
$GLOBALS['orders']->orders[214]['total'] = 11000.0;
$GLOBALS['api']->calls();
pay_rest($s, 214);
check(sent() === 'GET /payments/trx_b13r, DELETE /payments/trx_b13r, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 7000.0 && held($s, 214) === 4000.0, 'B13: one for another amount than what is left: cancelled, and the rest asked again: ' . sent());
check(noted(214, 'the earlier Wajub payment trx_b13r, not completed, was cancelled') === 1, 'B13: the merchant reads it: ' . notes_of(214));

// B14 What was paid in Sandbox does not count in Live, and the other way round.
$s = money_shop();
money_order(215);
counted(215, 'trx_test_b14', 4000, 'sandbox');
pay_rest($s, 215);
check(sent() === 'POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 10000.0 && spaced((string) ($GLOBALS['api']->bodies('POST /payments')[0]['description'] ?? '')) === 'Order #R215', 'B14: 4 000 paid in Sandbox: the shop in Live asks for the whole total: ' . json_encode($GLOBALS['api']->bodies('POST /payments')[0] ?? []));
$s = money_shop('sandbox');
money_order(216);
counted(216, 'trx_b14b', 4000, 'live');
pay_rest($s, 216);
check((float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 10000.0 && str_starts_with($GLOBALS['api']->headers('POST /payments')[0]['Authorization'] ?? '', 'sk_test.'), 'B14: 4 000 paid in Live: the shop in Sandbox takes a test payment of the whole total, with the Sandbox key');

// B15 Gone through in full, then refunded in full before it counted: it holds
// nothing, and the whole total is asked again.
$s = money_shop();
money_order(217, 'trx_b15');
$GLOBALS['api']->add('trx_b15', ['status' => 'succeeded', 'metadata' => ['order_id' => '217']]);
$GLOBALS['api']->refunds['trx_b15'] = [['id' => 'rfd_b15', 'transaction' => 'trx_b15', 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded']];
pay_rest($s, 217);
check(sent() === 'GET /payments/trx_b15, GET /payments/trx_b15/refunds, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 10000.0 && !is_paid(217), 'B15: a payment refunded before it counted pays nothing, and the whole total is asked: ' . sent());
check(noted(217, 'trx_b15, which paid part of this order, has a refund of ' . fcfa(10000)) === 1, 'B15: the merchant reads its refund, once: ' . notes_of(217));

// B16 Closed meanwhile (in the Dashboard): read again, counted.
$s = money_shop();
money_order(218, 'trx_b16');
$GLOBALS['api']->add('trx_b16', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '218']]);
$GLOBALS['api']->next['POST /payments/trx_b16/close'] = [[409, ['message' => 'Already closed', 'error_code' => 'payment_already_closed']]];
$GLOBALS['api']->next['GET /payments/trx_b16'] = [[200, ['transaction' => $GLOBALS['api']->payments['trx_b16']]]];
$GLOBALS['api']->payments['trx_b16']['status'] = 'succeeded';
pay_rest($s, 218);
check(sent() === 'GET /payments/trx_b16, POST /payments/trx_b16/close, GET /payments/trx_b16, GET /payments/trx_b16/refunds, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 6000.0 && noted(218, 'could not be closed') === 0, 'B16: closed meanwhile, it is read again, counts, and the rest is asked: ' . sent());

// B17 Wajub not answering: the payment the order holds may still be paid.
// Nothing new starts until it can be settled.
$s = money_shop();
money_order(219, 'trx_b17');
$GLOBALS['api']->add('trx_b17', ['status' => 'pending', 'metadata' => ['order_id' => '219']]);
$GLOBALS['api']->next['GET /payments/trx_b17'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 219);
check(!$result['ok'] && $result['data']['code'] === 'busy' && !in_array('POST /payments', $GLOBALS['api']->sent(), true), 'B17: not read: the payer is stopped, nothing new: ' . sent() . ' ' . json_encode($result));
// Its cancellation not answered, read again open with nothing collected.
$s = money_shop();
money_order(220, 'trx_b17b', 10000.0, ['amount' => 9000.0]);
$GLOBALS['api']->add('trx_b17b', ['status' => 'pending', 'metadata' => ['order_id' => '220']]);
$GLOBALS['api']->next['DELETE /payments/trx_b17b'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 220);
check(!$result['ok'] && $result['data']['code'] === 'busy' && !in_array('POST /payments', $GLOBALS['api']->sent(), true), 'B17: its cancellation not answered: the payer is stopped too: ' . sent());
// One this key cannot see (404: the other environment's): it can charge nothing.
$s = money_shop();
money_order(221, 'trx_b17c');
$result = pay_rest($s, 221);
check(sent() === 'GET /payments/trx_b17c, DELETE /payments/trx_b17c, POST /payments' && $result['ok'], 'B17: one Wajub has not for this key (404) can charge nothing: the new payment starts: ' . sent());

// B19 Gone through, short of the total, its refunds not answered: it cannot
// count yet, and may hold money. Nothing new starts.
$s = money_shop();
money_order(222, 'trx_b19');
$GLOBALS['api']->add('trx_b19', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '222']]);
$GLOBALS['api']->next['GET /payments/trx_b19/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 222);
check(!$result['ok'] && in_array($result['data']['code'], ['pending', 'busy'], true) && !in_array('POST /payments', $GLOBALS['api']->sent(), true) && $GLOBALS['orders']->paymentsOf(222) === [], 'B19: its refunds not answered: not counted, the payer stopped, nothing started: ' . sent() . ' ' . json_encode($result));
check($s->statusAnswer()->payable($s->orders()->find(222)) === false, 'B19: nor offered to pay from the order\'s pages');

// B19b The order's payment reads as refunded in full, its refunds refused to
// this key: it holds nothing, so the payer pays the order in full.
$s = money_shop();
money_order(298, 'trx_b19b');
$GLOBALS['api']->add('trx_b19b', ['status' => 'refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '298']]);
$GLOBALS['api']->next['GET /payments/trx_b19b/refunds'] = [[403, ['message' => 'Forbidden']]];
$result = pay_rest($s, 298);
check($result['ok'] && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 10000.0 && !is_paid(298), 'B19b: refunded in full, its refunds refused: a new payment for the whole total: ' . sent());
// Refunded in part: the payer is stopped, as when Wajub does not answer.
$s = money_shop();
money_order(299, 'trx_b19c');
$GLOBALS['api']->add('trx_b19c', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '299']]);
$GLOBALS['api']->next['GET /payments/trx_b19c/refunds'] = [[403, ['message' => 'Forbidden']]];
$result = pay_rest($s, 299);
check(!$result['ok'] && !in_array('POST /payments', $GLOBALS['api']->sent(), true) && noted(299, 'trx_b19c was partly refunded') === 1, 'B19b: refunded in part, its refunds refused: nothing started, the merchant told: ' . sent());

// B20 A plan in another currency than the order's (changed since): what it
// collected counts nothing toward the order, so it is neither taken up nor
// closed for it. The payer is stopped; the merchant settles it.
$s = money_shop();
money_order(223, 'trx_b20');
$GLOBALS['api']->add('trx_b20', ['status' => 'partial', 'currency' => 'EUR', 'amount' => 15, 'amount_paid' => 6, 'metadata' => ['order_id' => '223']]);
$result = pay_rest($s, 223);
check(!$result['ok'] && $result['data']['code'] === 'busy' && sent() === 'GET /payments/trx_b20' && $GLOBALS['api']->payments['trx_b20']['status'] === 'partial', 'B20: a plan in euros for an order in FCFA: left open, nothing new: ' . sent());
check(noted(223, 'Wajub payment trx_b20, paid in instalments, collected ' . money_of(6, 'EUR') . ', in another currency than the order\'s, so no new payment was started.') === 1, 'B20: the merchant reads why, in the payment\'s currency: ' . notes_of(223));
pay_rest($s, 223);
check(noted(223, 'in another currency') === 1, 'B20: once');

// B21 Closing not answered: Wajub may have closed it all the same. Read again.
$s = money_shop();
money_order(224, 'trx_b21');
$GLOBALS['api']->add('trx_b21', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '224']]);
$GLOBALS['api']->next['POST /payments/trx_b21/close'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$GLOBALS['api']->next['GET /payments/trx_b21'] = [[200, ['transaction' => $GLOBALS['api']->payments['trx_b21']]], [200, ['transaction' => ['status' => 'succeeded'] + $GLOBALS['api']->payments['trx_b21']]]];
$result = pay_rest($s, 224);
check(sent() === 'GET /payments/trx_b21, POST /payments/trx_b21/close, POST /payments/trx_b21/close, GET /payments/trx_b21, GET /payments/trx_b21/refunds, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 6000.0 && $result['ok'], 'B21: closed though the answer was lost: read again, counted, and the rest asked: ' . sent());
check(noted(224, 'could not be closed') === 0 && noted(224, 'trx_new_1 was started for the ' . fcfa(6000)) === 1, 'B21: no "could not be closed" note: ' . notes_of(224));
$s = money_shop();
money_order(225, 'trx_b21b');
$GLOBALS['api']->add('trx_b21b', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '225']]);
$GLOBALS['api']->next['POST /payments/trx_b21b/close'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 225);
check(!$result['ok'] && !in_array('POST /payments', $GLOBALS['api']->sent(), true) && noted(225, 'trx_b21b, paid in instalments, collected ' . fcfa(4000) . ' but could not be closed (cURL error 28: Operation timed out') === 1, 'B21: not closed, still open once read again: the merchant reads why, nothing new: ' . sent() . ' ' . notes_of(225));

// B22 Counted as the rest is asked for: the new payment's note says what is
// left, and the payment's own webhook, late, adds none.
$s = money_shop();
money_order(226, 'trx_b22');
$GLOBALS['api']->add('trx_b22', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '226']]);
pay_rest($s, 226);
deliver($s, 'payment.succeeded', payment('trx_b22', 226, 'succeeded', ['amount_paid' => 4000]));
check(noted(226, 'is left to pay') === 0 && noted(226, 'was started for the ' . fcfa(6000) . ' left to pay') === 1 && $s->payments()->current(226)->paymentId === 'trx_new_1', 'B22: the late webhook of the payment counted before the rest started adds no note: ' . notes_of(226));

// B23 A paid order, a cancelled one, one paid once and refunded since: nothing starts.
$s = money_shop();
money_order(227, 'trx_b23');
counted(227, 'trx_b23', 10000);
mark_paid(227);
check(pay_rest($s, 227)['data']['code'] === 'paid' && sent() === '', 'B23: a paid order starts nothing');
$GLOBALS['orders']->setState($s->orders()->find(227), FakeShopOrders::REFUNDED, false);
check(pay_rest($s, 227)['data']['code'] === 'order_cancelled' && sent() === '' && !$s->statusAnswer()->payable($s->orders()->find(227)), 'B23: nor one paid once, refunded since');
money_order(228, 'trx_b23c');
$GLOBALS['orders']->setState($s->orders()->find(228), FakeShopOrders::CANCELED, false);
check(pay_rest($s, 228)['data']['code'] === 'order_cancelled' && sent() === '', 'B23: nor a cancelled one');

// === C. The checkout's form, once part of the order is paid ============================

// C1 Part of the order counted: the form's payment, for the whole total,
// cannot take the rest. Wajub's hosted page asks for it, of the form's kind;
// the form's payment is never activated.
$s = money_shop();
money_order(300);
counted(300, 'trx_c1', 4000);
$slot = money_payment(0, 'trx_c1_prep', ['state' => PaymentRecord::PREPARED, 'idCart' => 400, 'mode' => PaymentRecord::MODE_FIELDS, 'method' => 'card', 'idOrder' => 0]);
$GLOBALS['orders']->orders[300]['cart'] = 400;
$result = $s->starter()->fields($s->orders()->find(300), cart_view(400, 10000.0), 'card', $slot, 'en');
$body = $GLOBALS['api']->bodies('POST /payments')[0] ?? [];
check(sent() === 'POST /payments' && $result['ok'] && !isset($result['data']['fields']) && str_starts_with((string) ($result['data']['redirect'] ?? ''), 'https://pay.wajub.com/trx_new_'), 'C1: no activation; the rest on Wajub\'s hosted page: ' . sent() . ' ' . json_encode($result));
check((float) ($body['amount'] ?? 0) === 6000.0 && ($body['accept']['types'] ?? []) === ['card'] && str_contains(spaced((string) ($body['description'] ?? '')), fcfa(4000) . ' already received; ' . fcfa(6000) . ' left to pay'), 'C1: for the 6 000 left, of the form\'s kind, saying so: ' . json_encode($body));
check(record('trx_c1_prep')->state === PaymentRecord::PREPARED && record('trx_c1_prep')->idOrder === 0, 'C1: the form\'s payment stays the cart\'s, never activated');

// C4 The order's payment, short of the total, not counted yet: counted first
// (its refunds read), then the rest on the hosted page, never the form.
$s = money_shop();
money_order(303, 'trx_c4');
$GLOBALS['api']->add('trx_c4', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '303']]);
$result = $s->starter()->hosted($s->orders()->find(303), cart_view(403, 10000.0), 'mobile_money', 'en');
check(sent() === 'GET /payments/trx_c4, GET /payments/trx_c4/refunds, POST /payments' && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 6000.0, 'C4: the short payment counts, then the rest is asked for on the hosted page: ' . sent());

// === D. Webhooks of the order's payments ===========================================

// D1 The rest's payment goes through: with the payment counted before, it
// pays the order. Delivered in full of what it asked, its refunds are not read.
$s = money_shop();
money_order(400);
apply_succeeded($s, 400, payment('trx_d1p', 400, 'succeeded', ['amount_paid' => 4000]));
money_payment(400, 'trx_d1r', ['amount' => 6000.0]);
$GLOBALS['api']->calls();
deliver($s, 'payment.succeeded', payment('trx_d1r', 400, 'succeeded', ['amount' => 6000, 'channel' => 'cm.orange']));
check(is_paid(400) && noted(400, 'second payment') === 0 && noted(400, 'paid in full by Wajub payments trx_d1p, trx_d1r') === 1, 'D1: the rest, delivered, pays the order: ' . notes_of(400));
check(sent() === '', 'D1: the order\'s own payment, delivered in full of what it asked, has refunded nothing: no refunds read: ' . sent());

// D2 The first payment's webhook, late, after the rest paid: silent.
$notes = count($GLOBALS['orders']->notes[400]);
deliver($s, 'payment.succeeded', payment('trx_d1p', 400, 'succeeded', ['amount_paid' => 4000, 'channel' => 'card']));
check(count($GLOBALS['orders']->notes[400]) === $notes && $s->payments()->current(400)->paymentId === 'trx_d1r', 'D2: a late delivery of a payment that paid part of the order adds nothing, moves nothing: ' . notes_of(400));

// D3 The first payment's webhook, late, while the rest is under way: nothing
// moves, and the order's payment of the moment stays the rest's.
$s = money_shop();
money_order(401);
apply_succeeded($s, 401, payment('trx_d3p', 401, 'succeeded', ['amount_paid' => 4000]));
money_payment(401, 'trx_d3r', ['amount' => 6000.0, 'channel' => 'cm.mtn']);
$notes = count($GLOBALS['orders']->notes[401]);
deliver($s, 'payment.succeeded', payment('trx_d3p', 401, 'succeeded', ['amount_paid' => 4000, 'channel' => 'card']));
check(!is_paid(401) && count($GLOBALS['orders']->notes[401]) === $notes && $s->payments()->current(401)->paymentId === 'trx_d3r' && record('trx_d3r')->channel === 'cm.mtn', 'D3: the first payment, late, changes nothing of the payment the order holds now: ' . notes_of(401));

// D3b An earlier attempt, short of the total, delivered late while the order
// holds a newer one: it counts, but pays nothing, so nothing moves to it.
$s = money_shop();
money_order(413, 'trx_d3b_old');
money_payment(413, 'trx_d3b_new');
deliver($s, 'payment.succeeded', payment('trx_d3b_old', 413, 'succeeded', ['amount_paid' => 4000]));
check(held($s, 413) === 4000.0 && $s->payments()->current(413)->paymentId === 'trx_d3b_new' && in_array('GET /payments/trx_d3b_old/refunds', $GLOBALS['api']->sent(), true), 'D3b: an earlier attempt short of the total counts, its refunds read first, and moves nothing: ' . sent());

// D3c An earlier attempt delivered late, refunded in full before: it holds
// nothing, so it neither pays the order nor takes its place.
$s = money_shop();
money_order(414, 'trx_d3c_old');
money_payment(414, 'trx_d3c_new');
$GLOBALS['api']->refunds['trx_d3c_old'] = [['id' => 'rfd_d3c', 'transaction' => 'trx_d3c_old', 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded']];
deliver($s, 'payment.succeeded', payment('trx_d3c_old', 414));
check(!is_paid(414) && held($s, 414) === 0.0 && in_array('GET /payments/trx_d3c_old/refunds', $GLOBALS['api']->sent(), true), 'D3c: an earlier attempt refunded in full pays nothing: ' . notes_of(414));
check(noted(414, 'trx_d3c_old, which paid part of this order, has a refund of ' . fcfa(10000)) === 1, 'D3c: the merchant reads its refund: ' . notes_of(414));
// One in full, not refunded: it pays the order.
$s = money_shop();
money_order(415, 'trx_d3d_old');
money_payment(415, 'trx_d3d_new');
deliver($s, 'payment.succeeded', payment('trx_d3d_old', 415));
check(is_paid(415) && record('trx_d3d_old')->isCounted(), 'D3c: one in full pays the order: ' . notes_of(415));
// Its refunds not readable: nothing moves, and it is read again later.
$s = money_shop();
money_order(416, 'trx_d3e_old');
money_payment(416, 'trx_d3e_new');
$GLOBALS['api']->next['GET /payments/trx_d3e_old/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', payment('trx_d3e_old', 416));
check(!is_paid(416) && record('trx_d3e_old')->state === PaymentRecord::UNCOUNTED && $s->payments()->uncounted(10) !== [], 'D3c: its refunds not readable: nothing moves, and it is kept to be read again (Order\\Cleanup)');

// D9 Another currency: counted nothing, noted once.
$s = money_shop();
money_order(406, 'trx_d9');
deliver($s, 'payment.succeeded', payment('trx_d9', 406, 'succeeded', ['currency' => 'EUR', 'amount' => 15]));
deliver($s, 'payment.succeeded', payment('trx_d9', 406, 'succeeded', ['currency' => 'EUR', 'amount' => 15]));
check($GLOBALS['orders']->paymentsOf(406) === [] && noted(406, 'Wajub payment trx_d9 collected ' . money_of(15, 'EUR') . ', in another currency than this order\'s (XAF): it does not count toward the order. Refund it from the Wajub Dashboard.') === 1 && !is_paid(406), 'D9: a payment in euros counts nothing, and the merchant is told to refund it, once: ' . notes_of(406));

// D12 A live delivery that does not say its environment, while the shop is in
// Sandbox: counted in Live, the environment that signed it.
$s = money_shop('sandbox');
money_order(410, 'trx_d12');
$data = payment('trx_d12', 410, 'succeeded', ['amount_paid' => 4000]);
unset($data['sandbox']);
deliver($s, 'payment.succeeded', $data, 'live');
check(held($s, 410, 'live') === 4000.0 && held($s, 410, 'sandbox') === 0.0, 'D12: counted in Live');
// A delivery signed in the other environment than its payment's: never acted on.
money_order(417, 'trx_test_d12b');
deliver($s, 'payment.succeeded', payment('trx_test_d12b', 417), 'live');
check(!is_paid(417) && $GLOBALS['orders']->paymentsOf(417) === [], 'D12: a Sandbox payment delivered signed with the Live secret changes nothing');

// D13 A refund of the order's payment before it counts: known, and taken off
// once it does.
$s = money_shop();
money_order(411, 'trx_d13');
$s->refunds()->note(record('trx_d13'), ['id' => 'rfd_d13', 'amount' => 1000, 'currency' => 'XAF', 'status' => 'pending'], 'wajub', true);
deliver($s, 'payment.succeeded', payment('trx_d13', 411, 'succeeded', ['amount_paid' => 4000]));
check(held($s, 411) === 3000.0, 'D13: counted later, the payment holds what it collected less that refund: ' . held($s, 411));
check(noted(411, 'trx_d13, which paid part of this order, has a refund of ' . fcfa(1000) . '. ' . fcfa(7000) . ' of the order is now left to pay.') === 1, 'D13: once it counts, the merchant reads its refund and what is left: ' . notes_of(411));

// === F. What the payer reads ==========================================================

// F1 Part of the order paid, nothing new started: the confirmation page says
// what was received, what is left, and offers to pay it.
$s = money_shop();
money_order(600, 'trx_f1');
$GLOBALS['api']->add('trx_f1', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '600']]);
$answer = $s->statusAnswer()->answer($s->orders()->find(600));
check(($answer['partial'] ?? false) === true && spaced((string) ($answer['message'] ?? '')) === fcfa(4000) . ' already received; ' . fcfa(6000) . ' left to pay.' && ($answer['pay'] ?? false) === true, 'F1, F3: short of the total, counted: what is left, and "Pay the rest": ' . json_encode($answer));
check(spaced($s->statusAnswer()->payerLine($s->orders()->find(600), 'live')) === fcfa(4000) . ' already received; ' . fcfa(6000) . ' left to pay.' && $s->statusAnswer()->payable($s->orders()->find(600)), 'F1: the order\'s pages say what is left, and offer to pay it');
$GLOBALS['orders']->setState($s->orders()->find(600), FakeShopOrders::CANCELED, false);
check(!$s->statusAnswer()->payable($s->orders()->find(600)), 'F1: not once the order is cancelled');

// F3 The status the pages poll.
$s = money_shop();
money_order(603, 'trx_f3');
$GLOBALS['api']->add('trx_f3', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '603']]);
$answer = $s->statusAnswer()->answer($s->orders()->find(603));
check(($answer['status'] ?? '') === 'pending' && ($answer['processing'] ?? false) === true && ($answer['partial'] ?? false) === true && spaced((string) ($answer['message'] ?? '')) === fcfa(4000) . ' already received; ' . fcfa(6000) . ' left to pay.' && ($answer['pay'] ?? false) === true, 'F3: between two instalments: what was received, and where to pay the next: ' . json_encode($answer));
$s = money_shop();
money_order(604, 'trx_f3b');
$GLOBALS['api']->add('trx_f3b', ['status' => 'processing', 'amount_paid' => 4000, 'metadata' => ['order_id' => '604']]);
$answer = $s->statusAnswer()->answer($s->orders()->find(604));
check(($answer['processing'] ?? false) === true && !isset($answer['partial']) && !$s->statusAnswer()->payable($s->orders()->find(604)), 'F3: an instalment with the payer: a payment under way, followed, nothing to pay from here: ' . json_encode($answer));
$s = money_shop();
money_order(606, 'trx_f3d');
$GLOBALS['api']->add('trx_f3d', ['status' => 'succeeded', 'currency' => 'EUR', 'amount' => 15, 'metadata' => ['order_id' => '606']]);
$answer = $s->statusAnswer()->answer($s->orders()->find(606));
check(($answer['processing'] ?? false) === true && !isset($answer['partial']), 'F3: in another currency: followed, never "paid": ' . json_encode($answer));

// F4 The rest refused: the payer reads it, and pays the rest again, never
// "Try again" (the order is never given back as a cart).
$s = money_shop();
money_order(605);
counted(605, 'trx_f4p', 4000);
money_payment(605, 'trx_f4r', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_f4r', ['status' => 'failed', 'amount' => 6000, 'metadata' => ['order_id' => '605']]);
$answer = $s->statusAnswer()->answer($s->orders()->find(605));
check(($answer['status'] ?? '') === 'failed' && !isset($answer['retry']) && ($answer['pay'] ?? false) === true, 'F4: the rest refused: "Pay the rest", never "Try again": ' . json_encode($answer));

// F7 Every payment id of the order, the counted ones and the current one.
check(count(array_filter($s->payments()->forOrder(605), static fn (PaymentRecord $r): bool => $r->isCounted())) === 1, 'F7: one counted, one of the moment');

// F9 An order cancelled while Wajub held money for it: no retry, nothing to pay.
$s = money_shop();
money_order(650, 'trx_f9');
counted(650, 'trx_f9', 4000);
$GLOBALS['orders']->setState($s->orders()->find(650), FakeShopOrders::CANCELED, false);
$answer = $s->statusAnswer()->answer($s->orders()->find(650));
check(($answer['status'] ?? '') === 'failed' && ($answer['partial'] ?? false) === true && ($answer['message'] ?? '') === 'Part of this order was paid before it was cancelled. Contact the shop to complete it.' && !isset($answer['retry']) && !isset($answer['pay']), 'F9: the status says part of it was paid, with no retry, nothing to pay: ' . json_encode($answer));

// === G. The order page ===============================================================

// G1 Every payment counted: what it collected, its environment, its refunds;
// one of another environment says it does not count. While unpaid, what was
// received and what is left.
$s = money_shop();
money_order(700, 'trx_g1r');
counted(700, 'trx_g1p', 4000);
counted(700, 'trx_test_g1t', 2000, 'sandbox');
$s->refunds()->note(record('trx_g1p'), ['id' => 'rfd_g1', 'amount' => 1000, 'currency' => 'XAF', 'status' => 'succeeded'], 'wajub');
money_payment(700, 'trx_g1x');
$view = (new OrderPanel($s))->view(700);
$byId = array_column($view['payments'], null, 'id');
check(array_column($view['payments'], 'id') === ['trx_g1x', 'trx_test_g1t', 'trx_g1p', 'trx_g1r'], 'G1: the order page lists the payments, the newest first');
check(spaced($byId['trx_g1p']['counted']) === fcfa(4000) && spaced($byId['trx_g1p']['refunded']) === fcfa(1000) && $byId['trx_g1p']['environment_label'] === 'Live' && !$byId['trx_g1p']['not_counted'], 'G1: what each collected, its environment, its refunds');
check($byId['trx_test_g1t']['not_counted'] === true && $byId['trx_test_g1t']['environment_label'] === 'Sandbox', 'G1: one of another environment not counted');
check(spaced($view['received']) === fcfa(3000) . ' of ' . fcfa(10000) && spaced($view['left']) === fcfa(7000), 'G1: and what was received, and what is left: ' . json_encode([$view['received'], $view['left']]));
check(count($view['refunds']) === 1 && $view['refunds'][0]['id'] === 'rfd_g1' && $view['refunds'][0]['origin'] === 'Made in the Wajub Dashboard', 'G1: its refunds, and where they were made');
mark_paid(700);
check((new OrderPanel($s))->view(700)['left'] === '', 'G1: once paid, nothing is left');

// G3 A plan between two instalments.
$s = money_shop();
money_order(702, 'trx_g3');
$GLOBALS['api']->add('trx_g3', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '702']]);
$data = (new OrderPanel($s))->check(702);
check(spaced($data['message']) === 'The customer is paying in instalments: ' . fcfa(4000) . ' of ' . fcfa(10000) . ' received so far. The order is paid with the last instalment.' && !$data['reload'], 'G3: "Check status" of a plan says what its instalments collected: ' . $data['message']);
check((new OrderPanel($s))->view(702)['payments'][0]['status_label'] === 'Paying in instalments', 'G3: and the panel reads it as being paid in instalments');
$s = money_shop();
money_order(706, 'trx_g3e');
$GLOBALS['api']->add('trx_g3e', ['status' => 'partial', 'currency' => 'EUR', 'amount' => 15, 'amount_paid' => 6, 'metadata' => ['order_id' => '706']]);
$data = (new OrderPanel($s))->check(706);
check(str_contains(spaced($data['message']), money_of(6, 'EUR') . ' of ' . money_of(15, 'EUR')), 'G3: a plan in another currency than the order\'s, in its own: ' . $data['message']);

// G4 The total lowered since: paid, with no API call.
$s = money_shop();
money_order(703, 'trx_g4r');
counted(703, 'trx_g4p', 4000);
$GLOBALS['orders']->orders[703]['total'] = 4000.0;
$data = (new OrderPanel($s))->check(703);
check(is_paid(703) && $data['tone'] === 'success' && $data['reload'] && sent() === '', 'G4: "Check status" of an order its payments cover pays it, asking Wajub nothing: ' . sent());

// G5 Refunded since it went through: it counts for what it holds.
$s = money_shop();
money_order(704, 'trx_g5');
$GLOBALS['api']->add('trx_g5', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '704']]);
$GLOBALS['api']->refunds['trx_g5'] = [['id' => 'rfd_g5', 'transaction' => 'trx_g5', 'amount' => 2000, 'currency' => 'XAF', 'status' => 'succeeded']];
$data = (new OrderPanel($s))->check(704);
check(spaced($data['message']) === 'Wajub confirmed this payment: ' . fcfa(8000) . ' received so far, ' . fcfa(2000) . ' left to pay. The customer can pay the rest from the order in their account, or from its confirmation page.' && held($s, 704) === 8000.0, 'G5: a payment refunded since counts, and "Check status" says what is left: ' . $data['message']);
$s = money_shop();
money_order(705, 'trx_g5b');
$GLOBALS['api']->add('trx_g5b', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '705']]);
$GLOBALS['api']->next['GET /payments/trx_g5b/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$data = (new OrderPanel($s))->check(705);
check(str_contains($data['message'], 'could not be reached') && $GLOBALS['orders']->paymentsOf(705) === [], 'G5: its refunds not answered: not counted, and the merchant is told to check again: ' . $data['message']);

// G6 Each reason "Check status" gives.
$reasons = [
    'paid already' => [static function (): void {
        money_order(710, 'trx_g6a');
        counted(710, 'trx_g6a', 10000);
        mark_paid(710);
    }, 710, 'This order is already paid.', 'success'],
    'gone through' => [static function (): void {
        money_order(711, 'trx_g6b');
        $GLOBALS['api']->add('trx_g6b', ['metadata' => ['order_id' => '711']]);
    }, 711, 'Wajub confirmed the payment: the order is now marked as paid.', 'success'],
    'another order\'s' => [static function (): void {
        money_order(712, 'trx_g6c');
        $GLOBALS['api']->add('trx_g6c', ['metadata' => ['order_id' => '999']]);
    }, 712, 'This Wajub payment belongs to another order: this order is left as it is.', 'warning'],
    'another currency' => [static function (): void {
        money_order(713, 'trx_g6d');
        $GLOBALS['api']->add('trx_g6d', ['currency' => 'EUR', 'amount' => 15, 'metadata' => ['order_id' => '713']]);
    }, 713, 'Wajub confirmed this payment, but in another currency than the order\'s: it does not count toward the order, which is left unpaid. Refund it from the Wajub Dashboard.', 'warning'],
    'not found in this environment' => [static function (): void { money_order(714, 'trx_g6e'); }, 714, 'Wajub has no payment trx_g6e in Live for this shop\'s key: it was made with another Wajub account, or in the other environment. The order is left as it is.', 'warning'],
    'unreachable' => [static function (): void {
        money_order(715, 'trx_g6f');
        $GLOBALS['api']->next['GET /payments/trx_g6f'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
    }, 715, 'Wajub could not be reached, or has no answer for this payment yet. Check again in a moment.', 'warning'],
    'cancelled by the payer' => [static function (): void {
        money_order(716, 'trx_g6g');
        $GLOBALS['api']->add('trx_g6g', ['status' => 'cancelled', 'metadata' => ['order_id' => '716']]);
    }, 716, 'The customer did not complete the payment (Wajub status: cancelled). The order stays as it is: the customer can still pay it.', 'warning'],
    'refused' => [static function (): void {
        money_order(717, 'trx_g6h');
        $GLOBALS['api']->add('trx_g6h', ['status' => 'failed', 'metadata' => ['order_id' => '717']]);
    }, 717, 'The payment did not go through (Wajub status: failed). The order is marked as "Payment error".', 'error'],
    'not confirmed yet' => [static function (): void {
        money_order(718, 'trx_g6i');
        $GLOBALS['api']->add('trx_g6i', ['status' => 'pending', 'metadata' => ['order_id' => '718']]);
    }, 718, 'Wajub has not confirmed this payment yet (Wajub status: pending). Check again in a moment.', 'warning'],
    'no payment' => [static function (): void { money_order(719); }, 719, 'This order has no Wajub payment.', 'warning'],
];
foreach ($reasons as $case => [$setup, $idOrder, $message, $tone]) {
    $s = money_shop();
    $setup();
    $data = (new OrderPanel($s))->check($idOrder);
    check($data['message'] === $message && $data['tone'] === $tone, "G6 ({$case}): \"Check status\" says why: " . $data['message']);
}
// Refunded in part, its refunds refused to this key: said, not "could not be reached" (L6).
$s = money_shop();
money_order(968, 'trx_l6');
$GLOBALS['api']->add('trx_l6', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '968']]);
$GLOBALS['api']->next['GET /payments/trx_l6/refunds'] = [[403, ['message' => 'Forbidden']]];
$data = (new OrderPanel($s))->check(968);
check($data['message'] === 'Wajub confirmed this payment, but it was partly refunded and this shop\'s key may not read its refunds (refund.read): it does not count toward the order until the key may. Connect with Wajub again, or give the key refund.read in the Wajub Dashboard.' && $data['tone'] === 'warning', 'L6: refunds refused to the key: said, not "could not be reached": ' . $data['message']);
$s = money_shop();
money_order(969, 'trx_l6b');
$GLOBALS['api']->add('trx_l6b', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '969']]);
$GLOBALS['api']->next['GET /payments/trx_l6b/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
check(str_contains((new OrderPanel($s))->check(969)['message'], 'could not be reached'), 'L6: refunds Wajub did not answer for: "could not be reached"');
// L18 A payment that names no currency: never "in another currency".
$s = money_shop();
money_order(987, 'trx_l18');
$GLOBALS['api']->add('trx_l18', ['status' => 'succeeded', 'metadata' => ['order_id' => '987']]);
unset($GLOBALS['api']->payments['trx_l18']['currency']);
$data = (new OrderPanel($s))->check(987);
check(!is_paid(987) && $data['message'] === 'Wajub confirmed this payment, but it does not cover the order\'s total: the order is left unpaid. Check the payment in the Wajub Dashboard.', 'L18: no currency: as a payment short of the total: ' . $data['message']);
// The order page asks again, once loaded, about payments that could not count.
$s = money_shop();
money_order(720, 'trx_g7');
$GLOBALS['api']->add('trx_g7', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '720']]);
$GLOBALS['api']->next['GET /payments/trx_g7/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_g7']);
check(record('trx_g7')->state === PaymentRecord::UNCOUNTED && (new OrderPanel($s))->view(720)['refresh'] === true, 'G7: a payment that could not count: the order page asks about it once loaded');
$refreshed = (new OrderPanel($s))->refresh(720);
check($refreshed['changed'] && held($s, 720) === 4000.0 && record('trx_g7')->isCounted(), 'G7: read again as the page loads, it counts, and the page reloads: ' . json_encode($refreshed));
$GLOBALS['api']->calls();
check((new OrderPanel($s))->refresh(720)['changed'] === false && sent() === '', 'G7: asked again at once: nothing more, no call');

// === I. The payer's return ================================================================

// I1 Between two instalments: the payer paid what was asked so far.
$s = money_shop();
money_order(900, 'trx_i1');
$GLOBALS['api']->add('trx_i1', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '900']]);
$landing = $s->returnFlow()->handle($s->orders()->find(900));
check($landing['url'] === $GLOBALS['storefront']->confirmation($s->orders()->find(900)) && notes_of(900) === '' && $s->ledger()->instalmentsOpen($s->orders()->find(900)) !== null, 'I1: back between two instalments: to the order, no "not confirmed yet" note: ' . notes_of(900));

// I3 Back from the rest, paid: the order is paid, no refunds read.
$s = money_shop();
money_order(902);
counted(902, 'trx_i3p', 4000);
money_payment(902, 'trx_i3r', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_i3r', ['status' => 'succeeded', 'amount' => 6000, 'metadata' => ['order_id' => '902']]);
$s->returnFlow()->handle($s->orders()->find(902));
check(is_paid(902) && sent() === 'GET /payments/trx_i3r', 'I3: back from the rest, paid: the order is paid: ' . sent());

// I4 Back from a payment refunded since it went through: it counts.
$s = money_shop();
money_order(903, 'trx_i4');
$GLOBALS['api']->add('trx_i4', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '903']]);
$GLOBALS['api']->refunds['trx_i4'] = [['id' => 'rfd_i4', 'transaction' => 'trx_i4', 'amount' => 1000, 'currency' => 'XAF', 'status' => 'succeeded']];
$s->returnFlow()->handle($s->orders()->find(903));
check(held($s, 903) === 9000.0 && noted(903, 'not confirmed') === 0 && noted(903, 'is left to pay') === 1, 'I4: refunded since, it counts for what it holds: ' . notes_of(903));

// I5 The rest refused, the payer back: to the order's page, never the cart back.
$s = money_shop();
money_order(904);
counted(904, 'trx_i5p', 4000);
money_payment(904, 'trx_i5r', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_i5r', ['status' => 'failed', 'amount' => 6000, 'metadata' => ['order_id' => '904']]);
$landing = $s->returnFlow()->handle($s->orders()->find(904));
check($landing['url'] === $GLOBALS['storefront']->confirmation($s->orders()->find(904)) && $GLOBALS['storefront']->restored === [] && $GLOBALS['orders']->state(904) !== FakeShopOrders::CANCELED, 'I5: the rest refused: the order stays, part paid, its cart never given back');

// === J. Two of everything at once ==========================================================

// J1 The return and the webhook of the same payment: counted once, paid once.
$s = money_shop();
money_order(950);
counted(950, 'trx_j1p', 4000);
money_payment(950, 'trx_j1r', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_j1r', ['status' => 'succeeded', 'amount' => 6000, 'metadata' => ['order_id' => '950']]);
$s->returnFlow()->handle($s->orders()->find(950));
deliver($s, 'payment.succeeded', payment('trx_j1r', 950, 'succeeded', ['amount' => 6000]));
check(is_paid(950) && noted(950, 'paid in full by Wajub payments') === 1 && count($GLOBALS['orders']->paymentsOf(950)) === 2, 'J1: paid once, noted once: ' . notes_of(950));

// J4 Another request holding the order: the payer is told to try again, nothing sent.
$s = money_shop();
money_order(952, 'trx_j4');
$GLOBALS['locks']->busy['order:952'] = true;
check($GLOBALS['locks']->acquire('order:952', 0) === false, 'J4: the order\'s lock is another request\'s');
unset($GLOBALS['locks']->busy['order:952']);

// === L. Follow-ups ==========================================================================

// L4 The payment of what is left does not start: the merchant reads what is
// left to pay.
$s = money_shop();
money_order(965, 'trx_l4');
counted(965, 'trx_l4', 4000);
$GLOBALS['api']->next['POST /payments'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 965);
check(!$result['ok'] && $result['data']['code'] === 'start_failed' && noted(965, 'Wajub could not start the payment of the ' . fcfa(6000) . ' left to pay (' . fcfa(4000) . ' already received): cURL error 28: Operation timed out') === 1, 'L4: the rest could not be asked for: the merchant reads what is left: ' . notes_of(965));
$s = money_shop();
money_order(966, 'trx_l4b');
counted(966, 'trx_l4b', 4000);
$GLOBALS['api']->next['POST /payments'] = [[422, ['message' => 'The amount is too small.']]];
pay_rest($s, 966);
check(noted(966, 'Wajub could not start the payment of the ' . fcfa(6000) . ' left to pay (' . fcfa(4000) . ' already received): The amount is too small.') === 1, 'L4: Wajub\'s own words: ' . notes_of(966));
check(str_contains($s->starter()->startFailureNote(new RuntimeException('boom'), $s->orders()->find(966), 10000.0, 0.0), 'Wajub could not start the payment: boom'), 'L4: a first payment says so');

// L5 Wajub answers neither the close of a payment paid in instalments nor a
// read of it since: nothing new starts, and the merchant reads why.
$s = money_shop();
money_order(967, 'trx_l5');
$GLOBALS['api']->add('trx_l5', ['status' => 'partial', 'amount_paid' => 4000, 'expires_at' => gmdate('c', time() + 60), 'metadata' => ['order_id' => '967']]);
$GLOBALS['api']->next['GET /payments/trx_l5'] = [[200, ['transaction' => $GLOBALS['api']->payments['trx_l5']]], FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$GLOBALS['api']->next['POST /payments/trx_l5/close'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$result = pay_rest($s, 967);
check(!$result['ok'] && !in_array('POST /payments', $GLOBALS['api']->sent(), true), 'L5: nothing new starts: ' . sent());
check(noted(967, 'Wajub did not answer when this shop closed Wajub payment trx_l5, paid in instalments, which collected ' . fcfa(4000) . ' (cURL error 28: Operation timed out') === 1 && noted(967, 'nor when it read the payment again, so no new payment was started') === 1, 'L5: the merchant reads why: ' . notes_of(967));

// L8 The payer's return reads whose payment it is as every other read does.
$s = money_shop();
money_order(972, 'trx_l8');
$GLOBALS['api']->add('trx_l8', ['status' => 'succeeded', 'metadata' => ['source' => 'prestashop', 'source_id' => '', 'order_id' => '972']]);
$s->returnFlow()->handle($s->orders()->find(972));
check(is_paid(972), 'L8: a payment naming this order, with an empty source_id: it pays the order');
$s = money_shop();
money_order(973, 'trx_l8b');
$GLOBALS['api']->add('trx_l8b', ['status' => 'succeeded', 'metadata' => ['source' => 'prestashop', 'source_id' => '973', 'order_id' => '999']]);
$s->returnFlow()->handle($s->orders()->find(973));
check(!is_paid(973) && $GLOBALS['orders']->paymentsOf(973) === [], 'L8: one Wajub holds for another order: neither pays nor fails this one');
// Two requests read one payment at once: one note (the notes' key is the claim).
$s = money_shop();
money_order(975, 'trx_l8d');
$short = payment('trx_l8d', 975, 'succeeded', ['amount_paid' => 4000]);
apply_succeeded($s, 975, $short, Settlement::SEEN_NOW);
$record = record('trx_l8d');
$record->counted = 0.0;
$s->settlement()->apply($s->orders()->find(975), record('trx_l8d'), ['outcome' => Client::OUTCOME_SUCCEEDED, 'status' => 'succeeded', 'transaction' => $short], Settlement::SEEN_NOW);
check(noted(975, 'is left to pay') === 1, 'L8: and one note of what is left to pay: ' . notes_of(975));

// L16 A payment in another currency than the order's counts nothing, and a
// new payment is asked for; the merchant reads, once per payment, what it
// collected and to refund it, wherever it is read.
$s = money_shop();
money_order(979, 'trx_l16');
$GLOBALS['api']->add('trx_l16', ['status' => 'succeeded', 'currency' => 'EUR', 'amount' => 15, 'metadata' => ['order_id' => '979']]);
$said = 'Wajub payment trx_l16 collected ' . money_of(15, 'EUR') . ', in another currency than this order\'s (XAF): it does not count toward the order. Refund it from the Wajub Dashboard.';
$s->returnFlow()->handle($s->orders()->find(979));
check(!is_paid(979) && notes_of(979) === $said, 'L16: the payer\'s return: ' . notes_of(979));
$data = (new OrderPanel($s))->check(979);
check($data['message'] === 'Wajub confirmed this payment, but in another currency than the order\'s: it does not count toward the order, which is left unpaid. Refund it from the Wajub Dashboard.' && notes_of(979) === $said, 'L16: "Check status" says so, the order noted once: ' . $data['message']);
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l16']);
check(notes_of(979) === $said, 'L16: its webhook adds nothing: ' . notes_of(979));
$result = pay_rest($s, 979);
check($result['ok'] && (float) ($GLOBALS['api']->bodies('POST /payments')[0]['amount'] ?? 0) === 10000.0, 'L16: and the payer is asked for the order\'s total: ' . json_encode($result));

// L19 The second payment's note, once, whoever finds it first, for what it collected.
$s = money_shop();
money_order(990, 'trx_l19_twice');
counted(990, 'trx_l19_paid', 10000);
mark_paid(990);
deliver($s, 'payment.succeeded', payment('trx_l19_twice', 990, 'succeeded', ['amount_paid' => 4000]));
deliver($s, 'payment.succeeded', payment('trx_l19_twice', 990, 'succeeded', ['amount_paid' => 4000]));
check(noted(990, 'Wajub received a second payment for this order, already paid: trx_l19_twice (' . fcfa(4000) . '). If the customer did not mean to pay twice, refund it from the Wajub Dashboard.') === 1, 'L19: the webhook notes what the second payment collected, once: ' . notes_of(990));
// A paid order whose own payment goes through uncounted, though the others do
// not cover it alone (marked paid by hand): no second payment.
$s = money_shop();
money_order(994);
counted(994, 'trx_l19e_part', 4000);
money_payment(994, 'trx_l19e_own', ['amount' => 6000.0]);
mark_paid(994);
deliver($s, 'payment.succeeded', payment('trx_l19e_own', 994, 'succeeded', ['amount' => 6000]));
check(noted(994, 'second payment') === 0, 'L19: the order\'s own payment, the others short of the total: not a second payment: ' . notes_of(994));
// The order's own payment, once the others cover the order alone: a second payment.
$s = money_shop();
money_order(995);
counted(995, 'trx_l19f_old', 10000);
money_payment(995, 'trx_l19f_own');
mark_paid(995);
deliver($s, 'payment.succeeded', payment('trx_l19f_own', 995));
check(noted(995, 'Wajub received a second payment for this order, already paid: trx_l19f_own') === 1, 'L19: the order\'s own payment, the others covering it: noted: ' . notes_of(995));

// D10 A dispute on a payment that paid part of the order: noted on the order,
// once per dispute and event, whatever the order holds since.
$s = money_shop();
money_order(1120, 'trx_d10r');
counted(1120, 'trx_d10p', 4000);
$disputes = new DisputeEventHandler($s->payments(), static fn (int $idShop): Services => $s, $GLOBALS['logger']);
$dispute = ['id' => 'dsp_d10', 'transaction' => 'trx_d10p', 'amount' => 4000, 'currency' => 'XAF', 'reason' => 'fraud', 'sandbox' => false];
check($disputes->handles('dispute.created') && $disputes->handles('dispute.lost') && !$disputes->handles('refund.created'), 'D10: the disputes\' handler acts on dispute.*');
$disputes->handle(new Event('evt_d10a', 'dispute.created', $dispute, 'live'));
$disputes->handle(new Event('evt_d10b', 'dispute.created', $dispute, 'live'));
check(noted(1120, 'Wajub dispute event: dispute.created on payment trx_d10p (Live). Reason: fraud. Amount: ' . fcfa(4000) . '. Answer it from the Wajub Dashboard.') === 1, 'D10: a dispute on a payment that paid part of the order is noted on it, once: ' . notes_of(1120));
$disputes->handle(new Event('evt_d10c', 'dispute.lost', $dispute, 'live'));
check(noted(1120, 'Wajub dispute event: dispute.lost on payment trx_d10p') === 1, 'D10: its outcome is noted too');
$disputes->handle(new Event('evt_d10d', 'dispute.created', ['id' => 'dsp_d10x'] + $dispute, 'sandbox'));
$disputes->handle(new Event('evt_d10e', 'dispute.created', ['id' => 'dsp_d10y', 'transaction' => 'trx_elsewhere'] + $dispute, 'live'));
check(noted(1120, 'dsp_d10x') === 0 && noted(1120, 'dispute.created') === 1, 'D10: one signed in the other environment, or of a payment of no order of the shop, is not noted');

// === P. An order paid once: refunded, or cancelled by the merchant since ===========

// P1 Refunded in full in the Wajub Dashboard ("Refunded", not paid in
// PrestaShop), then the late webhook of an earlier attempt that went
// through: the order stays "Refunded", with no e-mail, the attempt not
// counted toward it, and the merchant told of a second payment.
$s = money_shop();
money_order(1100, 'trx_p1_first');
money_payment(1100, 'trx_p1_paid');
counted(1100, 'trx_p1_paid', 10000);
mark_paid(1100);
$s->refundSync()->webhook($s->orders()->find(1100), record('trx_p1_paid'), 'refund.succeeded', ['id' => 'rfd_p1', 'transaction' => 'trx_p1_paid', 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded', 'metadata' => []]);
check($GLOBALS['orders']->state(1100) === FakeShopOrders::REFUNDED, 'P1: refunded in full: "Refunded"');
$history = count($GLOBALS['orders']->history);
deliver($s, 'payment.succeeded', payment('trx_p1_first', 1100));
check($GLOBALS['orders']->state(1100) === FakeShopOrders::REFUNDED && count($GLOBALS['orders']->history) === $history, 'P1: an earlier attempt that went through late leaves it "Refunded", no e-mail sent');
check(count($GLOBALS['orders']->paymentsOf(1100)) === 1 && !record('trx_p1_first')->isCounted() && record('trx_p1_first')->state === PaymentRecord::FINAL, 'P1: the attempt is not counted toward the order');
check(noted(1100, 'Wajub received a second payment for this order, already paid: trx_p1_first (' . fcfa(10000) . ').') === 1, 'P1: the merchant is told of a second payment: ' . notes_of(1100));

// P2 Paid, then cancelled by the merchant, nothing refunded: "Check status" is
// not offered, and refused; nothing pays it again, its own payment's webhook
// delivered again neither.
$s = money_shop();
money_order(1101, 'trx_p2');
$GLOBALS['api']->add('trx_p2', ['metadata' => ['order_id' => '1101']]);
counted(1101, 'trx_p2', 10000);
mark_paid(1101);
$GLOBALS['orders']->setState($s->orders()->find(1101), FakeShopOrders::CANCELED, false);
$history = count($GLOBALS['orders']->history);
$data = (new OrderPanel($s))->check(1101);
check($data['message'] === 'This order no longer waits for its payment: there is nothing to check with Wajub.' && !$data['reload'] && sent() === '' && $GLOBALS['orders']->state(1101) === FakeShopOrders::CANCELED, 'P2: "Check status" of a paid order cancelled since: refused, nothing read, the order left cancelled: ' . $data['message']);
check((new OrderPanel($s))->view(1101)['checkable'] === false, 'P2: and not offered on its page');
check(!$s->settlement()->completeIfCovered($s->orders()->find(1101), 'live'), 'P2: its payments cover it, yet it is not paid again');
deliver($s, 'payment.succeeded', payment('trx_p2', 1101));
check($GLOBALS['orders']->state(1101) === FakeShopOrders::CANCELED && count($GLOBALS['orders']->history) === $history && noted(1101, 'second payment') === 0, 'P2: its own payment\'s webhook delivered again changes nothing: ' . notes_of(1101));

// P3 Refunded: "Check status" never says the customer can pay the rest, and
// the payer's pages offer nothing to pay.
$s = money_shop();
money_order(1102, 'trx_p3');
$GLOBALS['api']->add('trx_p3', ['status' => 'refunded', 'metadata' => ['order_id' => '1102']]);
counted(1102, 'trx_p3', 10000);
mark_paid(1102);
$s->refundSync()->webhook($s->orders()->find(1102), record('trx_p3'), 'refund.succeeded', ['id' => 'rfd_p3', 'transaction' => 'trx_p3', 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded', 'metadata' => []]);
$data = (new OrderPanel($s))->check(1102);
check($GLOBALS['orders']->state(1102) === FakeShopOrders::REFUNDED && !str_contains($data['message'], 'left to pay') && $data['message'] === 'This order no longer waits for its payment: there is nothing to check with Wajub.', 'P3: "Check status" of a refunded order: refused, no "left to pay": ' . $data['message']);
$status = $s->statusAnswer()->answer($s->orders()->find(1102));
check($status['status'] === 'pending' && $status['processing'] === false && empty($status['pay']) && !$s->statusAnswer()->payable($s->orders()->find(1102)), 'P3: the payer\'s status of a refunded order offers nothing to pay: ' . json_encode($status));

// P4 Which orders "Check status" is offered for: waiting for their payment,
// paid in part, or in "Payment error"; never paid, cancelled or refunded.
$s = money_shop();
$offered = [];
foreach ([FakeShopOrders::AWAITING, FakeShopOrders::PARTIAL, FakeShopOrders::ERROR, FakeShopOrders::PAID, FakeShopOrders::CANCELED, FakeShopOrders::REFUNDED] as $i => $state) {
    money_order(1110 + $i, 'trx_p4_' . $i);
    $GLOBALS['orders']->setState($s->orders()->find(1110 + $i), $state, false);
    $offered[$state] = (new OrderPanel($s))->view(1110 + $i)['checkable'];
}
check($offered === [FakeShopOrders::AWAITING => true, FakeShopOrders::PARTIAL => true, FakeShopOrders::ERROR => true, FakeShopOrders::PAID => false, FakeShopOrders::CANCELED => false, FakeShopOrders::REFUNDED => false], 'P4: "Check status" offered while the order waits for its payment only: ' . json_encode($offered));

// P5 The payer leaves the payment (the release form, posted from a stale
// tab) of an order paid once: refunded in full since, or cancelled by the
// merchant. Never cancelled by it, nor its cart given back to be ordered
// again: the payer is sent to the order's confirmation page.
$s = money_shop();
money_order(1130, 'trx_p5');
$GLOBALS['api']->add('trx_p5', ['status' => 'refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '1130']]);
counted(1130, 'trx_p5', 10000);
mark_paid(1130);
$s->refundSync()->webhook($s->orders()->find(1130), record('trx_p5'), 'refund.succeeded', ['id' => 'rfd_p5', 'transaction' => 'trx_p5', 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded', 'metadata' => []]);
money_order(1131, 'trx_p5c');
$GLOBALS['api']->add('trx_p5c', ['metadata' => ['order_id' => '1131']]);
counted(1131, 'trx_p5c', 10000);
mark_paid(1131);
$GLOBALS['orders']->setState($s->orders()->find(1131), FakeShopOrders::CANCELED, false);
$GLOBALS['storefront']->visitorIsCustomer = true;
foreach ([1130 => FakeShopOrders::REFUNDED, 1131 => FakeShopOrders::CANCELED] as $id => $state) {
    $history = count($GLOBALS['orders']->history);
    $left = $s->release()->leave($s->orders()->find($id), 'cancelled');
    check($left['to'] === Release::TO_CONFIRMATION && $GLOBALS['orders']->state($id) === $state && count($GLOBALS['orders']->history) === $history && $GLOBALS['storefront']->restored === [] && noted($id, 'was cancelled, and its cart given back') === 0, "P5: the payer leaving order {$id}, paid once: sent to its confirmation page, the order left as it is, no cart given back: " . json_encode($left));
}

done('remainder');

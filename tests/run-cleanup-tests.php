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
 * The scheduled task (Order\Cleanup): a port of the WordPress plugin's
 * keepUnpaidOrder() and recount() scenarios (run-remainder-tests.php F8,
 * L1, L1b–L1d, L17, L19), which WooCommerce's hold-stock cleanup and WP-Cron
 * run there.
 *
 * An order still "Awaiting Wajub payment" the configured delay after it was
 * placed is cancelled, its products back in stock, only when nothing of it
 * may hold money: never one holding money counted (live money whatever
 * environment the shop is in now), one paid in instalments, one Wajub is
 * taking, one that went through but could not count, one Wajub did not
 * answer for, one paid once. A payment nobody paid yet is cancelled with
 * Wajub first. The payments that could not count are read again, of every
 * order, and counted when they can be; still unread, the merchant is told.
 * A run asks Wajub of a bounded number of orders, and an order kept is left
 * alone for an hour.
 *
 *     php tests/run-cleanup-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';
require_once __DIR__ . '/fakes/money.php';

use Wajub\PrestaShop\Order\Cleanup;
use Wajub\PrestaShop\Order\Recounts;
use Wajub\PrestaShop\Payment\PaymentRecord;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\PaymentEventHandler;

/**
 * One run of the scheduled task, `$now` an hour and a half after the orders
 * of money_order() were placed (two hours ago) unless said.
 *
 * @return array{recounted: int, cancelled: list<int>, kept: list<int>}
 */
function run_cleanup(Services $s, ?int $now = null): array
{
    return $s->cleanup(static fn (int $idShop): Services => $s)->run($now);
}

/**
 * A payment's webhook, as Wajub signs it in Live.
 *
 * @param array<string, mixed> $data
 */
function deliver(Services $s, string $type, array $data): void
{
    (new PaymentEventHandler($s->payments(), static fn (int $idShop): Services => $s, $GLOBALS['logger']))->handle(new Event('evt_' . bin2hex(random_bytes(4)), $type, $data, 'live'));
}

function state_of(int $idOrder): int
{
    return $GLOBALS['orders']->state($idOrder);
}

function forget_kept(Services $s): void
{
    $s->state()->delete('cleanup_kept');
}

// --- The delay -------------------------------------------------------------------------------

$s = money_shop();
check($s->settings()->cancelAfter() === Settings::CANCEL_AFTER_DEFAULT && Settings::CANCEL_AFTER_DEFAULT === 60, 'an unpaid order is cancelled an hour after it was placed, until the merchant chooses');
money_order(100, 'trx_young');
$GLOBALS['orders']->orders[100]['placed'] = time() - 600;
$GLOBALS['api']->add('trx_young', ['status' => 'pending', 'metadata' => ['order_id' => '100']]);
$done = run_cleanup($s);
check($done['cancelled'] === [] && state_of(100) === FakeShopOrders::AWAITING && sent() === '', 'an order placed ten minutes ago is left alone, nothing asked');
$s->settings()->setCancelAfter(0);
$GLOBALS['orders']->orders[100]['placed'] = time() - 30 * 86400;
$done = run_cleanup($s);
check($done['cancelled'] === [] && sent() === '', 'a delay of 0: never cancelled, nothing asked');
$s->settings()->setCancelAfter(5);
forget_kept($s);
$GLOBALS['orders']->orders[100]['placed'] = time() - 600;
$done = run_cleanup($s);
check($done['cancelled'] === [100], 'a delay of 5 minutes, the order placed 10 minutes ago: cancelled');
check(noted(100, 'not completed within 5 minutes of the order being placed') === 1, 'the note says the delay: ' . notes_of(100));
// One minute: a wording of its own, translated whole, never "1 minutes".
$s->settings()->setCancelAfter(1);
money_order(101, 'trx_minute');
$GLOBALS['orders']->orders[101]['placed'] = time() - 120;
$GLOBALS['api']->add('trx_minute', ['status' => 'pending', 'metadata' => ['order_id' => '101']]);
$done = run_cleanup($s);
check($done['cancelled'] === [101] && noted(101, 'This unpaid order was cancelled: its Wajub payment was not completed within 1 minute of the order being placed, and Wajub holds no money for it. Its products are back in stock.') === 1 && noted(101, '1 minutes') === 0, 'a delay of 1 minute: the note says "1 minute": ' . notes_of(101));
$s->settings()->setCancelAfter(999999);
check($s->settings()->cancelAfter() === Settings::CANCEL_AFTER_MAX, 'the delay is 30 days at most');

// --- F8 What WooCommerce's hold-stock cleanup asks, here ---------------------------------------

// A payment nobody paid: cancelled with Wajub first, then the order.
$s = money_shop();
money_order(613, 'trx_f8_pending');
$GLOBALS['api']->add('trx_f8_pending', ['status' => 'pending', 'metadata' => ['order_id' => '613']]);
// Its confirmation e-mail, held as it was placed (Order\HeldConfirmations).
$s->heldConfirmations()->hold(['template' => 'order_conf', 'templateVars' => ['{id_order}' => 613, '{email}' => 'payer@example.cm'], 'to' => 'payer@example.cm']);
$done = run_cleanup($s);
check($done['cancelled'] === [613] && state_of(613) === FakeShopOrders::CANCELED && end($GLOBALS['orders']->history)['mail'] === false, 'F8: a payment with nothing taken: the order is cancelled, no e-mail');
check(sent() === 'GET /payments/trx_f8_pending, DELETE /payments/trx_f8_pending' && $GLOBALS['api']->payments['trx_f8_pending']['status'] === 'cancelled', 'F8: its payment cancelled with Wajub first, so it can never be approved after: ' . sent());
check(noted(613, 'This unpaid order was cancelled: its Wajub payment was not completed within 60 minutes of the order being placed, and Wajub holds no money for it. Its products are back in stock.') === 1, 'F8: the merchant reads why: ' . notes_of(613));
check(!$s->heldConfirmations()->holds(613), 'F8: its confirmation e-mail, held until it is paid, is forgotten: never sent, the customer\'s name and addresses kept no longer');
// The payment's webhook, which follows: the shop cancelled it, not the customer.
deliver($s, 'payment.cancelled', ['id' => 'trx_f8_pending', 'status' => 'cancelled', 'amount' => 10000, 'amount_paid' => 0, 'currency' => 'XAF', 'sandbox' => false, 'metadata' => ['order_id' => '613']]);
check(noted(613, 'Wajub payment trx_f8_pending was cancelled by the shop, before anyone paid it (Wajub status: cancelled).') === 1 && noted(613, 'The customer did not complete') === 0, 'F8: the webhook of the payment it cancelled: noted as cancelled by the shop, never as left by the customer: ' . notes_of(613));

// A cart PrestaShop split into several orders (packages): each order's
// confirmation is forgotten with the payment nobody paid, not the first's only.
$s = money_shop();
money_order(614, 'trx_f8_split', 6000.0);
money_order(615, '', 4000.0);
$GLOBALS['orders']->split(614, 615);
$GLOBALS['api']->add('trx_f8_split', ['status' => 'pending', 'metadata' => ['order_id' => '614']]);
foreach ([614, 615] as $id) {
    $s->heldConfirmations()->hold(['template' => 'order_conf', 'templateVars' => ['{id_order}' => $id], 'to' => 'payer@example.cm']);
}
$done = run_cleanup($s);
check(in_array(614, $done['cancelled'], true) && !$s->heldConfirmations()->holds(614) && !$s->heldConfirmations()->holds(615), 'F8: a cart split into two orders: both confirmation e-mails are forgotten with its payment: ' . json_encode($done));

// Holding money counted: kept, with no API call.
$s = money_shop();
money_order(611, 'trx_f8a');
counted(611, 'trx_f8a', 4000);
$s->heldConfirmations()->hold(['template' => 'order_conf', 'templateVars' => ['{id_order}' => 611], 'to' => 'payer@example.cm']);
$done = run_cleanup($s);
check($done['kept'] === [611] && state_of(611) === FakeShopOrders::AWAITING && sent() === '', 'F8: an order holding money counted is kept, with no API call');
check($s->heldConfirmations()->holds(611), 'F8: kept, its confirmation e-mail is kept too: it goes once the order is paid');

// Holding money counted, the rest's payment open on Wajub's page: kept with
// no call, its payment never cancelled under the payer.
$s = money_shop();
money_order(616);
counted(616, 'trx_f8c_part', 4000);
money_payment(616, 'trx_f8c_rest', ['amount' => 6000.0]);
$GLOBALS['api']->add('trx_f8c_rest', ['status' => 'pending', 'amount' => 6000, 'metadata' => ['order_id' => '616']]);
$done = run_cleanup($s);
check($done['kept'] === [616] && sent() === '' && $GLOBALS['api']->payments['trx_f8c_rest']['status'] === 'pending', 'F8: an order holding money counted, its rest being paid: kept, with no API call, the rest\'s payment left open: ' . sent());

// Paid in instalments: kept, once read, the merchant told why, once.
$s = money_shop();
money_order(612, 'trx_f8b');
$GLOBALS['api']->add('trx_f8b', ['status' => 'partial', 'amount_paid' => 4000, 'metadata' => ['order_id' => '612']]);
$done = run_cleanup($s);
check($done['kept'] === [612] && sent() === 'GET /payments/trx_f8b' && $s->ledger()->instalmentsOpen($s->orders()->find(612)) !== null, 'F8: one paid in instalments is kept, once read: ' . sent());
check(noted(612, 'This unpaid order was not cancelled: Wajub payment trx_f8b, paid in instalments, has collected ' . fcfa(4000) . ' so far.') === 1, 'F8: the merchant reads why: ' . notes_of(612));
$GLOBALS['api']->calls();
run_cleanup($s);
check(sent() === '', 'F8: kept, it is left alone for an hour');
forget_kept($s);
run_cleanup($s);
check(noted(612, 'was not cancelled') === 1, 'F8: an hour later, kept again: noted once');
// The plan finished since, its webhook lost: the next run reads it and pays the order.
$GLOBALS['api']->payments['trx_f8b']['status'] = 'succeeded';
$GLOBALS['api']->payments['trx_f8b']['amount_paid'] = 10000;
forget_kept($s);
$done = run_cleanup($s);
check($GLOBALS['orders']->find(612)->paid && $done['cancelled'] === [], 'F8: a plan finished since, its webhook lost, is read and pays the order');

foreach (['being approved' => ['processing', 0, false], 'refunded in full' => ['refunded', 10000, true]] as $case => [$status, $paid, $cancels]) {
    $s = money_shop();
    money_order(614, 'trx_f8_' . $status);
    $GLOBALS['api']->add('trx_f8_' . $status, ['status' => $status, 'amount_paid' => $paid, 'metadata' => ['order_id' => '614']]);
    if ($status === 'refunded') {
        $GLOBALS['api']->refunds['trx_f8_' . $status] = [['id' => 'rfd_f8', 'transaction' => 'trx_f8_' . $status, 'amount' => 10000, 'currency' => 'XAF', 'status' => 'succeeded']];
    }
    $done = run_cleanup($s);
    check(($done['cancelled'] === [614]) === $cancels, "F8: a payment {$case}: " . ($cancels ? 'cancelled' : 'kept') . ' ' . json_encode($done));
    if ($status === 'processing') {
        $why = 'This unpaid order was not cancelled: Wajub payment trx_f8_processing may still go through (Wajub status: processing). The order is looked at again once Wajub tells how the payment ended.';
        check(noted(614, $why) === 1, 'F8: kept for a payment being approved: the merchant reads why: ' . notes_of(614));
        forget_kept($s);
        run_cleanup($s);
        check(noted(614, $why) === 1, 'F8: kept again by the next run: said once');
    }
}
foreach (['refunded' => true, 'partially_refunded' => false] as $status => $cancels) {
    $s = money_shop();
    money_order(615, 'trx_f8_refused_' . $status);
    $GLOBALS['api']->add('trx_f8_refused_' . $status, ['status' => $status, 'amount_paid' => 10000, 'metadata' => ['order_id' => '615']]);
    $GLOBALS['api']->next['GET /payments/trx_f8_refused_' . $status . '/refunds'] = [[403, ['message' => 'Forbidden']]];
    $done = run_cleanup($s);
    check(($done['cancelled'] === [615]) === $cancels, "F8: a payment {$status}, its refunds refused to this key: " . ($cancels ? 'cancelled' : 'kept'));
}
// Wajub not answering: kept until a later run.
$s = money_shop();
money_order(640, 'trx_f8_down');
$GLOBALS['api']->next['GET /payments/trx_f8_down'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$done = run_cleanup($s);
check($done['kept'] === [640] && state_of(640) === FakeShopOrders::AWAITING, 'F8: Wajub not answering: kept until a later run');
// A payment Wajub has not (404): it can charge nothing, cancelled.
$s = money_shop();
money_order(641, 'trx_f8_gone');
$done = run_cleanup($s);
check($done['cancelled'] === [641], 'F8: a payment Wajub has not (404): cancelled');
// Another module's orders, and orders in another state, are never looked at.
$s = money_shop();
money_order(642, 'trx_f8_other');
$GLOBALS['orders']->orders[642]['module'] = 'ps_wirepayment';
money_order(643, 'trx_f8_error');
$GLOBALS['orders']->setState($s->orders()->find(643), FakeShopOrders::ERROR, false);
$done = run_cleanup($s);
check($done === ['recounted' => 0, 'cancelled' => [], 'kept' => []] && sent() === '', 'F8: nothing asked for another module\'s order, or one no longer awaiting its payment');
// A plan in another currency than the order's: kept, its amount in its own.
$s = money_shop();
money_order(645, 'trx_f8e');
$GLOBALS['api']->add('trx_f8e', ['status' => 'partial', 'currency' => 'EUR', 'amount' => 15, 'amount_paid' => 6, 'metadata' => ['order_id' => '645']]);
$done = run_cleanup($s);
check($done['kept'] === [645] && noted(645, 'paid in instalments, has collected ' . money_of(6, 'EUR') . ' so far') === 1, 'F8: a plan in another currency than the order\'s: kept, its amount in its own: ' . notes_of(645));
// Live money, the shop in Sandbox since: still held. Kept, with no API call.
$s = money_shop('sandbox');
money_order(644, 'trx_f8_live');
counted(644, 'trx_f8_live', 4000, 'live');
$done = run_cleanup($s);
check($done['kept'] === [644] && sent() === '', 'F8: 4 000 of live money, the shop in Sandbox: kept, with no API call');

// --- L1 Payments that could not count ---------------------------------------------------------

// L1 An earlier attempt paid in full, its refunds not read as its webhook
// came: it counts nothing yet, and the order, whose newer payment the payer
// left, is never cancelled meanwhile.
$s = money_shop();
money_order(960, 'trx_l1_old');
money_payment(960, 'trx_l1_new');
$GLOBALS['api']->add('trx_l1_old', ['status' => 'succeeded', 'metadata' => ['order_id' => '960']]);
$GLOBALS['api']->add('trx_l1_new', ['status' => 'expired', 'metadata' => ['order_id' => '960']]);
$GLOBALS['api']->next['GET /payments/trx_l1_old/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l1_old']);
check(!$GLOBALS['orders']->find(960)->paid && $GLOBALS['payments']->find('trx_l1_old')->state === PaymentRecord::UNCOUNTED && notes_of(960) === '', 'L1: its refunds unread, it does not count yet, nothing noted yet');
$done = run_cleanup($s);
check(!$GLOBALS['orders']->find(960)->paid && state_of(960) === FakeShopOrders::AWAITING && in_array('GET /payments/trx_l1_old', $GLOBALS['api']->sent(), true), 'L1: the run reads it again: still unread, the order is not cancelled, though its own payment expired: ' . sent());
check(noted(960, 'Wajub payment trx_l1_old went through, but its refunds could not be read, so it does not count toward the order yet. The order stays as it is, and is not cancelled as unpaid, until the payment counts.') === 1, 'L1: the merchant is told, once: ' . notes_of(960));
forget_kept($s);
run_cleanup($s);
check(noted(960, 'its refunds could not be read') === 1 && state_of(960) === FakeShopOrders::AWAITING, 'L1: kept, the merchant told once: ' . notes_of(960));
forget_kept($s);
$done = run_cleanup($s);
check($GLOBALS['orders']->find(960)->paid && $s->ledger()->uncounted($s->orders()->find(960)) === [] && $done['recounted'] === 1, 'L1: its refunds read at last, it counts and pays the order: ' . json_encode($done));
// The order's own payment, refunded in part, its refunds refused to this key:
// kept, the merchant told why and what to do.
$s = money_shop();
money_order(961, 'trx_l1_own');
$GLOBALS['api']->add('trx_l1_own', ['status' => 'partially_refunded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '961']]);
$GLOBALS['api']->next['GET /payments/trx_l1_own/refunds'] = [[403, ['message' => 'Forbidden']]];
$done = run_cleanup($s);
check($done['kept'] === [961] && noted(961, '(refund.read)') === 1 && noted(961, 'is not cancelled as unpaid') === 1, 'L1: its own payment, its refunds refused to the key: kept, and the merchant reads why: ' . notes_of(961));

// L1b An earlier attempt that could not count, short of the total: read again
// by the run, it counts at last, and the order, which now holds those 4 000,
// is kept, as one that counted them earlier is.
$s = money_shop();
money_order(980, 'trx_l1b_old');
money_payment(980, 'trx_l1b_new');
$GLOBALS['api']->add('trx_l1b_old', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '980']]);
$GLOBALS['api']->add('trx_l1b_new', ['status' => 'expired', 'metadata' => ['order_id' => '980']]);
$GLOBALS['api']->next['GET /payments/trx_l1b_old/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l1b_old']);
$done = run_cleanup($s);
check($done['cancelled'] === [] && $s->ledger()->held($s->orders()->find(980), 'live') === 4000.0 && $s->ledger()->uncounted($s->orders()->find(980)) === [] && !$GLOBALS['orders']->find(980)->paid, 'L1b: counted as the run reads it again, 4 000 held: the order is not cancelled: ' . json_encode($done));
forget_kept($s);
check(run_cleanup($s)['cancelled'] === [], 'L1b: nor at the next run, the ledger holding it');

// L1c An earlier attempt that could not count, the 6 000 left of an order that
// holds 4 000 already: read again all the same, and it pays the order.
$s = money_shop();
money_order(981, 'trx_l1c_old', 10000.0, ['amount' => 6000.0]);
counted(981, 'trx_l1c_part', 4000);
$GLOBALS['api']->add('trx_l1c_old', ['status' => 'succeeded', 'amount' => 6000, 'metadata' => ['order_id' => '981']]);
$GLOBALS['api']->next['GET /payments/trx_l1c_old/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l1c_old']);
check($s->ledger()->uncounted($s->orders()->find(981)) !== [] && !$GLOBALS['orders']->find(981)->paid, 'L1c: its refunds unread, it does not count yet');
run_cleanup($s);
check($GLOBALS['orders']->find(981)->paid && $s->ledger()->uncounted($s->orders()->find(981)) === [], 'L1c: read again, though the order holds money: it counts, and pays the order: ' . notes_of(981));

// L1d An order paid once, which the merchant set back to awaiting since: never
// settled again here.
$s = money_shop();
money_order(982, 'trx_l1d');
mark_paid(982);
$GLOBALS['orders']->setState($s->orders()->find(982), FakeShopOrders::AWAITING, false);
$GLOBALS['api']->add('trx_l1d', ['status' => 'pending', 'metadata' => ['order_id' => '982']]);
$done = run_cleanup($s);
check($done['kept'] === [982] && state_of(982) === FakeShopOrders::AWAITING && sent() === '', 'L1d: an order paid once is kept, and nothing is read');

// --- L17, L19 Second payments found by a read again ----------------------------------------------

// L17 Read again by the run, the payer having paid the newer attempt meanwhile:
// the merchant is told, once, and it counts nothing toward the order.
$s = money_shop();
money_order(983, 'trx_l17_old');
money_payment(983, 'trx_l17_new');
$GLOBALS['api']->add('trx_l17_old', ['status' => 'succeeded', 'metadata' => ['order_id' => '983']]);
$GLOBALS['api']->next['GET /payments/trx_l17_old/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l17_old']);
deliver($s, 'payment.succeeded', ['id' => 'trx_l17_new', 'status' => 'succeeded', 'amount' => 10000, 'amount_paid' => 10000, 'currency' => 'XAF', 'sandbox' => false, 'metadata' => ['order_id' => '983']]);
check($GLOBALS['orders']->find(983)->paid && $GLOBALS['payments']->find('trx_l17_old')->state === PaymentRecord::UNCOUNTED && noted(983, 'second payment') === 0, 'L17: the newer attempt paid the order, the earlier one not counted yet');
$done = run_cleanup($s);
check(noted(983, 'Wajub received a second payment for this order, already paid: trx_l17_old (' . fcfa(10000) . '). If the customer did not mean to pay twice, refund it from the Wajub Dashboard.') === 1 && $GLOBALS['payments']->find('trx_l17_old')->state === PaymentRecord::FINAL && !$GLOBALS['payments']->find('trx_l17_old')->isCounted(), 'L17: read again by the run, it went through: the merchant is told, it counts nothing: ' . notes_of(983));
run_cleanup($s);
deliver($s, 'payment.succeeded', $GLOBALS['api']->payments['trx_l17_old']);
check(noted(983, 'second payment') === 1, 'L17: read again, or its webhook delivered again: noted once');
// Refunded in full since: it gave everything back, nothing to say.
$s = money_shop();
money_order(984, 'trx_l17b_old');
money_payment(984, 'trx_l17b_new');
counted(984, 'trx_l17b_new', 10000);
mark_paid(984);
$record = $GLOBALS['payments']->find('trx_l17b_old');
$record->state = PaymentRecord::UNCOUNTED;
$GLOBALS['payments']->save($record);
$GLOBALS['api']->add('trx_l17b_old', ['status' => 'refunded', 'metadata' => ['order_id' => '984']]);
run_cleanup($s);
check(noted(984, 'second payment') === 0 && sent() === 'GET /payments/trx_l17b_old', 'L17: one refunded in full since: read, nothing noted: ' . notes_of(984) . ' ' . sent());
// Two earlier attempts that could not count: the first pays the order, the
// second, read all the same, is noted with what it collected.
$s = money_shop();
money_order(985, 'trx_l17c_one');
money_payment(985, 'trx_l17c_two');
money_payment(985, 'trx_l17c_new');
foreach (['trx_l17c_one', 'trx_l17c_two'] as $paymentId) {
    $record = $GLOBALS['payments']->find($paymentId);
    $record->state = PaymentRecord::UNCOUNTED;
    $GLOBALS['payments']->save($record);
}
$GLOBALS['api']->add('trx_l17c_new', ['status' => 'expired', 'metadata' => ['order_id' => '985']]);
$GLOBALS['api']->add('trx_l17c_one', ['status' => 'succeeded', 'metadata' => ['order_id' => '985']]);
$GLOBALS['api']->add('trx_l17c_two', ['status' => 'succeeded', 'amount_paid' => 9000, 'metadata' => ['order_id' => '985']]);
run_cleanup($s);
check($GLOBALS['orders']->find(985)->paid && $GLOBALS['payments']->find('trx_l17c_one')->isCounted() && in_array('GET /payments/trx_l17c_two', $GLOBALS['api']->sent(), true), 'L17: the first pays the order, the second is read all the same: ' . sent());
check(noted(985, 'already paid: trx_l17c_two (' . fcfa(9000) . ')') === 1 && noted(985, 'second payment') === 1, 'L17: the second, what it collected: ' . notes_of(985));
// The order's own payment, its webhook lost, read by the run: it pays the
// order, and the earlier attempt that went through is noted.
$s = money_shop();
money_order(986, 'trx_l17d_old');
money_payment(986, 'trx_l17d_new');
$record = $GLOBALS['payments']->find('trx_l17d_old');
$record->state = PaymentRecord::UNCOUNTED;
$GLOBALS['payments']->save($record);
$GLOBALS['api']->add('trx_l17d_new', ['status' => 'succeeded', 'metadata' => ['order_id' => '986']]);
$GLOBALS['api']->add('trx_l17d_old', ['status' => 'succeeded', 'metadata' => ['order_id' => '986']]);
run_cleanup($s);
check($GLOBALS['orders']->find(986)->paid && $GLOBALS['payments']->find('trx_l17d_new')->isCounted() && noted(986, 'already paid: trx_l17d_old') === 1, 'L17: the order\'s own payment pays it as the run reads it: the earlier one is noted: ' . notes_of(986));
// L19 The order's own payment, read by the run as gone through, its refunds
// unread, then an earlier attempt read again pays the order: the own one
// charged the payer twice.
$s = money_shop();
money_order(993, 'trx_l19d_old');
money_payment(993, 'trx_l19d_new');
$record = $GLOBALS['payments']->find('trx_l19d_old');
$record->state = PaymentRecord::UNCOUNTED;
$GLOBALS['payments']->save($record);
$GLOBALS['api']->add('trx_l19d_new', ['status' => 'succeeded', 'metadata' => ['order_id' => '993']]);
$GLOBALS['api']->add('trx_l19d_old', ['status' => 'succeeded', 'metadata' => ['order_id' => '993']]);
$GLOBALS['api']->next['GET /payments/trx_l19d_new/refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
run_cleanup($s);
check($GLOBALS['orders']->find(993)->paid && $GLOBALS['payments']->find('trx_l19d_old')->isCounted() && noted(993, 'already paid: trx_l19d_new') === 1 && noted(993, 'second payment') === 1, 'L19: the order\'s own payment, its refunds unread, then the earlier one paying the order: noted: ' . notes_of(993) . ' ' . sent());

// A payment started again from the order's pages ("Pay for this order") has
// the whole delay: it counts from the latest payment, not the order.
$s = money_shop();
money_order(620, 'trx_f8d_first', 10000.0, ['state' => PaymentRecord::FINAL, 'status' => 'cancelled']);
$GLOBALS['orders']->orders[620]['placed'] = time() - 59 * 60;
$GLOBALS['api']->add('trx_f8d_first', ['status' => 'cancelled', 'metadata' => ['order_id' => '620']]);
check($s->statusAnswer()->payable($s->orders()->find(620)) && $s->starter()->rest($s->orders()->find(620), cart_view(720, 10000.0), 'en')['ok'], 'F8: the payer pays the order again from its page, 59 minutes after it was placed');
$started = $s->payments()->current(620)->paymentId;
$GLOBALS['api']->calls();
$done = run_cleanup($s, time() + 120);
check($done['cancelled'] === [] && state_of(620) === FakeShopOrders::AWAITING && sent() === '' && $GLOBALS['api']->payments[$started]['status'] === 'pending', 'F8: two minutes later, the run leaves the order and its new payment alone: ' . sent());
$done = run_cleanup($s, time() + 3660);
check($done['cancelled'] === [620] && $GLOBALS['api']->payments[$started]['status'] === 'cancelled', 'F8: its new payment left an hour: cancelled with Wajub, then the order');

// A payment that went through in another currency counts nothing, yet the
// payer was charged: kept until it is refunded in full.
$s = money_shop();
money_order(621, 'trx_f8e');
$GLOBALS['api']->add('trx_f8e', ['currency' => 'EUR', 'amount' => 15, 'amount_paid' => 15, 'metadata' => ['order_id' => '621']]);
$done = run_cleanup($s);
check($done['kept'] === [621] && state_of(621) === FakeShopOrders::AWAITING && noted(621, 'This unpaid order was cancelled') === 0, 'F8: a payment that went through in another currency: the order is kept: ' . json_encode($done) . ' ' . notes_of(621));
check(noted(621, 'in another currency than this order\'s (XAF): it does not count toward the order. Refund it from the Wajub Dashboard.') === 1, 'F8: the merchant reads why, and to refund it: ' . notes_of(621));
check(sent() === 'GET /payments/trx_f8e', 'F8: read once: ' . sent());
forget_kept($s);
$s->state()->delete('outcome:' . md5('trx_f8e'));
$GLOBALS['api']->calls();
$done = run_cleanup($s);
check($done['kept'] === [621] && sent() === 'GET /payments/trx_f8e', 'F8: looked at again: read again, and kept: ' . sent());
$GLOBALS['api']->payments['trx_f8e']['status'] = 'refunded';
forget_kept($s);
$s->state()->delete('outcome:' . md5('trx_f8e'));
$done = run_cleanup($s);
check($done['cancelled'] === [621], 'F8: refunded in full since: the order goes: ' . json_encode($done));

// --- A run --------------------------------------------------------------------------------------

// Bounded: at most this many orders per run, the longest waiting first; the next run goes on.
$s = money_shop();
for ($id = 1; $id <= Cleanup::ORDERS_PER_RUN + 3; ++$id) {
    money_order(1000 + $id, 'trx_run_' . $id);
    $GLOBALS['orders']->orders[1000 + $id]['placed'] = time() - 7200 - $id * 60;
    $GLOBALS['api']->add('trx_run_' . $id, ['status' => 'pending', 'metadata' => ['order_id' => (string) (1000 + $id)]]);
}
$done = run_cleanup($s);
check(count($done['cancelled']) === Cleanup::ORDERS_PER_RUN && $done['cancelled'][0] === 1000 + Cleanup::ORDERS_PER_RUN + 3, 'a run looks at ' . Cleanup::ORDERS_PER_RUN . ' orders at most, the longest waiting first: ' . json_encode($done['cancelled']));
check(count(run_cleanup($s)['cancelled']) === 3, 'the next run goes on with the others');
// One run at a time; an order another request holds is left for later.
$s = money_shop();
money_order(1100, 'trx_busy');
$GLOBALS['api']->add('trx_busy', ['status' => 'pending', 'metadata' => ['order_id' => '1100']]);
$GLOBALS['store']->add('cleanup_run', time(), 900);
check(run_cleanup($s) === ['recounted' => 0, 'cancelled' => [], 'kept' => []] && sent() === '', 'another run under way: nothing done');
$GLOBALS['store']->delete('cleanup_run');
$GLOBALS['locks']->busy['order:1100'] = true;
check(run_cleanup($s)['cancelled'] === [] && state_of(1100) === FakeShopOrders::AWAITING, 'an order another request holds is left as it is');
unset($GLOBALS['locks']->busy['order:1100']);
check(run_cleanup($s)['cancelled'] === [1100], 'and looked at by the next run');
check($GLOBALS['store']->get('cleanup_run') === null, 'each run lets go of its lease');
// A run that outlasts its lease (Wajub slow on every order): the next run
// took the lease meanwhile, and keeps it once the first one ends.
$s = money_shop();
money_order(1101, 'trx_slow');
$GLOBALS['api']->add('trx_slow', ['status' => 'pending', 'metadata' => ['order_id' => '1101']]);
$GLOBALS['api']->duringRequest = static function (string $route): void {
    $GLOBALS['store']->delete('cleanup_run');
    $GLOBALS['store']->add('cleanup_run', 'the-next-run', 900);
};
run_cleanup($s);
$GLOBALS['api']->duringRequest = null;
check($GLOBALS['store']->get('cleanup_run') === 'the-next-run', 'a run that outlasted its lease leaves the next run its own: ' . json_encode($GLOBALS['store']->get('cleanup_run')));
$GLOBALS['store']->delete('cleanup_run');
// MySQL 5.6 lets a request hold one named lock: the run is kept apart by a
// lease in the module's state, never by a lock of its own, and takes each
// order's lock in turn.
$s = money_shop();
money_order(1101, 'trx_one_a');
$GLOBALS['api']->add('trx_one_a', ['status' => 'pending', 'metadata' => ['order_id' => '1101']]);
money_order(1102, 'trx_one_b', 10000.0, ['state' => PaymentRecord::UNCOUNTED, 'status' => 'succeeded']);
$GLOBALS['api']->add('trx_one_b', ['status' => 'succeeded', 'metadata' => ['order_id' => '1102']]);
$done = run_cleanup($s);
check($GLOBALS['locks']->mostHeld === 1 && !in_array('cleanup', $GLOBALS['locks']->taken, true) && in_array('order:1101', $GLOBALS['locks']->taken, true) && in_array('order:1102', $GLOBALS['locks']->taken, true), 'MySQL 5.6: one database lock at a time, each order\'s, none for the run: ' . json_encode($GLOBALS['locks']->taken));
// The scheduled task reads a payment that could not count as the pages would
// (Order\Recounts): still unread, its next read moves on, and the pages stop
// asking until then.
$s = money_shop();
money_order(1104, 'trx_cron_recount');
$GLOBALS['api']->add('trx_cron_recount', ['status' => 'succeeded', 'amount_paid' => 4000, 'metadata' => ['order_id' => '1104']]);
$GLOBALS['api']->next['GET /payments/trx_cron_recount/refunds'] = array_fill(0, 6, [503, []]);
deliver($s, 'payment.succeeded', payment_data('trx_cron_recount', 'succeeded', 10000.0, ['amount_paid' => 4000, 'metadata' => ['order_id' => '1104']]));
$scheduled = (int) $GLOBALS['payments']->find('trx_cron_recount')->meta(Recounts::DUE);
check($scheduled > 0 && $GLOBALS['recountHint']->at === $scheduled, 'recount: a payment that could not count is due again in a few minutes');
$now = time() + 60;
run_cleanup($s, $now);
$record = $GLOBALS['payments']->find('trx_cron_recount');
check((int) $record->meta(Recounts::DUE) === $scheduled && $record->meta(Recounts::TRIES) === '' && $GLOBALS['recountHint']->at === $scheduled, 'recount: read by the scheduled task before it is due, still unread: the pages still read it when it is due, none of their reads spent (the plugin\'s five minutes): ' . $record->meta(Recounts::DUE) . '/' . $record->meta(Recounts::TRIES));
forget_kept($s);
$now = $scheduled + 30;
run_cleanup($s, $now);
check((int) $GLOBALS['payments']->find('trx_cron_recount')->meta(Recounts::DUE) === $now + Recounts::RETRY_AFTER[0] && $GLOBALS['recountHint']->at === $now + Recounts::RETRY_AFTER[0], 'recount: read by the scheduled task once due, still unread: due again later, the pages asking then');
$GLOBALS['api']->next['GET /payments/trx_cron_recount/refunds'] = [];
forget_kept($s);
run_cleanup($s, $now + 10);
check($GLOBALS['payments']->find('trx_cron_recount')->isCounted() && $GLOBALS['recountHint']->at === 0, 'recount: counted by the scheduled task: nothing left for the pages to ask');
// The cron's token: one for the whole PrestaShop, which only the shop can make.
check(strlen($s->tokens()->cron()) === 40 && $s->tokens()->cron() !== (new Wajub\PrestaShop\Checkout\Tokens('another-secret'))->cron(), 'the cron address carries a token made with the shop\'s own secret');

// --- The merchant cancels an order --------------------------------------------------------------

// Its payments still open are cancelled with Wajub, so that nobody pays a
// cancelled order; one being paid is left to its webhook; the order is
// never moved: the merchant's state stands.
$s = money_shop();
money_order(1200, 'trx_mc_pending');
money_payment(1200, 'trx_mc_through', ['amount' => 10000.0]);
money_payment(1200, 'trx_mc_down', ['amount' => 10000.0]);
$GLOBALS['api']->add('trx_mc_pending', ['status' => 'pending', 'metadata' => ['order_id' => '1200']]);
$GLOBALS['api']->add('trx_mc_through', ['status' => 'succeeded', 'amount_paid' => 10000, 'metadata' => ['order_id' => '1200']]);
$GLOBALS['api']->add('trx_mc_down', ['status' => 'pending', 'metadata' => ['order_id' => '1200']]);
$GLOBALS['api']->next['DELETE /payments/trx_mc_down'] = [[503, ['message' => 'Unavailable']], [503, ['message' => 'Unavailable']], [503, ['message' => 'Unavailable']]];
$before = state_of(1200);
$done = $s->openPayments()->cancelWithOrder($s->orders()->find(1200));
$cancelled = $GLOBALS['payments']->find('trx_mc_pending');
check($done['cancelled'] === ['trx_mc_pending'] && $GLOBALS['api']->payments['trx_mc_pending']['status'] === 'cancelled' && $cancelled->state === PaymentRecord::FINAL && $cancelled->meta(PaymentRecord::CANCELLED_BY_SHOP) !== '', 'a payment nobody paid: cancelled with Wajub, its webhook to say the shop cancelled it: ' . json_encode($done));
check($done['refused'] === ['trx_mc_through'] && $GLOBALS['payments']->find('trx_mc_through')->state === PaymentRecord::ACTIVE, 'one that went through, its webhook lost: Wajub refuses, it is left to its webhook');
check($done['unanswered'] === ['trx_mc_down'] && $GLOBALS['payments']->find('trx_mc_down')->state === PaymentRecord::ACTIVE, 'one Wajub does not answer for: left, the merchant told to cancel it in the Dashboard');
check(state_of(1200) === $before && !in_array('GET /payments/trx_mc_through', $GLOBALS['api']->sent(), true), 'the order never moved, nothing read or settled: ' . sent());

done('cleanup');

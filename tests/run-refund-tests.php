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
 * Refunds, both ways (Refund\RefundSync, Webhook\RefundEventHandler): a port
 * of the refund scenarios of the WordPress plugin's tests
 * (run-remainder-tests.php E, D5–D13, J2, L2, L3, L7; run-refund-sync-tests.php
 * and run-webhook-tests.php for what a refund made in the Wajub Dashboard
 * does to its order).
 *
 * From the back office: a credit slip refunded through Wajub before
 * PrestaShop makes it, split across the order's payments, the newest first,
 * each with a key of its own Wajub keeps (the call sent again refunds
 * nothing twice); refused (more than they hold, the other environment, an
 * order never paid in full, Wajub's refusal) with nothing refunded and no
 * slip; refused after a part, what went through recorded on a slip of its
 * own; not answered, asked again for the same amount under the same key
 * (its webhook may have said meanwhile that Wajub made it), or recorded on
 * the order once its webhook says it went through; PrestaShop making no slip
 * of what Wajub refunded, recorded on a slip of its own at the end of the
 * request, or making it out of the module's sight, which then records it.
 * From the Wajub Dashboard: noted on its payment, recorded on the order
 * as a credit slip when it gives back money that paid it, never twice, the
 * order "Refunded" once all its money is back; one of a payment that did not
 * pay the order, or of the other environment, only noted.
 *
 *     php tests/run-refund-tests.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fakes/payments.php';
require_once __DIR__ . '/fakes/money.php';

use Wajub\PrestaShop\Payment\RefundRecord;
use Wajub\PrestaShop\Refund\PendingSlips;
use Wajub\PrestaShop\Refund\RefundSync;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Webhook\Event;
use Wajub\PrestaShop\Webhook\RefundEventHandler;

/**
 * A paid order counting `$parts` (payment id => what it collected), the
 * first made first, in Live unless said.
 *
 * @param array<string, float> $parts
 */
function paid_order(int $id, array $parts, float $total = 10000.0, string $environment = 'live'): void
{
    money_order($id, '', $total);
    foreach ($parts as $paymentId => $amount) {
        counted($id, $paymentId, $amount, $environment);
    }
    mark_paid($id);
}

/**
 * A refund the merchant makes from the order page, before PrestaShop makes
 * its slip, as the hook asks it: refunded through Wajub, or refused.
 *
 * @return array{status: string, message: string, keys: list<string>, refunded: float}
 */
function refund(Services $s, int $idOrder, float $amount): array
{
    return $s->refundSync()->refundForSlip($s->orders()->find($idOrder), $amount);
}

/**
 * A refund's webhook, as Wajub signs it in `$environment`.
 *
 * @param array<string, mixed> $data
 */
function deliver_refund(Services $s, string $type, array $data, string $environment = 'live'): void
{
    $handler = new RefundEventHandler($s->payments(), static fn (int $idShop): Services => $s, $GLOBALS['logger']);
    check($handler->handles($type), "the refunds' handler acts on {$type}");
    $handler->handle(new Event('evt_' . bin2hex(random_bytes(4)), $type, $data, $environment));
}

/**
 * A refund as its webhooks carry it: live, succeeded.
 *
 * @param array<string, mixed> $fields
 *
 * @return array<string, mixed>
 */
function refund_data(string $id, string $paymentId, float $amount, string $status = 'succeeded', array $fields = []): array
{
    return $fields + ['id' => $id, 'transaction' => $paymentId, 'amount' => $amount, 'currency' => 'XAF', 'status' => $status, 'sandbox' => str_starts_with($paymentId, 'trx_test_'), 'metadata' => []];
}

/** @return list<float> the amounts of the credit slips the module made for the order */
function slips_of(int $idOrder): array
{
    return array_map(static fn (array $slip): float => $slip['amount'], $GLOBALS['orders']->slipsOf($idOrder));
}

// === E. Refunds started from the order page ============================================

// E1 Paid by one payment: refunded from it, the refund known by its key, then its id.
$s = money_shop();
paid_order(500, ['trx_e1' => 10000]);
$answer = refund($s, 500, 2500);
$made = $GLOBALS['api']->bodies('POST /refunds');
check($answer['status'] === RefundSync::DONE && sent() === 'POST /refunds' && (float) ($made[0]['amount'] ?? 0) === 2500.0 && ($made[0]['payment'] ?? '') === 'trx_e1', 'E1: one payment: one refund, for what was asked: ' . json_encode($made));
$key = $answer['keys'][0] ?? '';
check(($made[0]['metadata']['request_key'] ?? '') === $key && ($made[0]['metadata']['order_id'] ?? '') === '500' && ($made[0]['metadata']['source'] ?? '') === 'prestashop', 'E1: it carries the shop\'s key for it, and its order: ' . json_encode($made[0]['metadata'] ?? []));
check(($GLOBALS['api']->headers('POST /refunds')[0]['Idempotency-Key'] ?? '') === 'wajub-prestashop-refund-' . $key, 'E1: sent under an idempotency key derived from it: the call sent again makes no second refund');
$row = $s->refunds()->findByKey($key);
check($row !== null && $row->refundId === 'rfd_1' && $row->status === 'pending' && $row->origin === RefundRecord::ORIGIN_PRESTASHOP && $s->ledger()->refunded($s->orders()->find(500), 'trx_e1') === 2500.0, 'E1: known by its id once Wajub answered, and on the ledger');
check(noted(500, 'Wajub refunded ' . fcfa(2500) . ' from trx_e1.') === 1, 'E1: the merchant reads it: ' . notes_of(500));
$GLOBALS['api']->next['POST /refunds'] = [[422, ['message' => 'Refund amount exceeds refundable amount', 'errors' => ['amount' => ['too much']]]]];
$answer = refund($s, 500, 1000);
check($answer['status'] === RefundSync::REFUSED && $answer['message'] === 'Wajub did not refund it: Refund amount exceeds refundable amount (http_422, HTTP 422)' && count($s->refunds()->forOrder(500)) === 1, 'E1: refused by Wajub: nothing kept, the merchant reads why: ' . $answer['message']);
// Not answered: Wajub may have made it; kept until its webhook says.
$GLOBALS['api']->next['POST /refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
$answer = refund($s, 500, 1000);
$waiting = array_values(array_filter($s->refunds()->forOrder(500), static fn (RefundRecord $r): bool => $r->status === RefundRecord::REQUESTED));
check($answer['status'] === RefundSync::REFUSED && str_contains($answer['message'], 'the refund may have been made all the same') && count($waiting) === 1 && !$waiting[0]->takesMoney(), 'E1: not answered: refused, the merchant told it may have been made, kept as asked: ' . $answer['message']);
$attempts = $GLOBALS['api']->headers('POST /refunds');
check(count($attempts) === 4 && $attempts[2]['Idempotency-Key'] === $attempts[3]['Idempotency-Key'], 'E1: its two attempts carry the same key');
// Its webhook says Wajub made it: PrestaShop made no slip of it, so the order
// records it now, as a refund made in the Wajub Dashboard, once.
$late = refund_data('rfd_e1_late', 'trx_e1', 1000, 'succeeded', ['metadata' => ['request_key' => $waiting[0]->requestKey]]);
deliver_refund($s, 'refund.succeeded', $late);
deliver_refund($s, 'refund.succeeded', $late);
$row = $s->refunds()->findByKey($waiting[0]->requestKey);
check($row->refundId === 'rfd_e1_late' && $s->ledger()->refunded($s->orders()->find(500), 'trx_e1') === 3500.0 && slips_of(500) === [1000.0] && $row->idOrderSlip > 0, 'E1: its webhook says it was made: known, on the ledger, and recorded on the order as a credit slip, once: ' . json_encode(slips_of(500)));
check(noted(500, 'Wajub refund rfd_e1_late of ' . fcfa(1000) . ' (payment trx_e1), asked from this order, went through without a credit slip of it: it is recorded on the order as a credit slip now.') === 1, 'E1: the merchant reads it: ' . notes_of(500));
$GLOBALS['api']->calls();
refund($s, 500, 1000);
check(count($GLOBALS['api']->made) === 2 && count(array_unique(array_map(static fn (array $h): string => $h['Idempotency-Key'] ?? '', $GLOBALS['api']->headers('POST /refunds')))) === 1, 'E1: recorded, asked again: a refund of its own, the order showing the first');

// E2 Paid by two: split, the newest first, each up to what it can refund.
$s = money_shop();
paid_order(501, ['trx_e2p' => 4000, 'trx_e2r' => 6000]);
$answer = refund($s, 501, 7000);
$made = $GLOBALS['api']->bodies('POST /refunds');
check($answer['status'] === RefundSync::DONE && sent() === 'POST /refunds, POST /refunds' && ($made[0]['payment'] ?? '') === 'trx_e2r' && (float) $made[0]['amount'] === 6000.0 && ($made[1]['payment'] ?? '') === 'trx_e2p' && (float) $made[1]['amount'] === 1000.0, 'E2: 6 000 from the newest, 1 000 from the first: ' . json_encode($made));
check(count($answer['keys']) === 2 && $made[0]['metadata']['request_key'] !== $made[1]['metadata']['request_key'], 'E2: a key for each part');
check(noted(501, 'Wajub refunded ' . fcfa(6000) . ' from trx_e2r, ' . fcfa(1000) . ' from trx_e2p.') === 1, 'E2: the merchant reads both parts: ' . notes_of(501));
check($s->ledger()->refunded($s->orders()->find(501), 'trx_e2r') === 6000.0 && $s->ledger()->refunded($s->orders()->find(501), 'trx_e2p') === 1000.0, 'E2: each on the ledger');

// E3, E5 What fits in the newest; then what the newest can no longer refund.
$s = money_shop();
paid_order(503, ['trx_e3p' => 4000, 'trx_e3r' => 6000]);
refund($s, 503, 3000);
check(count($GLOBALS['api']->bodies('POST /refunds')) === 1 && ($GLOBALS['api']->bodies('POST /refunds')[0]['payment'] ?? '') === 'trx_e3r', 'E3: 3 000 come from the newest alone');
$GLOBALS['api']->calls();
refund($s, 503, 4000);
$made = $GLOBALS['api']->bodies('POST /refunds');
check(count($made) === 2 && $made[0]['payment'] === 'trx_e3r' && (float) $made[0]['amount'] === 3000.0 && $made[1]['payment'] === 'trx_e3p' && (float) $made[1]['amount'] === 1000.0, 'E5: the newest refunds what it has left, the first the rest: ' . json_encode($made));

// E4 More than the payments can refund: refused, nothing sent.
$s = money_shop();
paid_order(504, ['trx_e4p' => 4000, 'trx_e4r' => 6000]);
$answer = refund($s, 504, 11000);
check($answer['status'] === RefundSync::REFUSED && spaced($answer['message']) === 'Wajub can refund at most ' . fcfa(10000) . ' on this order.' && sent() === '', 'E4: more than they hold is refused, nothing sent: ' . $answer['message']);

// E6, E8 The second part refused: what Wajub refunded is recorded on a slip of
// its own, PrestaShop making none of the whole; its webhook adds nothing.
$s = money_shop();
paid_order(505, ['trx_e6p' => 4000, 'trx_e6r' => 6000]);
$GLOBALS['api']->next['POST /refunds'] = [];
$api = $GLOBALS['api'];
$api->duringRefund = static function (array $refund) use ($api): void {
    if ($refund['transaction'] === 'trx_e6r') {
        $api->next['POST /refunds'] = [[422, ['message' => 'Refund amount exceeds refundable amount', 'errors' => ['amount' => ['too much']]]]];
    }
};
$answer = refund($s, 505, 7000);
$api->duringRefund = null;
check($answer['status'] === RefundSync::PARTIAL && $answer['refunded'] === 6000.0 && str_contains(spaced($answer['message']), 'Wajub refunded ' . fcfa(6000) . ' from trx_e6r of the ' . fcfa(7000) . ' asked, then refused the rest: Refund amount exceeds refundable amount'), 'E6: the second part refused: the merchant reads what went through: ' . $answer['message']);
$slip = $s->refundSync()->recordParts($s->orders()->find(505), $answer['keys']);
check($slip > 0 && slips_of(505) === [6000.0] && $s->refunds()->findByKey($answer['keys'][0])->idOrderSlip === $slip, 'E6: one slip of the 6 000 Wajub made, which the refund knows');
check(noted(505, 'Wajub refunded ' . fcfa(6000) . ' from payment trx_e6r (refund rfd_1). The rest of the refund asked did not go through.') === 1, 'E6: the merchant reads it: ' . notes_of(505));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_1', 'trx_e6r', 6000, 'succeeded', ['metadata' => ['request_key' => $answer['keys'][0]]]));
check(slips_of(505) === [6000.0], 'E6: its webhook records nothing more');

// E9 Never paid in full: refused, nothing sent. Its money is given back from
// the Dashboard, and asked again from the payer.
$s = money_shop();
money_order(508);
counted(508, 'trx_e9', 4000);
$answer = refund($s, 508, 1000);
check($answer['status'] === RefundSync::REFUSED && $answer['message'] === 'This order is not paid in full: refund its Wajub payments from the Wajub Dashboard. The customer is then asked for what was refunded.' && sent() === '', 'E9: an order never paid in full gets no refund from here: ' . $answer['message']);

// E10 Paid in Live, refunded while the shop is in Sandbox: refused, nothing sent.
$s = money_shop();
paid_order(509, ['trx_e10p' => 4000, 'trx_e10r' => 6000]);
shop_in($s, 'sandbox');
$answer = refund($s, 509, 1000);
check($answer['status'] === RefundSync::REFUSED && $answer['message'] === 'This order was paid in Wajub Live: refund it while Wajub uses that environment, or from the Wajub Dashboard.' && sent() === '', 'E10: paid in Live, the shop in Sandbox: refused, nothing sent');
// Paid in Live, though it counts a Sandbox payment too: refused in Sandbox all
// the same, rather than refunding the Sandbox part as a refund of the order.
$s = money_shop();
money_order(513);
counted(513, 'trx_test_e10b', 4000, 'sandbox');
counted(513, 'trx_e10b_live', 10000, 'live');
mark_paid(513);
shop_in($s, 'sandbox');
$answer = refund($s, 513, 1000);
check($s->ledger()->paidIn($s->orders()->find(513)) === 'live' && $answer['status'] === RefundSync::REFUSED && $answer['message'] === 'This order was paid in Wajub Live: refund it while Wajub uses that environment, or from the Wajub Dashboard.' && sent() === '', 'E10: paid in Live with a Sandbox part, the shop in Sandbox: refused: ' . $answer['message']);
shop_in($s, 'live');
$answer = refund($s, 513, 1000);
check($answer['status'] === RefundSync::DONE && ($GLOBALS['api']->bodies('POST /refunds')[0]['payment'] ?? '') === 'trx_e10b_live' && str_starts_with($GLOBALS['api']->headers('POST /refunds')[0]['Authorization'] ?? '', 'sk.'), 'E10: the shop in Live: its Live payment refunded, with the Live key');
// Paid in Sandbox, the shop in Live: refused, said in its words.
$s = money_shop();
paid_order(514, ['trx_test_e10c' => 10000], 10000.0, 'sandbox');
$answer = refund($s, 514, 1000);
check($answer['status'] === RefundSync::REFUSED && $answer['message'] === 'This order was paid in Wajub Sandbox: refund it while Wajub uses that environment, or from the Wajub Dashboard.' && sent() === '', 'E10: paid in Sandbox, the shop in Live: refused');

// E12 No Wajub payment counts toward the order (paid another way): nothing for
// Wajub to refund, the slip made without it, the merchant told.
$s = money_shop();
money_order(515, 'trx_e12');
mark_paid(515);
$answer = refund($s, 515, 1000);
check($answer['status'] === RefundSync::NONE && sent() === '' && noted(515, 'No Wajub payment counts toward this order: the refund of ' . fcfa(1000) . ' was recorded without Wajub.') === 1, 'E12: an order no Wajub payment counts toward: the slip goes on, without Wajub: ' . notes_of(515));

// E13 A slip made another way (a module, PrestaShop's API): refunded after the
// fact, and linked to it; refused, noted for the merchant.
$s = money_shop();
paid_order(516, ['trx_e13' => 10000]);
$slip = $GLOBALS['orders']->merchantSlip(516, 2000);
$s->refundSync()->refundSlipMade($s->orders()->find(516), $slip, 2000);
$own = $s->refunds()->forOrder(516);
check(count($own) === 1 && $own[0]->idOrderSlip === $slip && $own[0]->amount === 2000.0, 'E13: a slip made another way is refunded through Wajub, and the refund knows its slip');
$slip = $GLOBALS['orders']->merchantSlip(516, 20000);
$s->refundSync()->refundSlipMade($s->orders()->find(516), $slip, 20000);
check(noted(516, 'Credit slip ' . $slip . ' (' . fcfa(20000) . ') was made, but Wajub did not refund all of it: Wajub can refund at most ' . fcfa(8000) . ' on this order. Refund the customer the rest from the Wajub Dashboard.') === 1, 'E13: one Wajub will not refund: the merchant is told: ' . notes_of(516));
// The slip PrestaShop made for the refunds asked before it.
$s = money_shop();
paid_order(517, ['trx_e14' => 10000]);
$answer = refund($s, 517, 3000);
$slip = $GLOBALS['orders']->merchantSlip(517, 3000);
$s->refundSync()->linkSlip($answer['keys'], $slip);
check($s->refunds()->findByKey($answer['keys'][0])->idOrderSlip === $slip, 'E14: the slip PrestaShop made records the refund asked before it');

// E15 Not answered, though Wajub made it, then asked again for the same
// amount: sent again under the same key, which Wajub answers with the refund
// it made. One refund, one row, the slip PrestaShop makes records it.
$s = money_shop();
paid_order(518, ['trx_e15' => 10000]);
$api = $GLOBALS['api'];
$api->duringRefund = static function (array $refund) use ($api): void {
    // Made, then its answer lost, and the client's own retry too.
    $api->duringRefund = null;
    $api->next['POST /refunds'] = [FakeWajubApi::timeout()];
    throw FakeWajubApi::timeout();
};
$first = refund($s, 518, 3000);
check($first['status'] === RefundSync::REFUSED && count($api->made) === 1 && str_contains($first['message'], 'Until then, asking again for the same amount sends the same refund again'), 'E15: made, its answer lost: refused, the merchant told asking again is safe: ' . $first['message']);
$again = refund($s, 518, 3000);
$rows = $s->refunds()->forOrder(518);
$keys = array_values(array_unique(array_map(static fn (array $h): string => $h['Idempotency-Key'] ?? '', $api->headers('POST /refunds'))));
check($again['status'] === RefundSync::DONE && count($api->made) === 1 && count($keys) === 1, 'E15: asked again for the same amount: under the same key, Wajub answers with the refund it made, never a second one: ' . json_encode($keys));
check(count($rows) === 1 && $rows[0]->refundId === 'rfd_1' && $again['keys'] === [$rows[0]->requestKey] && ($api->bodies('POST /refunds')[2]['metadata']['request_key'] ?? '') === $rows[0]->requestKey, 'E15: one row, known by its Wajub id, whose slip comes next');
// Not made the first time: the same key makes it, once.
$s = money_shop();
paid_order(519, ['trx_e15b' => 10000]);
$GLOBALS['api']->next['POST /refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
refund($s, 519, 3000);
$again = refund($s, 519, 3000);
check($again['status'] === RefundSync::DONE && count($GLOBALS['api']->made) === 1 && count($s->refunds()->forOrder(519)) === 1 && count(array_unique(array_map(static fn (array $h): string => $h['Idempotency-Key'] ?? '', $GLOBALS['api']->headers('POST /refunds')))) === 1, 'E15: not made the first time: asked again under its key, made once');
// Another amount meanwhile: a refund of its own, the first left to its webhook.
$s = money_shop();
paid_order(520, ['trx_e15c' => 10000]);
$GLOBALS['api']->next['POST /refunds'] = [FakeWajubApi::timeout(), FakeWajubApi::timeout()];
refund($s, 520, 3000);
$other = refund($s, 520, 2000);
$rows = $s->refunds()->forOrder(520);
check($other['status'] === RefundSync::DONE && count($rows) === 2 && $rows[0]->status === RefundRecord::REQUESTED && count(array_unique(array_map(static fn (array $h): string => $h['Idempotency-Key'] ?? '', $GLOBALS['api']->headers('POST /refunds')))) === 2, 'E15: another amount asked meanwhile: a refund of its own, under a key of its own');

/**
 * Wajub makes the next refund asked, then its answer is lost, and the
 * client's own retry's too. The refund Wajub made, once asked.
 *
 * @return ArrayObject<string, mixed>
 */
function made_unanswered(FakeWajubApi $api): ArrayObject
{
    $made = new ArrayObject();
    $api->duringRefund = static function (array $refund) use ($api, $made): void {
        $api->duringRefund = null;
        $made->exchangeArray($refund);
        $api->next['POST /refunds'] = [FakeWajubApi::timeout()];
        throw FakeWajubApi::timeout();
    };

    return $made;
}

// E15 Its refund.created (or .processing) handled before the merchant asks
// again, as in production, where the webhook waits for the request that
// asked, then Wajub sends it again: Wajub's id known, the refund pending,
// no slip yet. Asked again for the same amount: still under its key, one
// refund; the slip PrestaShop makes records it, its refund.succeeded nothing
// more. The same on an order paid in two payments, the refund from the
// newest: the part is planned from the room that payment had when it was
// first asked, not from what the pending refund leaves it.
foreach ([[525, ['trx_e15d' => 10000], 'refund.created'], [526, ['trx_e15e' => 10000], 'refund.processing'], [527, ['trx_e15f' => 6000, 'trx_e15g' => 4000], 'refund.created']] as [$id, $parts, $event]) {
    $s = money_shop();
    paid_order($id, $parts);
    $api = $GLOBALS['api'];
    $made = made_unanswered($api);
    refund($s, $id, 3000);
    deliver_refund($s, $event, ['status' => RefundSync::statusOfEvent($event)] + $made->getArrayCopy());
    $row = $s->refunds()->forOrder($id)[0];
    $from = array_key_last($parts);
    check($row->refundId === $made['id'] && $row->status === RefundSync::statusOfEvent($event) && $row->idOrderSlip === 0 && $made['transaction'] === $from, "E15 ({$event} first, order {$id}): Wajub's id known, no slip yet");
    $again = refund($s, $id, 3000);
    $keys = array_values(array_unique(array_map(static fn (array $h): string => $h['Idempotency-Key'] ?? '', $api->headers('POST /refunds'))));
    $asked = array_map(static fn (array $body): string => $body['payment'] . '/' . (float) $body['amount'], $api->bodies('POST /refunds'));
    check($again['status'] === RefundSync::DONE && count($api->made) === 1 && $keys === ['wajub-prestashop-refund-' . $row->requestKey] && $again['keys'] === [$row->requestKey] && count($s->refunds()->forOrder($id)) === 1 && array_unique($asked) === ["{$from}/3000"], "E15 ({$event} first, order {$id}): asked again for the same amount, still under its key: Wajub answers with the refund it made, never a second one: " . json_encode($asked));
    $slip = $GLOBALS['orders']->merchantSlip($id, 3000);
    $s->refundSync()->linkSlip($again['keys'], $slip);
    deliver_refund($s, 'refund.succeeded', ['status' => 'succeeded'] + $made->getArrayCopy());
    check(slips_of($id) === [] && $s->refunds()->findByKey($row->requestKey)->idOrderSlip === $slip && $s->refunds()->findByKey($row->requestKey)->status === 'succeeded', "E15 ({$event} first, order {$id}): the slip PrestaShop made records it, its refund.succeeded nothing more");
}
// Another amount while one is pending: a refund of its own, from what is
// left once the pending one is taken off, never more.
$s = money_shop();
paid_order(528, ['trx_e15h' => 10000]);
$api = $GLOBALS['api'];
$made = made_unanswered($api);
refund($s, 528, 3000);
deliver_refund($s, 'refund.created', ['status' => 'pending'] + $made->getArrayCopy());
$over = refund($s, 528, 8000);
check($over['status'] === RefundSync::REFUSED && spaced($over['message']) === 'Wajub can refund at most ' . fcfa(7000) . ' on this order.' && count($api->made) === 1, 'E15: more than is left beside the pending one: refused: ' . $over['message']);
$s = money_shop();
paid_order(529, ['trx_e15i' => 6000, 'trx_e15j' => 4000]);
$api = $GLOBALS['api'];
$made = made_unanswered($api);
refund($s, 529, 3000);
deliver_refund($s, 'refund.created', ['status' => 'pending'] + $made->getArrayCopy());
$before = count($api->bodies('POST /refunds'));
$other = refund($s, 529, 2000);
$asked = array_map(static fn (array $body): string => $body['payment'] . '/' . (float) $body['amount'], array_slice($api->bodies('POST /refunds'), $before));
check($other['status'] === RefundSync::DONE && $asked === ['trx_e15j/1000', 'trx_e15i/1000'] && count($api->made) === 3, 'E15: another amount on two payments: the newest gives what the pending one leaves it, the other the rest: ' . json_encode($asked));

// E16 Its webhook delivered while the call was open, which then went
// unanswered: Wajub said it made it, so it went through, and the slip
// PrestaShop makes next records it; nothing recorded meanwhile.
$s = money_shop();
paid_order(521, ['trx_e16' => 10000]);
$api = $GLOBALS['api'];
$api->duringRefund = static function (array $refund) use ($api, $s): void {
    $api->duringRefund = null;
    deliver_refund($s, 'refund.succeeded', ['status' => 'succeeded'] + $refund);
    $api->next['POST /refunds'] = [FakeWajubApi::timeout()];
    throw FakeWajubApi::timeout();
};
$answer = refund($s, 521, 2500);
check($answer['status'] === RefundSync::DONE && $answer['refunded'] === 2500.0 && count($answer['keys']) === 1 && count($api->made) === 1 && slips_of(521) === [], 'E16: its webhook said Wajub made it during the call, which then went unanswered: done, no slip of the module\'s: ' . json_encode($answer));
check(noted(521, 'Wajub refunded ' . fcfa(2500) . ' from trx_e16.') === 1 && $s->refunds()->findByKey($answer['keys'][0])->status === 'succeeded', 'E16: the merchant reads it: ' . notes_of(521));

// E17 A refund PrestaShop computes at 0 or less (products returned for an
// exchange, the order's voucher taken off): nothing for Wajub, nothing refused.
$s = money_shop();
paid_order(522, ['trx_e17' => 10000]);
foreach ([0.0, -2000.0] as $amount) {
    $answer = refund($s, 522, $amount);
    check($answer === ['status' => RefundSync::DONE, 'message' => '', 'keys' => [], 'refunded' => 0.0] && sent() === '' && notes_of(522) === '', "E17: a refund of {$amount}: nothing for Wajub, nothing refused: " . json_encode($answer));
}

// E18 The order page's refund through its request (Refund\PendingSlips): the
// order locked as the form is read, until the request ends; the slip
// PrestaShop makes records the refunds.
$s = money_shop();
paid_order(523, ['trx_e18' => 10000]);
$pending = new PendingSlips();
check($pending->lock(523, $s, 10) && isset($GLOBALS['locks']->held['order:523']), 'E18: the order locked as the form is read');
$answer = refund($s, 523, 3000);
$pending->expect(523, $s, $answer['keys']);
check($pending->expects(523) && !$GLOBALS['locks']->acquire('order:523', 0), 'E18: Wajub refunded: the lock stays; the same form posted again waits for this request');
$slip = $GLOBALS['orders']->merchantSlip(523, 3000);
$pending->slipMade(523, $slip);
check($s->refunds()->findByKey($answer['keys'][0])->idOrderSlip === $slip && isset($GLOBALS['locks']->held['order:523']) && !$pending->expects(523), 'E18: PrestaShop made the slip: it records the refund; the lock stays until PrestaShop has written what it refunded');
$pending->finish();
check(!isset($GLOBALS['locks']->held['order:523']) && slips_of(523) === [], 'E18: the request over, the lock goes, nothing more recorded');
$pending->lock(523, $s, 10);
$pending->unlock(523);
check(!isset($GLOBALS['locks']->held['order:523']), 'E18: a refund refused lets the lock go at once');

// E19 PrestaShop makes no slip of a refund Wajub made (its command refused
// once Wajub had refunded, or failed): recorded on a slip of its own at the
// end of the request, which its webhook finds.
$s = money_shop();
paid_order(524, ['trx_e19' => 10000]);
$pending = new PendingSlips();
$pending->lock(524, $s, 10);
$answer = refund($s, 524, 4000);
$pending->expect(524, $s, $answer['keys']);
$pending->finish();
$row = $s->refunds()->findByKey($answer['keys'][0]);
check(slips_of(524) === [4000.0] && $row->idOrderSlip > 0 && !isset($GLOBALS['locks']->held['order:524']), 'E19: no slip made of it: recorded on one of its own at the end of the request, the lock let go: ' . json_encode(slips_of(524)));
check(noted(524, 'Wajub refunded ' . fcfa(4000) . ' from payment trx_e19 (refund rfd_1), but PrestaShop did not make the credit slip of this refund: what Wajub refunded is recorded on a credit slip of its own.') === 1, 'E19: the merchant reads it: ' . notes_of(524));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_1', 'trx_e19', 4000, 'succeeded', ['metadata' => ['request_key' => $answer['keys'][0]]]));
check(slips_of(524) === [4000.0], 'E19: its webhook records nothing more');

// E20 PrestaShop makes the slip, but its hook never reaches the module:
// another module's actionOrderSlipAdd fails before it (PrestaShop adds the
// slip first, then runs the hook, then reports the error). The slip, the
// order's newest since Wajub was asked, records the refund at the end of
// the request: never a second slip of the module's for it. The merchant is
// told to check the refunded quantities before refunding again.
$s = money_shop();
paid_order(530, ['trx_e20' => 10000]);
$earlier = $GLOBALS['orders']->merchantSlip(530, 500);
$pending = new PendingSlips();
$pending->lock(530, $s, 10);
$answer = refund($s, 530, 4000);
$pending->expect(530, $s, $answer['keys']);
$slip = $GLOBALS['orders']->merchantSlip(530, 4000);
$pending->finish();
$row = $s->refunds()->findByKey($answer['keys'][0]);
check(slips_of(530) === [] && $row->idOrderSlip === $slip && $slip > $earlier && !isset($GLOBALS['locks']->held['order:530']), 'E20: PrestaShop made its slip out of the module\'s sight: that slip records the refund, no second slip, the lock let go: ' . json_encode(slips_of(530)));
check(noted(530, 'PrestaShop made credit slip ' . $slip . ' of this refund without the module being told: it records the ' . fcfa(4000) . ' Wajub refunded for it. An error may have stopped the refund after the slip: check the refunded quantities of the order\'s products before refunding it again.') === 1, 'E20: the merchant reads it: ' . notes_of(530));
deliver_refund($s, 'refund.succeeded', refund_data($row->refundId, 'trx_e20', 4000, 'succeeded', ['metadata' => ['request_key' => $answer['keys'][0]]]));
check(slips_of(530) === [] && noted(530, 'went through without a credit slip') === 0, 'E20: its webhook records nothing more');

// === Refunds made in the Wajub Dashboard ==================================================

// D5 A Dashboard refund of the first payment, once the order is paid: the
// order is found by the payment it counts, and the refund recorded once.
$s = money_shop();
paid_order(403, ['trx_d5p' => 4000, 'trx_d5r' => 6000]);
$refund = refund_data('rfd_d5', 'trx_d5p', 1000);
deliver_refund($s, 'refund.succeeded', $refund);
deliver_refund($s, 'refund.succeeded', $refund);
check(slips_of(403) === [1000.0] && $s->refunds()->find('rfd_d5')->status === 'succeeded' && $s->refunds()->find('rfd_d5')->idOrderSlip > 0, 'D5, J2: a refund of the first payment is recorded on the paid order as a credit slip, once: ' . json_encode(slips_of(403)));
check(noted(403, 'already records as refunded') === 0 && noted(403, 'beyond') === 0, 'D5, J2: delivered again, it is known as recorded: nothing more said of it: ' . notes_of(403));
check(noted(403, 'Wajub refund rfd_d5 of ' . fcfa(1000) . ' (payment trx_d5p), made in the Wajub Dashboard, is recorded on the order as a credit slip.') === 1 && $GLOBALS['orders']->state(403) === FakeShopOrders::PAID, 'D5: the merchant reads it; a part refunded leaves the order paid: ' . notes_of(403));
// Its creation, then its success: recorded once it succeeded, not before.
deliver_refund($s, 'refund.created', refund_data('rfd_d5b', 'trx_d5r', 500, 'pending'));
check(slips_of(403) === [1000.0] && $s->refunds()->find('rfd_d5b')->takesMoney(), 'D5: a refund pending is known, takes its money back, and is no slip yet');
deliver_refund($s, 'refund.succeeded', refund_data('rfd_d5b', 'trx_d5r', 500));
check(slips_of(403) === [1000.0, 500.0], 'D5: once it succeeded, it is one');

// D6 A Dashboard refund of a counted payment while the order is unpaid: the
// payer pays it again, never a slip as well.
$s = money_shop();
money_order(404);
counted(404, 'trx_d6', 4000);
deliver_refund($s, 'refund.created', refund_data('rfd_d6', 'trx_d6', 4000, 'pending'));
$entry = $s->refunds()->find('rfd_d6');
check($entry !== null && $entry->orderUnpaid && $entry->status === 'pending' && $s->ledger()->due($s->orders()->find(404), 'live') === 10000.0, 'D6: a pending refund of the 4 000 it collected: all 10 000 to pay again');
check(noted(404, 'trx_d6, which paid part of this order, has a refund of ' . fcfa(4000) . '. ' . fcfa(10000) . ' of the order is now left to pay.') === 1, 'D6: the merchant reads it: ' . notes_of(404));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_d6', 'trx_d6', 4000));
check(noted(404, 'has a refund of') === 1 && slips_of(404) === [] && $s->refunds()->find('rfd_d6')->status === 'succeeded', 'D6: once it succeeded: no second note, no slip');
$s = money_shop();
money_order(405);
counted(405, 'trx_d6b', 4000);
deliver_refund($s, 'refund.created', refund_data('rfd_d6b', 'trx_d6b', 1000, 'pending'));
deliver_refund($s, 'refund.failed', refund_data('rfd_d6b', 'trx_d6b', 1000, 'failed'));
check($s->refunds()->find('rfd_d6b')->status === 'failed' && $s->ledger()->due($s->orders()->find(405), 'live') === 6000.0, 'D6: a refund that fails gives what was left to pay back');

// D7 Delivered out of order: a refund done stays done.
deliver_refund($s, 'refund.succeeded', refund_data('rfd_d7', 'trx_d6b', 500));
deliver_refund($s, 'refund.created', refund_data('rfd_d7', 'trx_d6b', 500, 'pending'));
check($s->refunds()->find('rfd_d7')->status === 'succeeded', 'D7: its creation, delivered after it succeeded, changes nothing');
// One whose status the event alone says.
deliver_refund($s, 'refund.cancelled', ['id' => 'rfd_d7b', 'transaction' => 'trx_d6b', 'amount' => 300, 'currency' => 'XAF', 'metadata' => []]);
check($s->refunds()->find('rfd_d7b')->status === 'cancelled' && !$s->refunds()->find('rfd_d7b')->takesMoney(), 'D7: a refund that names no status takes its event\'s');

// D11 A refund pending while the order was unpaid; the order paid in full
// since: its success is no slip; its failure leaves Wajub holding more than
// the total, which the merchant is told, once.
foreach (['succeeded' => 408, 'failed' => 409] as $end => $orderId) {
    $s = money_shop();
    money_order($orderId);
    counted($orderId, 'trx_d11p_' . $end, 4000);
    deliver_refund($s, 'refund.created', refund_data('rfd_d11_' . $end, 'trx_d11p_' . $end, 4000, 'pending'));
    counted($orderId, 'trx_d11q_' . $end, 10000);
    mark_paid($orderId);
    deliver_refund($s, 'refund.' . $end, refund_data('rfd_d11_' . $end, 'trx_d11p_' . $end, 4000, $end));
    deliver_refund($s, 'refund.' . $end, refund_data('rfd_d11_' . $end, 'trx_d11p_' . $end, 4000, $end));
    check(slips_of($orderId) === [] && $GLOBALS['orders']->state($orderId) === FakeShopOrders::PAID, "D11 ({$end}): no slip on an order the payer paid again");
    check(noted($orderId, 'Wajub refund rfd_d11_' . $end . ' of payment trx_d11p_' . $end . ' did not go through, and the order was paid in full since: Wajub holds ' . fcfa(4000) . ' more') === ($end === 'failed' ? 1 : 0), "D11 ({$end}): " . ($end === 'failed' ? 'the surplus is pointed out, once' : 'nothing more is said') . ': ' . notes_of($orderId));
}

// D13 A refund of the order's payment before it counts: known, no note yet.
$s = money_shop();
money_order(418, 'trx_d13b');
deliver_refund($s, 'refund.succeeded', refund_data('rfd_d13b', 'trx_d13b', 1000));
check(notes_of(418) === '' && $s->refunds()->find('rfd_d13b') !== null && $s->refunds()->find('rfd_d13b')->orderUnpaid && slips_of(418) === [], 'D13: a refund of the order\'s own payment, not counted yet, is known, with no note yet');
// An earlier attempt refunded before it counts: noted as a payment that did not pay the order.
$s = money_shop();
money_order(419, 'trx_d13c_old');
money_payment(419, 'trx_d13c_new');
deliver_refund($s, 'refund.succeeded', refund_data('rfd_d13c', 'trx_d13c_old', 1000));
check(noted(419, 'Wajub payment trx_d13c_old, which did not pay this order, was refunded (' . fcfa(1000) . '). The order was left as it is.') === 1 && slips_of(419) === [], 'D13: an earlier attempt refunded, not counted: noted as one that did not pay the order: ' . notes_of(419));

// E11 Dashboard refunds of a paid order whose payments hold more than its
// total: what gives back money Wajub held beyond the total is noted, never a
// slip; the rest is.
$s = money_shop();
money_order(510, 'trx_e11');
counted(510, 'trx_e11', 4000);
$GLOBALS['orders']->orders[510]['total'] = 3500.0;
$s->settlement()->completeIfCovered($s->orders()->find(510), 'live');
$surplus = refund_data('rfd_e11', 'trx_e11', 500);
deliver_refund($s, 'refund.succeeded', $surplus);
deliver_refund($s, 'refund.succeeded', $surplus);
check(slips_of(510) === [] && noted(510, fcfa(500) . ' of Wajub refund rfd_e11 (payment trx_e11) gave back what Wajub held beyond the order\'s total: it is not recorded as a refund of the order.') === 1, 'E11: the 500 held beyond a total lowered to 3 500, refunded: noted once, no slip: ' . notes_of(510));
// Two payments that both went through: 14 000 held for 10 000.
$s = money_shop();
paid_order(511, ['trx_e11p' => 4000, 'trx_e11q' => 10000]);
deliver_refund($s, 'refund.succeeded', refund_data('rfd_e11b', 'trx_e11q', 5000));
check(slips_of(511) === [1000.0] && noted(511, fcfa(4000) . ' of Wajub refund rfd_e11b (payment trx_e11q) gave back') === 1, 'E11: a refund of 5 000 of 14 000 held for 10 000: 1 000 is a refund of the order, 4 000 the surplus: ' . json_encode(slips_of(511)));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_e11c', 'trx_e11q', 2000));
check(slips_of(511) === [1000.0, 2000.0] && noted(511, 'gave back what Wajub held beyond') === 1, 'E11: then a refund of 2 000, with no surplus left: all of it a refund of the order: ' . json_encode(slips_of(511)));
// A refund made from the order page first: what the order still counts as
// paid is its total less that slip.
$s = money_shop();
paid_order(512, ['trx_e11s' => 4000, 'trx_e11t' => 10000]);
$answer = refund($s, 512, 1000);
$s->refundSync()->linkSlip($answer['keys'], $GLOBALS['orders']->merchantSlip(512, 1000));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_e11d', 'trx_e11t', 5000));
check(slips_of(512) === [1000.0] && noted(512, fcfa(4000) . ' of Wajub refund rfd_e11d') === 1, 'E11: after a refund of 1 000 from the order page, a Dashboard refund of 5 000 of the 13 000 held for 9 000: 1 000 is a refund of the order: ' . json_encode(slips_of(512)) . ' ' . notes_of(512));

// L2 A Sandbox payment counted toward an order later paid in Live: its refund
// gives back nothing the order was paid with. Noted, never a slip; a Live
// refund still is.
$s = money_shop();
money_order(962);
counted(962, 'trx_test_l2', 4000, 'sandbox');
counted(962, 'trx_l2_live', 10000, 'live');
mark_paid(962);
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l2', 'trx_test_l2', 4000), 'sandbox');
check($s->ledger()->paidIn($s->orders()->find(962)) === 'live' && slips_of(962) === [] && noted(962, 'Wajub payment trx_test_l2, which did not pay this order, was refunded (' . fcfa(4000) . '). The order was left as it is.') === 1, 'L2: a Sandbox refund of an order paid in Live: noted, no slip: ' . notes_of(962));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l2b', 'trx_l2_live', 1000));
check(slips_of(962) === [1000.0], 'L2: a Live refund of its Live payment is one of the order');
// A delivery signed in the other environment than its payment's: never acted on.
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l2c', 'trx_l2_live', 1000), 'sandbox');
check($s->refunds()->find('rfd_l2c') === null && slips_of(962) === [1000.0], 'L2: a refund of a Live payment signed with the Sandbox secret changes nothing');
// A refund of a payment of no order of this shop.
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l2d', 'trx_elsewhere', 1000));
check($s->refunds()->find('rfd_l2d') === null, 'L2: one of a payment the shop never made: nothing');

// L3 A slip recorded without Wajub ("Refund manually"), then the refund made
// in the Wajub Dashboard: the order's refunds add up to what Wajub refunded,
// and the note says why part of it is not recorded again.
$s = money_shop();
paid_order(963, ['trx_l3' => 10000]);
$GLOBALS['orders']->merchantSlip(963, 4000);
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l3', 'trx_l3', 10000));
check(slips_of(963) === [6000.0] && $GLOBALS['orders']->refundedOnOrder($s->orders()->find(963)) === 10000.0, 'L3: 4 000 refunded by hand, then 10 000 in the Dashboard: 6 000 more recorded, 10 000 in all: ' . json_encode(slips_of(963)));
check(noted(963, 'Wajub refund rfd_l3 (payment trx_l3) gives back money this order already records as refunded without Wajub: ' . fcfa(4000) . ' of it is not recorded a second time, and ' . fcfa(6000) . ' is recorded now.') === 1 && noted(963, 'beyond') === 0, 'L3: the merchant reads what happened, not a surplus: ' . notes_of(963));
check($GLOBALS['orders']->state(963) === FakeShopOrders::REFUNDED && end($GLOBALS['orders']->history)['mail'] === true, 'L3: all of it given back: the order is "Refunded", with PrestaShop\'s e-mail of that state');
check(noted(963, 'Wajub gave back all its payments of this order collected: the order is now "Refunded".') === 1, 'L3: and the merchant reads why');
$s = money_shop();
paid_order(964, ['trx_l3b' => 10000]);
$GLOBALS['orders']->merchantSlip(964, 10000);
deliver_refund($s, 'refund.succeeded', refund_data('rfd_l3b', 'trx_l3b', 10000));
check(slips_of(964) === [] && noted(964, 'Wajub refund rfd_l3b (payment trx_l3b) gives back ' . fcfa(10000) . ' this order already records as refunded without Wajub: it is not recorded a second time.') === 1 && noted(964, 'beyond') === 0, 'L3: the whole of it refunded by hand first: nothing more recorded, and said so: ' . notes_of(964));

// L7 The split refund's keys: each part's webhook, delivered while the calls
// last, finds its key and records nothing on the order.
$s = money_shop();
paid_order(970, ['trx_l7p' => 4000, 'trx_l7r' => 6000]);
$GLOBALS['l7_rows'] = [];
$GLOBALS['api']->duringRefund = static function (array $refund) use ($s): void {
    $GLOBALS['l7_rows'][] = count($s->refunds()->forOrder(970));
    deliver_refund($s, 'refund.succeeded', $refund + ['sandbox' => false]);
};
$answer = refund($s, 970, 7000);
$GLOBALS['api']->duringRefund = null;
check($answer['status'] === RefundSync::DONE && count($GLOBALS['api']->made) === 2, 'L7: a refund of 7 000 split across two payments');
check($GLOBALS['l7_rows'] === [1, 2] && count($s->refunds()->forOrder(970)) === 2 && slips_of(970) === [], 'L7: each part known by its key before its call, its webhook delivered meanwhile finds it: no second row, no slip: ' . json_encode($GLOBALS['l7_rows']));
check(array_map(static fn (RefundRecord $r): string => $r->refundId . '/' . $r->status, $s->refunds()->forOrder(970)) === ['rfd_1/pending', 'rfd_2/pending'], 'L7: each with its Wajub id and status, as its webhook said');
check($GLOBALS['orders']->state(970) === FakeShopOrders::PAID, 'L7: 7 000 of 10 000 given back: the order stays paid');

// The shop's own refunds: "Refunded" once all is back; one that fails, told.
$s = money_shop();
paid_order(971, ['trx_o1' => 10000]);
$answer = refund($s, 971, 10000);
check($GLOBALS['orders']->state(971) === FakeShopOrders::PAID, 'own: asked, not done yet: the order stays paid');
$s->refundSync()->linkSlip($answer['keys'], $GLOBALS['orders']->merchantSlip(971, 10000));
deliver_refund($s, 'refund.succeeded', refund_data('rfd_1', 'trx_o1', 10000, 'succeeded', ['metadata' => ['request_key' => $answer['keys'][0]]]));
check($GLOBALS['orders']->state(971) === FakeShopOrders::REFUNDED && slips_of(971) === [], 'own: once Wajub refunded all of it, the order is "Refunded", the merchant\'s slip its only one');
$s = money_shop();
paid_order(972, ['trx_o2' => 10000]);
$answer = refund($s, 972, 3000);
deliver_refund($s, 'refund.failed', refund_data('rfd_1', 'trx_o2', 3000, 'failed', ['metadata' => ['request_key' => $answer['keys'][0]]]));
check(noted(972, 'Wajub refund rfd_1 of ' . fcfa(3000) . ' (payment trx_o2), asked from a credit slip of this order, did not go through (failed): the customer did not get this money back.') === 1 && $s->ledger()->refunded($s->orders()->find(972), 'trx_o2') === 0.0, 'own: one that failed: the merchant is told the customer did not get it back: ' . notes_of(972));

// A full Dashboard refund of an order paid by one payment: a slip of it all, "Refunded".
$s = money_shop();
paid_order(973, ['trx_o3' => 10000]);
deliver_refund($s, 'refund.succeeded', refund_data('rfd_o3', 'trx_o3', 10000));
check(slips_of(973) === [10000.0] && $GLOBALS['orders']->state(973) === FakeShopOrders::REFUNDED, 'a full Dashboard refund: a slip of it all, the order "Refunded"');
deliver_refund($s, 'refund.succeeded', refund_data('rfd_o3', 'trx_o3', 10000));
check(slips_of(973) === [10000.0] && noted(973, 'is now "Refunded"') === 1, 'delivered again: nothing more');
// A refund of a refunded order's payment arriving late: the order never goes back to paid in part.
check($s->settlement()->apply($s->orders()->find(973), $GLOBALS['payments']->find('trx_o3'), ['outcome' => 'succeeded', 'status' => 'refunded', 'transaction' => ['id' => 'trx_o3', 'status' => 'refunded', 'amount' => 10000, 'amount_paid' => 10000, 'currency' => 'XAF', 'metadata' => ['order_id' => '973']]]) !== 'failed' && $GLOBALS['orders']->state(973) === FakeShopOrders::REFUNDED, 'a refunded order read again stays "Refunded"');

done('refunds');

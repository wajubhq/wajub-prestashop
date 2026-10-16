#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# The money of an order on a local shop (the shops of
# ~/.cache/wajub-prestashop/env, ENV.md), against the stand-in for Wajub's API
# (tests/integration/api-stub.py): what run-payment.sh does not reach.
#
#   1. part paid: an order paid 4 000 short on Wajub's page (a plan closed
#      early), its webhook: "Partially paid with Wajub", one PrestaShop
#      payment of what it collected, what is left said on the confirmation
#      page and the customer's order page, with "Pay the rest";
#   2. "Pay the rest": a payment on Wajub's page for what is left, named after
#      the order ("-2"), saying what was received; its webhook pays the
#      order, two PrestaShop payments;
#   3. a partial refund from the back office's order page: refunded through
#      Wajub before PrestaShop makes its credit slip, the newest payment
#      first, the slip recording it; refused (the order paid in the other
#      environment): PrestaShop says why, no slip; a standard refund
#      PrestaShop refuses after the hook (returns off): nothing asked of
#      Wajub; the same refund posted twice at once: Wajub asked once; with
#      product returns on, a standard refund (order not delivered) and a
#      return of products (delivered), each refunded through Wajub for what
#      its slip refunds; another module's actionOrderSlipAdd failing before
#      the module's: PrestaShop's slip records the refund, no second slip;
#      the same with a PHP Error that ends the request before any end of
#      request (kernel.terminate): the slip records the refund as PrestaShop
#      adds it, the merchant told to check the quantities, its webhook adds
#      nothing; such an Error before PrestaShop makes the slip
#      (actionProductCancel): one slip of the module's, made by the webhook;
#   4. a refund made in the Wajub Dashboard (its signed webhook): a credit
#      slip of it on the order, once;
#   5. a refund Wajub refuses after a part went through: one slip of what it
#      refunded, none of the refund asked, nothing refunded twice; an order
#      the cart split in two (products of two carriers), paid, paid the rest
#      of, and refunded as one (a refund made in the Wajub Dashboard: one
#      slip per order, each up to its total); a customer group shown prices
#      without tax, refunded what the slip gives back with tax; a voucher
#      taken off a refund, counted as the slip gave it back; a payment whose
#      refunds Wajub did not answer for, read again once due, as the shop's
#      pages ask, and one of an order deleted since, asked for no more;
#   6. the scheduled task (controllers/front/cron.php): a wrong token refused;
#      an order the payer left cancelled, its payment cancelled with Wajub;
#      an order part paid kept;
#   7. "Check status" of a payment Wajub has not in this environment; none
#      for an order that no longer waits for its payment.
#
#     tests/integration/run-money.sh 8
#     tests/integration/run-money.sh 9
#
# Leaves the module installed from the repository, the stand-in's address
# removed from the shop's configuration.

set -euo pipefail

MAJOR="${1:?usage: run-money.sh <81|8|9>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DIR="${WAJUB_PS_ENV:-$HOME/.cache/wajub-prestashop/env}"
source "$ENV_DIR/lib.sh"
ps_select "$MAJOR"

failures=0
ok() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got: $2, expected: $3)"; fi; }
contains() { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 (no \"$3\" in: ${2:0:400})"; fi; }
lacks() { if grep -qiE -- "$3" <<<"$2"; then fail "$1 (\"$(grep -oiE -- "$3" <<<"$2" | head -1)\")"; else ok "$1"; fi; }
section() { printf '\n== %s\n' "$1"; }
php_in_shop() { "$ENV_DIR/exec.sh" "$MAJOR" php -r "$1"; }
json() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {"d": d}))' "$1"; }

CHECKS="wajub-checks-money-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }
source "$ROOT/tests/integration/lib-bo.sh"

if [ "$PS_FAMILY" = 8 ]; then FO="$PS_URL/en"; else FO="$PS_URL"; fi
jar="$(mktemp -p "$LOG_DIR" wajub-money-jar.XXXXXX)"
bo_jar="$(mktemp -p "$LOG_DIR" wajub-money-bo.XXXXXX)"
STUB_PID=""
STUB_LOG="$LOG_DIR/wajub-stub-money-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-money-ps$MAJOR.port"
STUB=""
FAILING=""
cleanup() {
    stop_stub
    # A failing test module left behind by a run stopped half-way.
    if [ -n "$FAILING" ]; then
        "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
        shop_checks failing-hook remove >/dev/null 2>&1 || true
    fi
    rm -f "$jar" "$bo_jar" "$SHARE_DIR/$CHECKS"
}
trap cleanup EXIT

start_stub() {
    local port="" i
    rm -f "$STUB_PORT_FILE"
    python3 "$ROOT/tests/integration/api-stub.py" "$STUB_PORT_FILE" "$STUB_LOG" &
    STUB_PID=$!
    for i in $(seq 1 50); do
        port="$(cat "$STUB_PORT_FILE" 2>/dev/null || true)"
        [ -n "$port" ] && break
        sleep 0.1
    done
    [ -n "$port" ] || { fail "the API stub starts"; return; }
    STUB="http://127.0.0.1:$port"
    php_in_shop '$f = "config/defines_custom.inc.php"; $c = preg_replace("/^.*wajub-integration-stub.*\n/m", "", (string) file_get_contents($f)); file_put_contents($f, rtrim($c, "\n") . "\n" . "define(\"_WAJUB_API_URL_\", \"http://host.containers.internal:'"$port"'\"); // wajub-integration-stub\n" . "define(\"_WAJUB_JS_URL_\", \"http://localhost:'"$port"'/wajub.js\"); // wajub-integration-stub\n");'
}
stop_stub() {
    if [ -n "$STUB_PID" ]; then
        kill "$STUB_PID" 2>/dev/null || true
        wait "$STUB_PID" 2>/dev/null || true
        STUB_PID=""
        rm -f "$STUB_PORT_FILE"
        php_in_shop '$f = "config/defines_custom.inc.php"; $c = (string) file_get_contents($f); $d = preg_replace("/^.*wajub-integration-stub.*\n/m", "", $c); if ($d !== $c) { file_put_contents($f, $d); }' || true
    fi
}
stub_payment() { curl -s "$STUB/__stub/payments/$1"; }
stub_set() { curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary "$2" "$STUB/__stub/payments/$1"; }

# --- The storefront, as the demo customer ---------------------------------------------------

STATIC_TOKEN=""
login_customer() {
    rm -f "$jar"
    curl -s -c "$jar" -b "$jar" -o /dev/null --data-urlencode "email=pub@prestashop.com" --data-urlencode "password=123456789" -d submitLogin=1 "$FO/login"
    STATIC_TOKEN="$(curl -s -c "$jar" -b "$jar" "$FO/" | grep -o 'static_token":"[a-z0-9]*"' | head -1 | sed 's/.*:"//; s/"//')"
}
add_product() {
    curl -s -c "$jar" -b "$jar" -o /dev/null -H 'Accept: application/json' -d "token=$STATIC_TOKEN&id_product=${1:-1}&id_customization=0&qty=1&add=1&action=update&ajax=1" "$FO/cart"
}
ADDRESS="$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT a.id_address FROM ${DB_PREFIX}address a JOIN ${DB_PREFIX}customer c ON c.id_customer = a.id_customer WHERE c.email = 'pub@prestashop.com' AND a.alias = 'Douala' AND a.deleted = 0 LIMIT 1")"
payment_step() {
    curl -s -c "$jar" -b "$jar" -o /dev/null "$FO/order"
    curl -s -c "$jar" -b "$jar" -o /dev/null -d "id_address_delivery=$ADDRESS&id_address_invoice=$ADDRESS&confirm-addresses=1" "$FO/order"
    local page carrier
    page="$(curl -s -c "$jar" -b "$jar" "$FO/order")"
    # One carrier's id and a comma, or several (a cart PrestaShop ships in
    # several packages: "2,1,").
    carrier="$(grep -oE 'id="delivery_option_[0-9]+" value="[0-9,]+,"' <<<"$page" | head -1 | sed 's/.*value="//; s/"//')"
    if [ -n "$carrier" ]; then
        curl -s -c "$jar" -b "$jar" -o /dev/null --data-urlencode "delivery_option[$ADDRESS]=$carrier" -d "confirmDeliveryOption=1&delivery_message=" "$FO/order"
    fi
    curl -s -c "$jar" -b "$jar" "$FO/order"
}
fields_config() { python3 -c 'import re,sys,json; m=re.search(r"var wajubFields = (\{.*?\});\n", sys.stdin.read()); print(m.group(1) if m else "{}")'; }
# An order placed with "Other payment methods": the order's id; its payment
# is on Wajub's page (the stand-in's).
place_hosted_order() {
    local i
    for i in $(seq 1 "${2:-1}"); do
        add_product "${1:-1}"
    done
    checkout_hosted
}
# The cart as it is, placed with "Other payment methods": the ids of the
# orders it became, one, or one per package.
checkout_hosted() {
    local page config cart token
    page="$(payment_step)"
    config="$(fields_config <<<"$page")"
    cart="$(json 'd["cart"]' <<<"$config")"
    token="$(json 'd["token"]' <<<"$config")"
    VALIDATION="$(json 'd["validationUrl"]' <<<"$config")"
    curl -s -c "$jar" -b "$jar" -o /dev/null -d "kind=other&token=$token&cart=$cart" "$VALIDATION"
    shop_checks orders-of-cart "$cart" | tail -1
}
post() {
    local body="$1" ts sig
    ts="$(date +%s)"
    sig="$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
    curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Wajub-Timestamp: $ts" -H "X-Wajub-Signature: v1=$sig" --data-binary "$body" "$WEBHOOK_URL"
}
# The payment as the stand-in keeps it, delivered as Wajub signs it.
deliver_payment() {
    local data event
    data="$(stub_payment "$1" | python3 -c 'import json,sys; p=json.load(sys.stdin); print(json.dumps({k: p[k] for k in ("id","status","amount","amount_paid","currency","sandbox","channel","metadata","reference") if k in p}))')"
    event="evt_test_money_$(date +%s%N)"
    post "{\"id\":\"$event\",\"type\":\"$2\",\"data\":$data}" | tail -1
}

section "Install, the stand-in for Wajub's API, a sandbox key"
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajub >/dev/null 2>&1 || true
"$ENV_DIR/install.sh" "$MAJOR" >/dev/null || failures=$((failures + 1))
start_stub
SECRET="whsec_test_money_$(date +%s)"
WEBHOOK_URL="$(shop_checks payment-setup "$SECRET" | tail -1)"
WEBHOOK_URL="${WEBHOOK_URL/https:/http:}"
STATES="$(shop_checks states | tail -1)"
AWAITING="$(json 'd["awaiting"]' <<<"$STATES")"
PARTIAL="$(json 'd["partial"]' <<<"$STATES")"
PAID="$(json 'd["paid"]' <<<"$STATES")"
CANCELED="$(json 'd["canceled"]' <<<"$STATES")"
for hook in actionBeforeUpdateCancelProductFormHandler actionObjectOrderSlipAddAfter actionOrderSlipAdd actionCronJob displayOrderDetail; do
    expect "the module is on $hook" "$(shop_checks hooked "$hook" | tail -1)" yes
done

section "Part paid: a plan closed early"
login_customer
# Two of a product: two refunds of one each.
ORDER="$(place_hosted_order 1 2)"
order="$(shop_checks order "$ORDER" | tail -1)"
FIRST="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
TOTAL="$(json 'int(d["total"])' <<<"$order")"
REFERENCE="$(json 'd["reference"]' <<<"$order")"
expect "the order waits for its payment" "$(json 'd["state"]' <<<"$order")" "$AWAITING"
stub_set "$FIRST" '{"status": "succeeded", "amount_paid": 4000, "channel": "cm.mtn"}'
expect "its webhook is accepted" "$(deliver_payment "$FIRST" payment.succeeded)" 200
order="$(shop_checks order "$ORDER" | tail -1)"
expect "the order is \"Partially paid with Wajub\"" "$(json 'd["state"]' <<<"$order")" "$PARTIAL"
expect "one PrestaShop payment, of what it collected" "$(json 'str(len(d["payments"])) + "/" + str(int(float(d["payments"][0]["amount"])))' <<<"$order")" "1/4000"
contains "the merchant reads what is left" "$order" "is left to pay"
LEFT=$((TOTAL - 4000))
# The customer's order page, by the link their order history gives it.
order_detail() {
    local link
    link="$(curl -sL -c "$jar" -b "$jar" "$FO/order-history" | grep -oE "href=\"[^\"]*controller=order-detail&amp;id_order=$1\"" | head -1 | sed 's/^href="//; s/"$//; s/&amp;/\&/g' || true)"
    curl -sL -c "$jar" -b "$jar" "$link"
}
detail="$(order_detail "$ORDER")"
contains "the customer's order page says part of it is paid" "$detail" 'Part of your order is paid.'
contains "with \"Pay the rest\"" "$detail" 'Pay the rest'
lacks "without a PHP error" "$detail" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
PAY_URL="$(grep -oE 'action="[^"]*(controller=pay|/pay)[^"]*"' <<<"$detail" | head -1 | sed 's/^action="//; s/"$//; s/&amp;/\&/g' || true)"
PAY_TOKEN="$(grep -A2 'wajub-order-pay__form' <<<"$detail" | grep -oE 'name="token" value="[^"]+"' | sed 's/.*value="//; s/"$//' || true)"
[ -n "$PAY_URL" ] && ok "its form posts to the module ($PAY_URL)" || fail "its form posts to the module"
if [ -z "$PAY_URL" ] || [ -z "$PAY_TOKEN" ]; then
    echo "PrestaShop $MAJOR money: no \"Pay the rest\" form to go on with"
    exit 1
fi

section "Pay the rest"
where="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -d "token=$PAY_TOKEN" "$PAY_URL")"
expect "the customer is sent to Wajub's page" "${where%% *}" 302
contains "the page of a new payment" "$where" 'https://pay.wajub.com/'
order="$(shop_checks order "$ORDER" | tail -1)"
SECOND="$(json 'd["wajub"][-1]["payment_id"]' <<<"$order")"
[ "$SECOND" != "$FIRST" ] && ok "the order holds a second payment" || fail "the order holds a second payment"
second="$(stub_payment "$SECOND")"
expect "for what is left" "$(json 'str(int(float(d["amount"])))' <<<"$second")" "$LEFT"
expect "named after the order, second" "$(json 'd["reference"]' <<<"$second")" "Order #$REFERENCE-2"
contains "Wajub's page says what was received" "$(json 'd["description"]' <<<"$second")" 'already received'
again="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -d "token=$PAY_TOKEN" "$PAY_URL")"
contains "\"Pay the rest\" again: the same payment, nobody having paid it" "$again" "$SECOND"
expect "and nothing new" "$(json 'len(d["wajub"])' <<<"$(shop_checks order "$ORDER" | tail -1)")" 2
forged="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -d "token=forged" "$PAY_URL")"
contains "a forged token starts nothing" "$forged" 'order-history'
stub_set "$SECOND" '{"status": "succeeded", "channel": "cm.orange"}'
expect "the rest's webhook is accepted" "$(deliver_payment "$SECOND" payment.succeeded)" 200
order="$(shop_checks order "$ORDER" | tail -1)"
expect "the order is paid" "$(json 'd["state"]' <<<"$order")" "$PAID"
expect "two PrestaShop payments" "$(json 'len(d["payments"])' <<<"$order")" 2
contains "the merchant reads which payments paid it" "$order" "paid in full by Wajub payments $FIRST, $SECOND"
detail="$(order_detail "$ORDER")"
lacks "a paid order offers nothing to pay" "$detail" 'Pay the rest|Pay for this order'

section "The back office"
BO_TOKEN="$(bo_login "$bo_jar")"
order_page() { order_page_as "$bo_jar" "$BO_TOKEN" "$1"; }
page="$(order_page "$ORDER")"
printf '%s' "$page" > "$LOG_DIR/wajub-money-order-ps$MAJOR.html"
contains "the order page has the Wajub panel" "$page" 'data-wajub-order-panel'
contains "listing both payments" "$page" "$SECOND"
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
partial_refund() { partial_refund_as "$bo_jar" "$BO_TOKEN" "$1" "$2"; }

section "A partial refund from the order page"
after="$(partial_refund "$ORDER" 3000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-refund-ps$MAJOR.html"
refunds="$(shop_checks refunds "$ORDER" | tail -1)"
made="$(curl -s "$STUB/__stub/refunds")"
expect "Wajub was asked for one refund" "$(json 'len(d["items"])' <<<"$made")" 1
expect "of 3 000, from the newest payment" "$(json 'str(int(float(d["items"][0]["amount"]))) + "/" + d["items"][0]["transaction"]' <<<"$made")" "3000/$SECOND"
expect "carrying the shop's key for it" "$(json 'd["items"][0]["metadata"]["request_key"]' <<<"$made")" "$(json 'd["refunds"][0]["request_key"]' <<<"$refunds")"
expect "PrestaShop made its credit slip" "$(json 'len(d["slips"])' <<<"$refunds")" 1
expect "which records the refund" "$(json 'str(d["refunds"][0]["id_order_slip"]) == str(d["slips"][0]["id_order_slip"])' <<<"$refunds")" True
expect "known by its Wajub id" "$(json 'd["refunds"][0]["refund_id"]' <<<"$refunds")" "$(json 'd["items"][0]["id"]' <<<"$made")"
contains "the merchant reads it" "$(shop_checks order "$ORDER" | tail -1)" 'Wajub refunded'
idem="$(grep '"path": "/refunds"' "$STUB_LOG" | tail -1 | json 'd["idempotency_key"]' || true)"
contains "under a key of its own" "$idem" 'wajub-prestashop-refund-'
# Refused: the order was paid in Sandbox, Wajub uses Live now.
"$ENV_DIR/exec.sh" "$MAJOR" php -r 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; Configuration::updateValue("WAJUB_ENVIRONMENT", "live");' >/dev/null
after="$(partial_refund "$ORDER" 1000)"
"$ENV_DIR/exec.sh" "$MAJOR" php -r 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; Configuration::updateValue("WAJUB_ENVIRONMENT", "sandbox");' >/dev/null
contains "a refund while Wajub uses Live, of an order paid in Sandbox: PrestaShop says why" "$after" 'This order was paid in Wajub Sandbox'
lacks "the module's message as it is, never as an unexpected error" "$after" 'An unexpected error occurred|CoreException'
expect "and makes no slip" "$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$ORDER" | tail -1)")" 1
expect "nothing asked of Wajub" "$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")" 1

section "A refund made in the Wajub Dashboard"
EVENT="evt_test_money_refund_$(date +%s)"
BODY="{\"id\":\"$EVENT\",\"type\":\"refund.succeeded\",\"data\":{\"id\":\"rfd_dashboard_$EVENT\",\"transaction\":\"$FIRST\",\"amount\":1000,\"currency\":\"XAF\",\"status\":\"succeeded\",\"sandbox\":true,\"metadata\":{}}}"
answer="$(post "$BODY")"
expect "its signed webhook is accepted" "${answer##*$'\n'}" 200
expect "and acted on" "$(shop_checks event "$EVENT" | tail -1)" processed
refunds="$(shop_checks refunds "$ORDER" | tail -1)"
expect "a credit slip of it on the order" "$(json 'str(len(d["slips"])) + "/" + str(int(float(d["slips"][-1]["amount"])))' <<<"$refunds")" "2/1000"
answer="$(post "${BODY/$EVENT\",\"type/${EVENT}_again\",\"type}")"
expect "delivered again, as another event" "${answer##*$'\n'}" 200
expect "recorded once" "$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$ORDER" | tail -1)")" 2
contains "the merchant reads it" "$(shop_checks order "$ORDER" | tail -1)" 'is recorded on the order as a credit slip'

section "A refund PrestaShop refuses after the module's hook"
# Standard refund while product returns are off (the shops' setting): the
# page hides the button, yet one opened before returns were turned off
# posts it, and PrestaShop refuses it once the module's hook has run. Wajub
# must not refund what PrestaShop refuses.
expect "product returns are off on this shop" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT IFNULL(MAX(value), 0) FROM ${DB_PREFIX}configuration WHERE name = 'PS_ORDER_RETURN'")" 0
before="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
detail="$(shop_checks order-detail-ids "$ORDER" | tail -1)"
after="$(refund_as "$bo_jar" "$BO_TOKEN" "$ORDER" standard-refund --data-urlencode "cancel_product[selected_$detail]=1" --data-urlencode "cancel_product[quantity_$detail]=1")"
printf '%s' "$after" > "$LOG_DIR/wajub-money-standard-ps$MAJOR.html"
expect "a standard refund PrestaShop refuses: nothing asked of Wajub" "$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")" "$before"
expect "and no slip" "$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$ORDER" | tail -1)")" 2

section "The same refund posted twice at once"
# Two sessions (one session's requests wait for each other), Wajub slow to
# answer the first: the second is posted while the first is refunded. It
# waits for the first request to end, reads that the product is refunded,
# and PrestaShop refuses it: Wajub is asked once, one slip.
bo2_jar="$(mktemp -p "$LOG_DIR" wajub-money-bo2.XXXXXX)"
BO2_TOKEN="$(bo_login "$bo2_jar")"
before="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary '{"path": "POST /refunds", "seconds": 4, "times": 1}' "$STUB/__stub/slow"
partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER" 1000 > "$LOG_DIR/wajub-money-twice-a-ps$MAJOR.html" &
first=$!
sleep 1
partial_refund_as "$bo2_jar" "$BO2_TOKEN" "$ORDER" 1000 > "$LOG_DIR/wajub-money-twice-b-ps$MAJOR.html" &
second=$!
wait "$first" "$second" || true
rm -f "$bo2_jar"
expect "posted twice at once: Wajub asked once" "$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")" "$((before + 1))"
refunds="$(shop_checks refunds "$ORDER" | tail -1)"
expect "one slip more, which records it" "$(json 'str(len(d["slips"])) + "/" + str(sum(1 for r in d["refunds"] if r["id_order_slip"] and str(r["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"])))' <<<"$refunds")" "3/1"

section "A refund the shop never heard of, read when Wajub refuses the next"
# Made in the Wajub Dashboard, its webhook never delivered (an endpoint down
# past Wajub's retries, a `wajub listen` stopped): Wajub refuses the next
# refund as more than the payment can refund. The module reads the payment's
# refunds, and records the one it missed on a credit slip of its own.
CU_ORDER="$(place_hosted_order 1 3)"
CU_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$CU_ORDER" | tail -1)")"
stub_set "$CU_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$CU_PAYMENT" payment.succeeded >/dev/null
expect "an order of three paid" "$(json 'd["state"]' <<<"$(shop_checks order "$CU_ORDER" | tail -1)")" "$PAID"
CU_PAID="$(json 'str(int(float(d.get("amount_paid") or d["amount"])))' <<<"$(stub_payment "$CU_PAYMENT")")"
OUTSIDE=$((CU_PAID - 500))
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary "{\"transaction\": \"$CU_PAYMENT\", \"amount\": $OUTSIDE, \"status\": \"succeeded\"}" "$STUB/__stub/refunds"
after="$(partial_refund "$CU_ORDER" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-caught-up-ps$MAJOR.html"
contains "Wajub refuses it as more than it can refund, and PrestaShop says why" "$after" 'Wajub did not refund it: Refund amount exceeds refundable amount'
contains "and that the refund the shop missed is recorded now" "$after" 'of this order that the shop had not recorded'
lacks "the HTTP status said once, without a stand-in code" "$after" 'http_422'
refunds="$(shop_checks refunds "$CU_ORDER" | tail -1)"
expect "one slip, the module's, of the refund the shop missed" "$(json 'str(len(d["slips"])) + "/" + str(int(float(d["slips"][-1]["amount"]))) + "/" + str(d["slips"][-1]["order_slip_type"])' <<<"$refunds")" "1/$OUTSIDE/2"
expect "no product refunded by PrestaShop" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT IFNULL(SUM(product_quantity_refunded), 0) FROM ${DB_PREFIX}order_detail WHERE id_order = $CU_ORDER")" 0
contains "with its note" "$(shop_checks order "$CU_ORDER" | tail -1)" 'made in the Wajub Dashboard, is recorded on the order as a credit slip'
after="$(partial_refund "$CU_ORDER" 500)"
lacks "asked again for what Wajub still holds: refunded" "$after" 'Wajub did not refund it|Wajub can refund at most'
expect "and recorded on PrestaShop's slip" "$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$CU_ORDER" | tail -1)")" 2

section "Standard refund and Return products"
# Product returns on: "Standard refund" while the order is not delivered,
# "Return products" once it is. Each refunded through Wajub for what
# PrestaShop's slip refunds, before PrestaShop makes it, the slip recording it.
expect "product returns turned on" "$(shop_checks returns 1 | tail -1)" 1
RET_ORDER="$(place_hosted_order 1 3)"
RET_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$RET_ORDER" | tail -1)")"
stub_set "$RET_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$RET_PAYMENT" payment.succeeded >/dev/null
expect "an order of three paid" "$(json 'd["state"]' <<<"$(shop_checks order "$RET_ORDER" | tail -1)")" "$PAID"
detail="$(shop_checks order-detail-ids "$RET_ORDER" | tail -1)"
# The refund `$1` of one product, the stand-in's refunds and the order's slips
# compared after it: Wajub asked once more, for what the one slip more refunds.
one_product_refund() {
    local before_items before_slips page made refunds
    before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
    before_slips="$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$RET_ORDER" | tail -1)")"
    page="$(refund_as "$bo_jar" "$BO_TOKEN" "$RET_ORDER" "$1" --data-urlencode "cancel_product[selected_$detail]=1" --data-urlencode "cancel_product[quantity_$detail]=1")"
    printf '%s' "$page" > "$LOG_DIR/wajub-money-$1-ps$MAJOR.html"
    made="$(curl -s "$STUB/__stub/refunds")"
    refunds="$(shop_checks refunds "$RET_ORDER" | tail -1)"
    expect "$1: Wajub asked once, from the order's payment" "$(json 'str(len(d["items"])) + "/" + d["items"][-1]["transaction"]' <<<"$made")" "$((before_items + 1))/$RET_PAYMENT"
    expect "$1: PrestaShop made its slip" "$(json 'len(d["slips"])' <<<"$refunds")" "$((before_slips + 1))"
    expect "$1: Wajub refunded what the slip refunds" "$(json 'str(round(float(d["slips"][-1]["total_products_tax_incl"]) + float(d["slips"][-1]["total_shipping_tax_incl"])))' <<<"$refunds")" "$(json 'str(round(float(d["items"][-1]["amount"])))' <<<"$made")"
    expect "$1: the slip records the refund" "$(json 'str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"]) and d["refunds"][-1]["refund_id"] == "'"$(json 'd["items"][-1]["id"]' <<<"$made")"'"' <<<"$refunds")" True
}
one_product_refund standard-refund
expect "the order delivered" "$(shop_checks deliver "$RET_ORDER" | tail -1)" delivered
one_product_refund return-product

section "Another module's hook fails before the module's"
# PrestaShop adds the slip, then runs actionOrderSlipAdd, which stops at the
# failing module, and says the refund failed: the module never hears of the
# slip. At the end of the request, that slip records what Wajub refunded:
# never a second slip of the same refund.
shop_checks failing-hook write >/dev/null
FAILING=1
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubfailinghook >/dev/null 2>&1 || true
expect "a module whose actionOrderSlipAdd fails runs first" "$(shop_checks failing-hook first | tail -1)" first
# Signed in again: the forms of a session from before a module was
# installed fail PrestaShop 9's CSRF check.
BO_TOKEN="$(bo_login "$bo_jar")"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
before_slips="$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$RET_ORDER" | tail -1)")"
after="$(partial_refund "$RET_ORDER" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-failing-hook-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$RET_ORDER" | tail -1)"
expect "Wajub asked once" "$(json 'len(d["items"])' <<<"$made")" "$((before_items + 1))"
expect "PrestaShop's slip only, no second slip" "$(json 'len(d["slips"])' <<<"$refunds")" "$((before_slips + 1))"
expect "which records the refund" "$(json 'str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"]) and int(float(d["slips"][-1]["total_products_tax_incl"])) == 1000' <<<"$refunds")" True
# PrestaShop swallows another module's Exception once debug mode is off
# (Hook::callHookOn()): the module's hook runs after it, and records the
# refund on the slip itself. In debug mode, the hook stops at it, and the
# end of the request records it, telling the merchant to check the
# quantities PrestaShop may not have written (run-refusal.sh has the
# production side, which the stand-in for Wajub cannot reach).
if [ "$(shop_checks mode | tail -1)" = debug ]; then
    contains "debug mode: the merchant is told to check the refunded quantities" "$(shop_checks order "$RET_ORDER" | tail -1)" 'without the module being told'
else
    lacks "production: the module's own hook recorded it, nothing to check" "$(shop_checks order "$RET_ORDER" | tail -1)" 'without the module being told'
fi
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed

# Wajub's webhook of the newest refund the stand-in made for `$1`, as Wajub
# sends it a little later: its HTTP status.
deliver_refund_of() {
    local made
    made="$(curl -s "$STUB/__stub/refunds")"
    post "{\"id\":\"evt_test_money_rf_$(date +%s%N)\",\"type\":\"refund.succeeded\",\"data\":{\"id\":\"$(json 'd["items"][-1]["id"]' <<<"$made")\",\"transaction\":\"$1\",\"amount\":$(json 'int(float(d["items"][-1]["amount"]))' <<<"$made"),\"currency\":\"XAF\",\"status\":\"succeeded\",\"sandbox\":true,\"metadata\":{}}}" | tail -1
}

section "Another module's hook fails with a PHP Error after the slip"
# A PHP Error (a method called on null), which neither PrestaShop's hooks
# nor Symfony's kernel catch, and which nothing turns into an answer (as
# when another module's error reporting took over from Symfony's): PHP ends
# the request there, before the module's actionOrderSlipAdd and before any
# end of request (kernel.terminate). The slip, as PrestaShop added it
# (actionObjectOrderSlipAddAfter), records the refund all the same, and the
# merchant is told to check the quantities PrestaShop did not write;
# Wajub's webhook then records nothing more.
ERR_ORDER="$(place_hosted_order 1 3)"
ERR_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$ERR_ORDER" | tail -1)")"
stub_set "$ERR_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$ERR_PAYMENT" payment.succeeded >/dev/null
expect "an order of three paid" "$(json 'd["state"]' <<<"$(shop_checks order "$ERR_ORDER" | tail -1)")" "$PAID"
shop_checks failing-hook write fatal >/dev/null
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubfailinghook >/dev/null 2>&1 || true
expect "a module whose actionOrderSlipAdd ends the request with a PHP Error runs first" "$(shop_checks failing-hook first | tail -1)" first
BO_TOKEN="$(bo_login "$bo_jar")"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
detail="$(shop_checks order-detail-ids "$ERR_ORDER" | tail -1)"
answer="$(refund_as "$bo_jar" "$BO_TOKEN" "$ERR_ORDER" partial-refund --data-urlencode "cancel_product[quantity_$detail]=1" --data-urlencode "cancel_product[amount_$detail]=1000" --data-urlencode "cancel_product[shipping_amount]=0" -w '\n%{http_code}')"
printf '%s' "$answer" > "$LOG_DIR/wajub-money-error-hook-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$ERR_ORDER" | tail -1)"
expect "Wajub asked once" "$(json 'len(d["items"])' <<<"$made")" "$((before_items + 1))"
expect "the request ended with PHP's fatal error, nothing to answer" "$(tail -n 1 <<<"$answer")" 500
expect "PrestaShop's slip only, which records the refund" "$(json 'str(len(d["slips"])) + "/" + str(str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"]))' <<<"$refunds")" "1/True"
contains "the merchant is told to check the refunded quantities" "$(shop_checks order "$ERR_ORDER" | tail -1)" 'without the module being told'
expect "the refund's webhook is accepted (the order's lock went with the request)" "$(deliver_refund_of "$ERR_PAYMENT")" 200
refunds="$(shop_checks refunds "$ERR_ORDER" | tail -1)"
expect "its webhook: no second slip, the refund on PrestaShop's" "$(json 'str(len(d["slips"])) + "/" + str(str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"])) + "/" + d["refunds"][-1]["status"]' <<<"$refunds")" "1/True/succeeded"
lacks "never taken for money refunded without Wajub" "$(shop_checks order "$ERR_ORDER" | tail -1)" 'without Wajub'
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed

section "Another module's hook fails with a PHP Error before the slip"
# actionProductCancel, which PrestaShop runs before it adds the slip, ends
# the request the same way: Wajub refunded, PrestaShop made no slip, and no
# end of request records it. Wajub's webhook does, once it says the refund
# went through: one slip, the module's.
shop_checks failing-hook write cancel-fatal >/dev/null
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubfailinghook >/dev/null 2>&1 || true
expect "a module whose actionProductCancel ends the request with a PHP Error runs first" "$(shop_checks failing-hook first | tail -1)" first
BO_TOKEN="$(bo_login "$bo_jar")"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
partial_refund_as "$bo_jar" "$BO_TOKEN" "$ERR_ORDER" 1000 >"$LOG_DIR/wajub-money-cancel-error-ps$MAJOR.html"
expect "Wajub asked once" "$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")" "$((before_items + 1))"
expect "no slip more yet: PrestaShop made none, and no end of request ran" "$(json 'str(len(d["slips"]))' <<<"$(shop_checks refunds "$ERR_ORDER" | tail -1)")" 1
expect "the refund's webhook is accepted" "$(deliver_refund_of "$ERR_PAYMENT")" 200
refunds="$(shop_checks refunds "$ERR_ORDER" | tail -1)"
expect "one slip more, the module's, which records the refund" "$(json 'str(len(d["slips"])) + "/" + str(str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"])) + "/" + str(int(float(d["slips"][-1]["amount"])))' <<<"$refunds")" "2/True/1000"
order="$(shop_checks order "$ERR_ORDER" | tail -1)"
contains "the merchant reads why the module made it" "$order" 'went through without a credit slip of it'
lacks "never taken for money refunded without Wajub" "$order" 'without Wajub'
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed
FAILING=""
BO_TOKEN="$(bo_login "$bo_jar")"
expect "product returns turned off again" "$(shop_checks returns 0 | tail -1)" 0

section "A refund Wajub refuses after a part went through"
# The order paid by two payments, the newest the stand-in's, the other one
# Wajub has not (it refuses its refund): Wajub refunds the newest, then
# refuses the rest. What it refunded is recorded on a slip of its own, and
# PrestaShop makes no slip of the refund asked: the refusal travels in the
# form's data (Refund\RefusedRefund), in debug mode as in production.
PART2_ORDER="$(place_hosted_order 1)"
PART2_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$PART2_ORDER" | tail -1)")"
PART2_TOTAL="$(json 'int(d["total"])' <<<"$(shop_checks order "$PART2_ORDER" | tail -1)")"
stub_set "$PART2_PAYMENT" '{"status": "succeeded", "amount_paid": 6000, "channel": "cm.mtn"}'
deliver_payment "$PART2_PAYMENT" payment.succeeded >/dev/null
GONE="trx_test_gone_$(date +%s)"
expect "an older payment counted for the rest" "$(shop_checks add-payments "$PART2_ORDER" "[{\"id\": \"$GONE\", \"counted\": $((PART2_TOTAL - 6000)), \"environment\": \"sandbox\", \"made_at\": $(($(date +%s) - 86400))}]" paid | tail -1)" counted
expect "the order paid" "$(json 'd["state"]' <<<"$(shop_checks order "$PART2_ORDER" | tail -1)")" "$PAID"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
after="$(partial_refund "$PART2_ORDER" 7000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-partial-refusal-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$PART2_ORDER" | tail -1)"
expect "Wajub refunded the newest payment, 6 000" "$(json 'str(len(d["items"])) + "/" + d["items"][-1]["transaction"] + "/" + str(int(float(d["items"][-1]["amount"])))' <<<"$made")" "$((before_items + 1))/$PART2_PAYMENT/6000"
expect "one slip, the module's, of what Wajub refunded" "$(json 'str(len(d["slips"])) + "/" + str(int(float(d["slips"][0]["amount"]))) + "/" + str(d["slips"][0]["order_slip_type"])' <<<"$refunds")" "1/6000/2"
contains "the merchant reads what went through, and why the rest did not" "$(bo_notice <<<"$after")" 'Wajub refunded'
lacks "never as an unexpected error" "$after" 'An unexpected error occurred|CoreException'
expect "no product refunded by PrestaShop" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT SUM(product_quantity_refunded) FROM ${DB_PREFIX}order_detail WHERE id_order = $PART2_ORDER")" 0
again="$(partial_refund "$PART2_ORDER" 7000)"
expect "asked again: nothing refunded twice" "$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")" "$((before_items + 1))"
expect "and no slip" "$(json 'len(d["slips"])' <<<"$(shop_checks refunds "$PART2_ORDER" | tail -1)")" 1
contains "the merchant reads why" "$(bo_notice <<<"$again")" 'Wajub'

section "An order the cart split in two"
# Products 1 and 2 shipped by two carriers each alone: PrestaShop makes the
# cart two orders under one reference. The module reads them as one, by the
# first: one Wajub payment for both, "Pay the rest" from either, a refund of
# either from their payments.
expect "products 1 and 2 shipped by two carriers" "$(shop_checks split-carriers on | tail -1)" split
add_product 1
add_product 2
SPLIT_IDS="$(checkout_hosted)"
SPLIT_FIRST="${SPLIT_IDS%%,*}"
SPLIT_SECOND="${SPLIT_IDS##*,}"
expect "the cart became two orders" "$(tr ',' '\n' <<<"$SPLIT_IDS" | grep -c .)" 2
group="$(shop_checks group "$SPLIT_SECOND" | tail -1)"
expect "read as one, by the first" "$(json 'str(d["lead"]) + "/" + ",".join(str(i) for i in d["ids"])' <<<"$group")" "$SPLIT_FIRST/$SPLIT_IDS"
SPLIT_TOTAL="$(json 'int(d["total"])' <<<"$group")"
SPLIT_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$SPLIT_FIRST" | tail -1)")"
expect "one Wajub payment, of both orders' total" "$(json 'str(int(float(d["amount"])))' <<<"$(stub_payment "$SPLIT_PAYMENT")")" "$SPLIT_TOTAL"
expect "held by the first order" "$(json 'len(d["wajub"])' <<<"$(shop_checks order "$SPLIT_SECOND" | tail -1)")" 0
stub_set "$SPLIT_PAYMENT" '{"status": "succeeded", "amount_paid": 5000, "channel": "cm.mtn"}'
expect "part of it paid: its webhook is accepted" "$(deliver_payment "$SPLIT_PAYMENT" payment.succeeded)" 200
expect "both orders \"Partially paid with Wajub\"" "$(json 'd["state"]' <<<"$(shop_checks order "$SPLIT_FIRST" | tail -1)")/$(json 'd["state"]' <<<"$(shop_checks order "$SPLIT_SECOND" | tail -1)")" "$PARTIAL/$PARTIAL"
SPLIT_REF="$(json 'd["reference"]' <<<"$group")"
detail="$(order_detail "$SPLIT_SECOND")"
contains "the second order's page offers to pay the rest" "$detail" 'Pay the rest'
contains "saying what is left is for both orders of the reference" "$detail" "for all your orders of reference $SPLIT_REF together"
contains "the back office's panel of either order says its amounts are those of both" "$(order_page "$SPLIT_SECOND")" "Orders $SPLIT_FIRST, $SPLIT_SECOND (reference $SPLIT_REF) are one cart shipped in several packages"
lacks "an order of its own says nothing of the kind" "$(order_page "$ORDER")" 'are one cart shipped in several packages'
SPLIT_PAY_URL="$(grep -oE 'action="[^"]*(controller=pay|/pay)[^"]*"' <<<"$detail" | head -1 | sed 's/^action="//; s/"$//; s/&amp;/\&/g' || true)"
SPLIT_PAY_TOKEN="$(grep -A2 'wajub-order-pay__form' <<<"$detail" | grep -oE 'name="token" value="[^"]+"' | sed 's/.*value="//; s/"$//' || true)"
where="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -d "token=$SPLIT_PAY_TOKEN" "$SPLIT_PAY_URL")"
expect "to Wajub's page" "${where%% *}" 302
SPLIT_REST="$(json 'd["wajub"][-1]["payment_id"]' <<<"$(shop_checks order "$SPLIT_FIRST" | tail -1)")"
rest="$(stub_payment "$SPLIT_REST")"
expect "a payment of the group, for what both orders lack" "$(json 'str(int(float(d["amount"]))) + "/" + str(d["metadata"]["order_id"])' <<<"$rest")" "$((SPLIT_TOTAL - 5000))/$SPLIT_FIRST"
stub_set "$SPLIT_REST" '{"status": "succeeded", "channel": "cm.orange"}'
expect "the rest's webhook is accepted" "$(deliver_payment "$SPLIT_REST" payment.succeeded)" 200
expect "both orders paid" "$(json 'd["state"]' <<<"$(shop_checks order "$SPLIT_FIRST" | tail -1)")/$(json 'd["state"]' <<<"$(shop_checks order "$SPLIT_SECOND" | tail -1)")" "$PAID/$PAID"
expect "two PrestaShop payments of the reference, which both orders show" "$(json 'len(d["payments"])' <<<"$(shop_checks order "$SPLIT_SECOND" | tail -1)")" 2
page="$(order_page "$SPLIT_SECOND")"
contains "the second order's page has the group's Wajub panel" "$page" "$SPLIT_REST"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
after="$(partial_refund "$SPLIT_SECOND" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-split-refund-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
expect "a refund of the second order: refunded through Wajub, from the group's newest payment" "$(json 'str(len(d["items"])) + "/" + d["items"][-1]["transaction"] + "/" + str(int(float(d["items"][-1]["amount"])))' <<<"$made")" "$((before_items + 1))/$SPLIT_REST/1000"
second_slips="$(shop_checks refunds "$SPLIT_SECOND" | tail -1)"
first_refunds="$(shop_checks refunds "$SPLIT_FIRST" | tail -1)"
expect "PrestaShop's slip on the second order records it" "$(json 'len(d["slips"])' <<<"$second_slips")/$(json 'str(d["refunds"][-1]["id_order_slip"])' <<<"$first_refunds")" "1/$(json 'str(d["slips"][-1]["id_order_slip"])' <<<"$second_slips")"
lacks "never a slip without Wajub" "$(shop_checks order "$SPLIT_FIRST" | tail -1)" 'No Wajub payment counts toward this order'
# The rest refunded in the Wajub Dashboard: one slip per order, each order's
# slips up to its own total, never all on the first.
SPLIT_T1="$(json 'int(round(d["total"]))' <<<"$(shop_checks order "$SPLIT_FIRST" | tail -1)")"
SPLIT_T2="$(json 'int(round(d["total"]))' <<<"$(shop_checks order "$SPLIT_SECOND" | tail -1)")"
for refund in "$SPLIT_PAYMENT:5000" "$SPLIT_REST:$((SPLIT_TOTAL - 6000))"; do
    EVENT="evt_test_money_split_refund_$(date +%s%N)"
    answer="$(post "{\"id\":\"$EVENT\",\"type\":\"refund.succeeded\",\"data\":{\"id\":\"rfd_dashboard_$EVENT\",\"transaction\":\"${refund%%:*}\",\"amount\":${refund##*:},\"currency\":\"XAF\",\"status\":\"succeeded\",\"sandbox\":true,\"metadata\":{}}}")"
    expect "a refund of ${refund##*:} made in the Wajub Dashboard is accepted" "${answer##*$'\n'}" 200
done
slips_given() { json 'str(int(round(sum((float(s["total_products_tax_incl"]) if float(s["total_products_tax_incl"]) > 0 else float(s["amount"])) + float(s["total_shipping_tax_incl"]) for s in d["slips"]))))' <<<"$(shop_checks refunds "$1" | tail -1)"; }
expect "each order's slips refund its own total, the first's and the second's" "$(slips_given "$SPLIT_FIRST")/$(slips_given "$SPLIT_SECOND")" "$SPLIT_T1/$SPLIT_T2"
lacks "nothing said to be refunded already" "$(shop_checks order "$SPLIT_FIRST" | tail -1)" 'already records as refunded|beyond'
expect "every carrier again" "$(shop_checks split-carriers off | tail -1)" 'every carrier'

section "A customer group shown prices without tax"
# The merchant types a refund without tax; PrestaShop's slip adds the tax
# back and says the customer gets that: Wajub refunds it, tax included.
expect "the demo customer's group shown prices without tax" "$(shop_checks tax-display excl | tail -1)" excl
TAX_ORDER="$(place_hosted_order 1)"
TAX_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$TAX_ORDER" | tail -1)")"
stub_set "$TAX_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$TAX_PAYMENT" payment.succeeded >/dev/null
expect "an order paid" "$(json 'd["state"]' <<<"$(shop_checks order "$TAX_ORDER" | tail -1)")" "$PAID"
before_items="$(json 'len(d["items"])' <<<"$(curl -s "$STUB/__stub/refunds")")"
after="$(partial_refund "$TAX_ORDER" 5000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-tax-excl-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$TAX_ORDER" | tail -1)"
expect "Wajub asked once" "$(json 'len(d["items"])' <<<"$made")" "$((before_items + 1))"
expect "for what the slip says the customer gets back, tax included" "$(json 'str(round(float(d["items"][-1]["amount"])))' <<<"$made")" "$(json 'str(round(float(d["slips"][-1]["total_products_tax_incl"]) + float(d["slips"][-1]["total_shipping_tax_incl"])))' <<<"$refunds")"
expect "more than the 5 000 typed without tax" "$(json 'str(float(d["items"][-1]["amount"]) > 5000)' <<<"$made")" True
expect "the slip records it" "$(json 'str(d["refunds"][-1]["id_order_slip"]) == str(d["slips"][-1]["id_order_slip"])' <<<"$refunds")" True
expect "the group shown prices with tax again" "$(shop_checks tax-display incl | tail -1)" incl
# The same with tax: what is typed is what the slip gives back.
INCL_ORDER="$(place_hosted_order 1)"
INCL_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$INCL_ORDER" | tail -1)")"
stub_set "$INCL_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$INCL_PAYMENT" payment.succeeded >/dev/null
partial_refund "$INCL_ORDER" 5000 >/dev/null
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$INCL_ORDER" | tail -1)"
expect "with tax: 5 000, what the slip gives back" "$(json 'str(round(float(d["items"][-1]["amount"])))' <<<"$made")/$(json 'str(round(float(d["slips"][-1]["total_products_tax_incl"]) + float(d["slips"][-1]["total_shipping_tax_incl"])))' <<<"$refunds")" "5000/5000"

section "A voucher taken off a refund"
# "Product prices, excluding the voucher", the forms' choice by default:
# PrestaShop takes the order's voucher off the refund, which Wajub refunds.
# Prices shown with tax, PrestaShop takes it off the slip's products without
# tax only, and its order page lists the slip for the products: the module
# counts what the slip gave back, and a refund of the rest made in the
# Wajub Dashboard is recorded in full.
CODE="$(shop_checks voucher make 2000 | tail -1)"
add_product 1
curl -s -c "$jar" -b "$jar" -o /dev/null -d "token=$STATIC_TOKEN&addDiscount=1&discount_name=$CODE" "$FO/cart"
VOUCHER_ORDER="$(checkout_hosted)"
VOUCHER_ORDER="${VOUCHER_ORDER%%,*}"
expect "an order with the 2 000 voucher" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT ROUND(total_discounts) FROM ${DB_PREFIX}orders WHERE id_order = $VOUCHER_ORDER")" 2000
order="$(shop_checks order "$VOUCHER_ORDER" | tail -1)"
VOUCHER_TOTAL="$(json 'int(round(d["total"]))' <<<"$order")"
VOUCHER_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
stub_set "$VOUCHER_PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
deliver_payment "$VOUCHER_PAYMENT" payment.succeeded >/dev/null
expect "paid" "$(json 'd["state"]' <<<"$(shop_checks order "$VOUCHER_ORDER" | tail -1)")" "$PAID"
after="$(partial_refund "$VOUCHER_ORDER" 5000)"
printf '%s' "$after" > "$LOG_DIR/wajub-money-voucher-ps$MAJOR.html"
made="$(curl -s "$STUB/__stub/refunds")"
refunds="$(shop_checks refunds "$VOUCHER_ORDER" | tail -1)"
expect "Wajub refunds the product less the voucher, as PrestaShop computes it" "$(json 'd["items"][-1]["transaction"] + "/" + str(round(float(d["items"][-1]["amount"])))' <<<"$made")" "$VOUCHER_PAYMENT/3000"
expect "PrestaShop's slip: its products with tax not taken off" "$(json 'str(round(float(d["slips"][-1]["total_products_tax_incl"])))' <<<"$refunds")" 5000
expect "the module counts what the slip gave back" "$(json 'str(round(float(d["given_back"])))' <<<"$refunds")" 3000
EVENT="evt_test_money_voucher_rest_$(date +%s%N)"
answer="$(post "{\"id\":\"$EVENT\",\"type\":\"refund.succeeded\",\"data\":{\"id\":\"rfd_dashboard_$EVENT\",\"transaction\":\"$VOUCHER_PAYMENT\",\"amount\":$((VOUCHER_TOTAL - 3000)),\"currency\":\"XAF\",\"status\":\"succeeded\",\"sandbox\":true,\"metadata\":{}}}")"
expect "the rest refunded in the Wajub Dashboard: accepted" "${answer##*$'\n'}" 200
expect "recorded in full" "$(json 'str(round(float(d["slips"][-1]["amount"])))' <<<"$(shop_checks refunds "$VOUCHER_ORDER" | tail -1)")" "$((VOUCHER_TOTAL - 3000))"
lacks "never said to be refunded already without Wajub" "$(shop_checks order "$VOUCHER_ORDER" | tail -1)" 'already records as refunded'
shop_checks voucher remove "$CODE" >/dev/null

section "A payment read again a few minutes later"
# Its refunds unread as its webhook came (Wajub not answering): not counted,
# and due to be read again five minutes later, which the shop's next pages
# ask (Order\Recounts, controllers/front/recount.php), without the scheduled
# task.
RC_ORDER="$(place_hosted_order 1)"
RC_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$RC_ORDER" | tail -1)")"
stub_set "$RC_PAYMENT" '{"status": "succeeded", "amount_paid": 4000, "channel": "cm.mtn"}'
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary "{\"path\": \"GET /payments/$RC_PAYMENT/refunds\", \"status\": 503, \"times\": 2}" "$STUB/__stub/fail"
expect "its webhook is accepted" "$(deliver_payment "$RC_PAYMENT" payment.succeeded)" 200
state="$(shop_checks recount state | tail -1)"
expect "not counted, its refunds unread" "$(json 'str(d["uncounted"].get("'"$RC_PAYMENT"'", 0) > 0)' <<<"$state")" True
expect "due in about five minutes" "$(json 'str(abs(d["hint"] - d["uncounted"]["'"$RC_PAYMENT"'"]) == 0 and d["hint"] > '"$(($(date +%s) + 240))"')' <<<"$state")" True
home="$(curl -s "$FO/")"
lacks "before it is due, no page asks" "$home" 'views/js/recount.js'
RECOUNT="${FO}/module/wajub/recount"
expect "asked all the same: 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RECOUNT")" 204
expect "and nothing read" "$(json 'str(d["uncounted"].get("'"$RC_PAYMENT"'", 0) > 0)' <<<"$(shop_checks recount state | tail -1)")" True
shop_checks recount due "$RC_ORDER" >/dev/null
home="$(curl -s "$FO/")"
contains "once due, the storefront's pages ask" "$home" 'views/js/recount.js'
contains "at the recount address" "$home" 'wajubRecount'
contains "the back office's pages too" "$(order_page "$RC_ORDER")" 'views/js/recount.js'
expect "a link followed reads nothing" "$(curl -s -o /dev/null -w '%{http_code}' "$RECOUNT")/$(json 'str(d["uncounted"].get("'"$RC_PAYMENT"'", 0) > 0)' <<<"$(shop_checks recount state | tail -1)")" "204/True"
expect "the page's request: 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RECOUNT")" 204
order="$(shop_checks order "$RC_ORDER" | tail -1)"
expect "read again, it counts" "$(json 'str(int(float(d["wajub"][0]["counted"]))) + "/" + str(d["state"])' <<<"$order")" "4000/$PARTIAL"
contains "the merchant reads what is left" "$order" 'is left to pay'
expect "nothing more due" "$(json 'str(d["hint"])' <<<"$(shop_checks recount state | tail -1)")" 0
lacks "the pages stop asking" "$(curl -s "$FO/")" 'views/js/recount.js'
# A payment due whose order the shop no longer has (deleted by a module):
# read once, due no more, and the pages stop asking for it.
GONE_PAYMENT="$(shop_checks recount gone | tail -1)"
contains "a payment due, of an order deleted since: the pages ask" "$(curl -s "$FO/")" 'views/js/recount.js'
expect "the page's request: 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RECOUNT")" 204
expect "due no more, nothing left to ask" "$(json 'str(d["uncounted"].get("'"$GONE_PAYMENT"'", -1)) + "/" + str(d["hint"])' <<<"$(shop_checks recount state | tail -1)")" "0/0"
lacks "the pages stop asking for it" "$(curl -s "$FO/")" 'views/js/recount.js'
expect "asked again: still 204, nothing due" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$RECOUNT")/$(json 'str(d["hint"])' <<<"$(shop_checks recount state | tail -1)")" "204/0"
shop_checks recount remove "$GONE_PAYMENT" >/dev/null
# The hint is written on what the database holds, never on what the request
# read when it began: another request's time is never lost.
hint="$(shop_checks recount hint-sql | tail -1)"
expect "brought forward, never back past another request's time; moved on only while set and sooner" "$(json '"/".join(str(d[k]) for k in ("set", "lower_later_than_elsewhere", "lower_sooner", "lower_none_elsewhere", "postpone_due", "postpone_later_elsewhere", "postpone_none", "read_now"))' <<<"$hint")" "2000000000/1900000000/1800000000/1700000000/1750000000/1760000000/0/0"

section "The scheduled task"
CRON="$(shop_checks cron-url | tail -1)"
CRON="${CRON/https:/http:}"
status="$(curl -s -o /dev/null -w '%{http_code}' "${CRON%%token=*}token=forged")"
expect "a wrong token is refused" "$status" 403
LEFT_ORDER="$(place_hosted_order 2)"
LEFT_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$LEFT_ORDER" | tail -1)")"
PART_ORDER="$(place_hosted_order 1)"
PART_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$PART_ORDER" | tail -1)")"
stub_set "$PART_PAYMENT" '{"status": "partial", "amount_paid": 4000}'
deliver_payment "$PART_PAYMENT" payment.partial >/dev/null
shop_checks age-order "$LEFT_ORDER" 7200 >/dev/null
shop_checks age-order "$PART_ORDER" 7200 >/dev/null
expect "the order the payer left: its confirmation e-mail held" "$(shop_checks held "$LEFT_ORDER" | tail -1)" held
ran="$(curl -s "$CRON")"
expect "the task runs" "$(json 'd["ran"]' <<<"$ran")" True
expect "the order the payer left is cancelled" "$(json 'd["state"]' <<<"$(shop_checks order "$LEFT_ORDER" | tail -1)")" "$CANCELED"
expect "its payment cancelled with Wajub first" "$(json 'd["status"]' <<<"$(stub_payment "$LEFT_PAYMENT")")" cancelled
contains "the merchant reads why" "$(shop_checks order "$LEFT_ORDER" | tail -1)" 'This unpaid order was cancelled'
expect "its held confirmation e-mail forgotten, never to be sent" "$(shop_checks held "$LEFT_ORDER" | tail -1)" none
expect "the payment's webhook, which follows, is accepted" "$(deliver_payment "$LEFT_PAYMENT" payment.cancelled)" 200
order="$(shop_checks order "$LEFT_ORDER" | tail -1)"
contains "noted as cancelled by the shop" "$order" "Wajub payment $LEFT_PAYMENT was cancelled by the shop, before anyone paid it"
lacks "never as left by the customer" "$order" 'The customer did not complete'
expect "the order paid in instalments is kept" "$(json 'd["state"]' <<<"$(shop_checks order "$PART_ORDER" | tail -1)")" "$AWAITING"
contains "the merchant reads why" "$(shop_checks order "$PART_ORDER" | tail -1)" 'was not cancelled'

section "The merchant cancels an order"
CANCEL_ORDER="$(place_hosted_order)"
CANCEL_PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$CANCEL_ORDER" | tail -1)")"
expect "its payment open on Wajub's page" "$(json 'd["status"]' <<<"$(stub_payment "$CANCEL_PAYMENT")")" pending
expect "cancelled from its page" "$(shop_checks cancel "$CANCEL_ORDER" | tail -1)" "$(shop_checks states | tail -1 | json 'd["canceled"]')"
expect "its payment is cancelled with Wajub too: nobody pays a cancelled order" "$(json 'd["status"]' <<<"$(stub_payment "$CANCEL_PAYMENT")")" cancelled
order="$(shop_checks order "$CANCEL_ORDER" | tail -1)"
contains "the merchant reads it" "$order" "Wajub payment $CANCEL_PAYMENT, which nobody had paid, is cancelled with Wajub too"
expect "the order stays as the merchant left it" "$(json 'd["state"]' <<<"$order")" "$(shop_checks states | tail -1 | json 'd["canceled"]')"
expect "its webhook, which follows, is accepted" "$(deliver_payment "$CANCEL_PAYMENT" payment.cancelled)" 200
lacks "and never puts it on the customer" "$(shop_checks order "$CANCEL_ORDER" | tail -1)" 'The customer did not complete'

section "Check status"
stub_set "$PART_PAYMENT" '{"sandbox": false}'
page="$(order_page "$PART_ORDER")"
WTOKEN="$(grep -oE 'data-wajub-order-panel data-order="[0-9]+" data-ajax-url="[^"]*" data-token="[^"]+"' <<<"$page" | sed 's/.*data-token="//; s/"$//')"
AJAX_URL="$(grep -oE 'data-wajub-order-panel data-order="[0-9]+" data-ajax-url="[^"]*"' <<<"$page" | sed 's/.*data-ajax-url="//; s/"$//; s/&amp;/\&/g')"
answer="$(curl -s -c "$bo_jar" -b "$bo_jar" --max-time 120 -d "ajax=1&action=check_order&id_order=$PART_ORDER&wajub_token=$WTOKEN" "$AJAX_URL")"
contains "a payment Wajub has not in this environment: said" "$answer" "has no payment $PART_PAYMENT in Sandbox"
answer="$(curl -s -c "$bo_jar" -b "$bo_jar" --max-time 120 -d "ajax=1&action=check_order&id_order=$LEFT_ORDER&wajub_token=$WTOKEN" "$AJAX_URL")"
contains "a cancelled order: nothing to check" "$answer" 'This order no longer waits for its payment'
lacks "and its page offers no \"Check status\"" "$(order_page "$LEFT_ORDER")" 'data-wajub-check-status'
contains "while an order waiting for its payment does" "$(order_page "$PART_ORDER")" 'data-wajub-check-status'

stop_stub
lacks "the stub's address is gone from the shop" "$("$ENV_DIR/exec.sh" "$MAJOR" cat config/defines_custom.inc.php)" 'wajub-integration-stub'

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR money: $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR money: all checks passed"

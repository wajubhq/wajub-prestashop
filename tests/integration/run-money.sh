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
#   4. a refund made in the Wajub Dashboard (its signed webhook): a credit
#      slip of it on the order, once;
#   5. the scheduled task (controllers/front/cron.php): a wrong token refused;
#      an order the payer left cancelled, its payment cancelled with Wajub;
#      an order part paid kept;
#   6. "Check status" of a payment Wajub has not in this environment; none
#      for an order that no longer waits for its payment.
#
#     tests/integration/run-money.sh 8
#     tests/integration/run-money.sh 9
#
# Leaves the module installed from the repository, the stand-in's address
# removed from the shop's configuration.

set -euo pipefail

MAJOR="${1:?usage: run-money.sh <8|9>}"
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

if [ "$MAJOR" = 8 ]; then FO="$PS_URL/en"; else FO="$PS_URL"; fi
jar="$(mktemp -p "$LOG_DIR" wajub-money-jar.XXXXXX)"
bo_jar="$(mktemp -p "$LOG_DIR" wajub-money-bo.XXXXXX)"
STUB_PID=""
STUB_LOG="$LOG_DIR/wajub-stub-money-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-money-ps$MAJOR.port"
STUB=""
cleanup() {
    stop_stub
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
    carrier="$(grep -o 'id="delivery_option_[0-9]*" value="[0-9]*,"' <<<"$page" | head -1 | sed 's/.*value="//; s/"//')"
    if [ -n "$carrier" ]; then
        curl -s -c "$jar" -b "$jar" -o /dev/null --data-urlencode "delivery_option[$ADDRESS]=$carrier" -d "confirmDeliveryOption=1&delivery_message=" "$FO/order"
    fi
    curl -s -c "$jar" -b "$jar" "$FO/order"
}
fields_config() { python3 -c 'import re,sys,json; m=re.search(r"var wajubFields = (\{.*?\});\n", sys.stdin.read()); print(m.group(1) if m else "{}")'; }
# An order placed with "Other payment methods": the order's id; its payment
# is on Wajub's page (the stand-in's).
place_hosted_order() {
    local page config cart token i
    for i in $(seq 1 "${2:-1}"); do
        add_product "${1:-1}"
    done
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
for hook in actionBeforeUpdateCancelProductFormHandler actionOrderSlipAdd actionCronJob displayOrderDetail; do
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
# An employee's session in `$1` (a cookie jar): the token its order pages
# take (PrestaShop 9: the session's own; 8: the employee's).
bo_login() {
    local jar="$1" login_page token landing
    login_page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 "$PS_ADMIN_URL")"
    if [ "$MAJOR" = 8 ]; then
        curl -sL -c "$jar" -b "$jar" --max-time 120 -o /dev/null --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" -d submitLogin=1 "${PS_ADMIN_URL}index.php?controller=AdminLogin"
        php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; echo Tools::getAdminToken("AdminOrders" . (int) Tab::getIdFromClassName("AdminOrders") . "1");' | tail -1
    else
        token="$(grep -oE 'id="_token" name="_token" value="[^"]+"' <<<"$login_page" | sed 's/.*value="//; s/"$//')"
        landing="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -o /dev/null -w '%{url_effective}' --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" --data-urlencode "_token=$token" -d submit_login= "${PS_ADMIN_URL}login")"
        grep -oE '[?&]_token=[^&]+' <<<"$landing" | head -1 | sed 's/.*_token=//'
    fi
}
# The order page `$3` in the session `$1` whose token is `$2`.
order_page_as() {
    if [ "$MAJOR" = 8 ]; then
        curl -sL -c "$1" -b "$1" --max-time 120 "${PS_ADMIN_URL}index.php?controller=AdminOrders&id_order=$3&vieworder=1&token=$2"
    else
        curl -sL -c "$1" -b "$1" --max-time 120 "${PS_ADMIN_URL}index.php/sell/orders/$3/view?_token=$2"
    fi
}
BO_TOKEN="$(bo_login "$bo_jar")"
order_page() { order_page_as "$bo_jar" "$BO_TOKEN" "$1"; }
page="$(order_page "$ORDER")"
printf '%s' "$page" > "$LOG_DIR/wajub-money-order-ps$MAJOR.html"
contains "the order page has the Wajub panel" "$page" 'data-wajub-order-panel'
contains "listing both payments" "$page" "$SECOND"
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
# The refund forms post the same form, to one route each, which the order
# page's script builds with the page's route token: `$4` in the session `$1`
# whose token is `$2`, of order `$3`, then the form's fields.
refund_as() {
    local jar="$1" token="$2" id="$3" route="$4" page form_token route_token
    shift 4
    page="$(order_page_as "$jar" "$token" "$id")"
    form_token="$(grep -oE 'name="cancel_product\[_token\]"[^>]*value="[^"]+"' <<<"$page" | head -1 | sed 's/.*value="//; s/"$//' || true)"
    route_token="$(grep -oE "/sell/orders/$id/[a-z-]+\?_token=[A-Za-z0-9_.-]+" <<<"$page" | head -1 | sed 's/.*_token=//' || true)"
    curl -sL -c "$jar" -b "$jar" --max-time 120 "$@" \
        --data-urlencode "cancel_product[credit_slip]=1" \
        --data-urlencode "cancel_product[voucher_refund_type]=1" \
        --data-urlencode "cancel_product[_token]=$form_token" \
        "${PS_ADMIN_URL}index.php/sell/orders/$id/$route?_token=$route_token"
}
# A partial refund of one of the order's first product, for `$2`.
partial_refund_as() {
    local detail
    detail="$(shop_checks order-detail-ids "$3" | tail -1)"
    refund_as "$1" "$2" "$3" partial-refund \
        --data-urlencode "cancel_product[quantity_$detail]=1" \
        --data-urlencode "cancel_product[amount_$detail]=$4" \
        --data-urlencode "cancel_product[shipping_amount]=0"
}
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
contains "the merchant is told to check the refunded quantities" "$(shop_checks order "$RET_ORDER" | tail -1)" 'without the module being told'
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed
BO_TOKEN="$(bo_login "$bo_jar")"
expect "product returns turned off again" "$(shop_checks returns 0 | tail -1)" 0

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
ran="$(curl -s "$CRON")"
expect "the task runs" "$(json 'd["ran"]' <<<"$ran")" True
expect "the order the payer left is cancelled" "$(json 'd["state"]' <<<"$(shop_checks order "$LEFT_ORDER" | tail -1)")" "$CANCELED"
expect "its payment cancelled with Wajub first" "$(json 'd["status"]' <<<"$(stub_payment "$LEFT_PAYMENT")")" cancelled
contains "the merchant reads why" "$(shop_checks order "$LEFT_ORDER" | tail -1)" 'This unpaid order was cancelled'
expect "the order paid in instalments is kept" "$(json 'd["state"]' <<<"$(shop_checks order "$PART_ORDER" | tail -1)")" "$AWAITING"
contains "the merchant reads why" "$(shop_checks order "$PART_ORDER" | tail -1)" 'was not cancelled'

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

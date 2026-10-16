#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# Payments on a local shop (of the environment WAJUB_PS_ENV names),
# against the stand-in for Wajub's API (tests/integration/api-stub.py),
# which also serves the stand-in Wajub.js the checkout loads: the demo
# customer goes through PrestaShop's checkout with curl, and pays.
#
#   1. the payment step lists Mobile Money, Card and Other payment methods,
#      as the account offers them, with the form's scripts and no key;
#   2. the form's payment, prepared for the cart (once, a token needed);
#   3. "Place order" with the form: the order created in "Awaiting Wajub
#      payment", its confirmation e-mail held, the payment activated for it
#      (the same body again for a double click, one order only), followed
#      until paid, then the order paid with one PrestaShop payment named by
#      the Wajub id, its confirmation e-mail released, and its confirmation
#      page;
#   4. Other payment methods: the payer sent to Wajub's page (restricted to
#      what Mobile Money and cards do not cover), the order paid by the
#      signed webhook, once, whatever is delivered again;
#   5. back from Wajub's page, cancelled: the order cancelled, its cart back;
#   6. "Use another payment method" while the form's payment is open: the
#      payment cancelled with Wajub, the order cancelled, the cart back, no
#      confirmation e-mail;
#   6b. a second tab places the same cart with the Card form: the Mobile
#      Money payment cancelled first, the card paid on Wajub's page; placed
#      again while that one is being paid: nothing new;
#   7. the order page's Wajub panel in the back office, and "Check status";
#   8. a payment the account does not offer (cards): its option hidden, and
#      Other payment methods placed from a page drawn before: no order.
#
#     tests/integration/run-payment.sh 8
#     tests/integration/run-payment.sh 9
#
# Leaves the module installed from the repository, the stand-in's address
# removed from the shop's configuration.

set -euo pipefail

MAJOR="${1:?usage: run-payment.sh <81|8|9>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DIR="${WAJUB_PS_ENV:-$HOME/.cache/wajub-prestashop/env}"
source "$ENV_DIR/lib.sh"
ps_select "$MAJOR"

failures=0
ok() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got: $2, expected: $3)"; fi; }
contains() { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 (no \"$3\" in: ${2:0:300})"; fi; }
lacks() { if grep -qiE -- "$3" <<<"$2"; then fail "$1 (\"$(grep -oiE -- "$3" <<<"$2" | head -1)\")"; else ok "$1"; fi; }
section() { printf '\n== %s\n' "$1"; }
php_in_shop() { "$ENV_DIR/exec.sh" "$MAJOR" php -r "$1"; }
json() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {"d": d}))' "$1"; }

CHECKS="wajub-checks-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }

if [ "$PS_FAMILY" = 8 ]; then FO="$PS_URL/en"; else FO="$PS_URL"; fi
jar="$(mktemp -p "$LOG_DIR" wajub-pay-jar.XXXXXX)"
bo_jar="$(mktemp -p "$LOG_DIR" wajub-pay-bo.XXXXXX)"
STUB_PID=""
STUB_LOG="$LOG_DIR/wajub-stub-pay-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-pay-ps$MAJOR.port"
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
    # The shop reaches it on the host; the browser loads its Wajub.js from localhost.
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

# --- The storefront, as the demo customer -----------------------------------------------

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
# The checkout up to its payment step: the Douala address, the carrier the
# shop selects first. Answers the payment step's page.
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
# What the payment step gives the scripts (Media::addJsDef): `var wajubFields = {…};`.
fields_config() { python3 -c 'import re,sys,json; m=re.search(r"var wajubFields = (\{.*?\});\n", sys.stdin.read()); print(m.group(1) if m else "{}")'; }
post_json() { curl -s -c "$jar" -b "$jar" -H 'Accept: application/json' "$@"; }

section "Install, the stand-in for Wajub's API, a sandbox key"
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajub >/dev/null 2>&1 || true
"$ENV_DIR/install.sh" "$MAJOR" >/dev/null || failures=$((failures + 1))
start_stub
SECRET="whsec_test_payments_$(date +%s)"
WEBHOOK_URL="$(shop_checks payment-setup "$SECRET" | tail -1)"
WEBHOOK_URL="${WEBHOOK_URL/https:/http:}"
STATES="$(shop_checks states | tail -1)"
AWAITING="$(json 'd["awaiting"]' <<<"$STATES")"
PAID="$(json 'd["paid"]' <<<"$STATES")"
CANCELED="$(json 'd["canceled"]' <<<"$STATES")"
echo "  states: $STATES, webhook: $WEBHOOK_URL"

section "The payment step"
login_customer
add_product 1
page="$(payment_step)"
printf '%s' "$page" > "$LOG_DIR/wajub-pay-step-ps$MAJOR.html"
contains "the checkout reaches its payment step" "$page" 'js-current-step'
contains "the payment step is the current one" "$(grep -A2 'checkout-payment-step' <<<"$page" | tr '\n' ' ')" 'js-current-step'
contains "Mobile Money is offered, with its form" "$page" 'data-wajub-form="mobile_money"'
contains "Card is offered, with its form" "$page" 'data-wajub-form="card"'
contains "Other payment methods is offered (the account has PayPal)" "$page" 'Other payment methods'
contains "the logos beside each option: one picture, a file PrestaShop's img/tmp serves" "$page" '/img/tmp/wajub-logos-'
lacks "never as data: written in the page" "$page" 'data:image/svg+xml'
STRIP="$(grep -oE 'src="[^"]*/img/tmp/wajub-logos-[0-9a-f]{20}\.svg"' <<<"$page" | head -1 | sed 's/^src="//; s/"$//')"
strip="$(curl -s --max-time 60 -w '\n%{http_code} %{content_type}' "$STRIP")"
contains "the picture is served, as SVG" "$(tail -1 <<<"$strip")" '200 image/svg+xml'
contains "it names its brands" "$strip" '<title>MTN Mobile Money, Orange Money</title>'
lacks "and loads nothing else" "$strip" 'href="http'
contains "the form's script" "$page" 'views/js/checkout-fields.js'
contains "Wajub.js (the stand-in, in debug mode)" "$page" "/wajub.js"
contains "and its configuration" "$page" 'var wajubFields'
lacks "no key in the page" "$page" 'sk_test\.integration'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
grep -oiE '<b>(notice|warning|deprecated)</b>.{0,400}' <<<"$page" | head -3 || true
CONFIG="$(fields_config <<<"$page")"
PREPARE="$(json 'd["prepareUrl"]' <<<"$CONFIG")"
VALIDATION="$(json 'd["validationUrl"]' <<<"$CONFIG")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
expect "the scripts get the invoice country" "$(json 'd["country"]' <<<"$CONFIG")" CM
expect "and Mobile Money's form in French? no: in the page's language" "$(json 'd["methods"]["mobile_money"]["locale"]' <<<"$CONFIG")" en
options="$(grep -c '"GET", "path": "/payments/options' "$STUB_LOG" || true)"
[ "$options" -ge 1 ] && ok "the account's options asked ($options time(s))" || fail "the account's options asked"

section "The form's payment, prepared for the cart"
answer="$(post_json -d "kind=mobile_money&token=$TOKEN&renew=0" "$PREPARE")"
PAYMENT="$(json 'd["data"]["paymentId"]' <<<"$answer")"
contains "the box gets a payment and its session" "$answer" '"sessionId":"sess_stub_'
lacks "and nothing else of it" "$answer" 'psf_|reference|sk_test'
again="$(post_json -d "kind=mobile_money&token=$TOKEN&renew=0" "$PREPARE")"
expect "the same cart again: the same payment" "$(json 'd["data"]["paymentId"]' <<<"$again")" "$PAYMENT"
expect "prepared once" "$(grep -c '"method": "POST", "path": "/payments"' "$STUB_LOG")" 1
prepared="$(stub_payment "$PAYMENT")"
expect "deferred, restricted to Mobile Money's types" "$(json 'd["accept"]' <<<"$prepared")" "{'types': ['mobile_money', 'mobile']}"
status="$(curl -s -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" -d "kind=mobile_money&token=forged" "$PREPARE")"
expect "a forged token is refused" "$status" 403

section "Place order with the form"
answer="$(post_json -d "ajax=1&kind=mobile_money&token=$TOKEN&cart=$CART&wajub_prepared=$PAYMENT" "$VALIDATION")"
contains "the order is placed and the form's payment activated" "$answer" '"fields":"1"'
ORDER="$(json 'd["data"]["orderId"]' <<<"$answer")"
STATUS_URL="$(json 'd["data"]["statusUrl"]' <<<"$answer")"
RETURN_URL="$(json 'd["data"]["returnUrl"]' <<<"$answer")"
expect "with the same payment" "$(json 'd["data"]["paymentId"]' <<<"$answer")" "$PAYMENT"
order="$(shop_checks order "$ORDER" | tail -1)"
expect "the order waits in \"Awaiting Wajub payment\"" "$(json 'd["state"]' <<<"$order")" "$AWAITING"
expect "placed with the module" "$(json 'd["module"]' <<<"$order")" wajub
expect "named after the option" "$(json 'd["payment"]' <<<"$order")" 'Mobile Money'
expect "its payment is the order's" "$(json 'd["wajub"][0]["payment_id"] + "/" + d["wajub"][0]["state"]' <<<"$order")" "$PAYMENT/active"
expect "its confirmation e-mail held until it is paid" "$(shop_checks held "$ORDER" | tail -1)" held
activated="$(stub_payment "$PAYMENT")"
REFERENCE="$(json 'd["reference"]' <<<"$order")"
expect "Wajub holds it for the order" "$(json 'd["metadata"]["order_id"]' <<<"$activated")" "$ORDER"
expect "named after its reference" "$(json 'd["activation"]["reference"]' <<<"$activated")" "Order #$REFERENCE"
expect "for its total" "$(json 'str(d["activation"]["amount"])' <<<"$activated")" "$(json 'str(int(d["total"]))' <<<"$order")"
expect "the payer, their phone international" "$(json 'd["activation"]["customer"]["email"] + " " + d["activation"]["customer"]["phone"]' <<<"$activated")" 'pub@prestashop.com +237670000000'
callback="$(json 'd["activation"]["callback"]' <<<"$activated")"
if grep -qE 'module/wajub/return\?|controller=return' <<<"$callback" && grep -qF "id_order=$ORDER" <<<"$callback"; then ok "the payer comes back to the order's return address"; else fail "the payer comes back to the order's return address (got: $callback)"; fi
again="$(post_json -d "ajax=1&kind=mobile_money&token=$TOKEN&cart=$CART&wajub_prepared=$PAYMENT" "$VALIDATION")"
expect "a second click: the same order" "$(json 'd["data"]["orderId"]' <<<"$again")" "$ORDER"
expect "one order for the cart" "$(shop_checks orders-of-cart "$CART" | tail -1)" "$ORDER"
expect "activated once, the same body sent again" "$(grep -c "\"path\": \"/payments/$PAYMENT/activate\"" "$STUB_LOG")" 2
status="$(curl -s -c "$jar" -b "$jar" "$STATUS_URL")"
contains "its status: pending" "$status" '"status":"pending"'
stub_set "$PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
sleep 11 # the shop keeps a pending answer ten seconds
status="$(curl -s -c "$jar" -b "$jar" "$STATUS_URL")"
contains "once Wajub says it went through: paid" "$status" '"status":"paid"'
order="$(shop_checks order "$ORDER" | tail -1)"
expect "the order is paid (Payment accepted)" "$(json 'd["state"]' <<<"$order")" "$PAID"
expect "with one payment, the Wajub id its transaction id" "$(json 'len(d["payments"])' <<<"$order")/$(json 'd["payments"][0]["transaction_id"]' <<<"$order")" "1/$PAYMENT"
expect "for what it collected" "$(json 'str(float(d["payments"][0]["amount"]))' <<<"$order")" "$(json 'str(float(d["total"]))' <<<"$order")"
contains "noted for the merchant" "$order" 'Wajub confirmed the payment (MTN Mobile Money).'
expect "its confirmation e-mail sent now" "$(shop_checks held "$ORDER" | tail -1)" none
page="$(curl -sL -c "$jar" -b "$jar" "$RETURN_URL")"
contains "the confirmation page shows the payment id" "$page" "$PAYMENT"
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
ORDER_PAID="$ORDER"

section "Other payment methods, paid by the webhook"
add_product 1
page="$(payment_step)"
CONFIG="$(fields_config <<<"$page")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
where="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' -d "kind=other&token=$TOKEN&cart=$CART" "$VALIDATION")"
expect "the payer is sent to Wajub's page" "${where%% *}" 302
contains "in their language" "$where" 'https://pay.wajub.com/'
ORDER="$(shop_checks orders-of-cart "$CART" | tail -1)"
order="$(shop_checks order "$ORDER" | tail -1)"
HOSTED="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
expect "the order waits" "$(json 'd["state"]' <<<"$order")" "$AWAITING"
expect "restricted to what Mobile Money and cards do not cover" "$(json 'd["accept"]' <<<"$(stub_payment "$HOSTED")")" "{'types': ['paypal']}"
post() {
    local body="$1" ts sig
    ts="$(date +%s)"
    sig="$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
    curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Wajub-Timestamp: $ts" -H "X-Wajub-Signature: v1=$sig" --data-binary "$body" "$WEBHOOK_URL"
}
total="$(json 'int(d["total"])' <<<"$order")"
EVENT="evt_test_pay_$(date +%s)"
BODY="{\"id\":\"$EVENT\",\"type\":\"payment.succeeded\",\"data\":{\"id\":\"$HOSTED\",\"status\":\"succeeded\",\"amount\":$total,\"amount_paid\":$total,\"currency\":\"XAF\",\"sandbox\":true,\"channel\":\"paypal\"}}"
answer="$(post "$BODY")"
expect "the signed webhook is accepted" "${answer##*$'\n'}" 200
expect "and acted on" "$(shop_checks event "$EVENT" | tail -1)" processed
order="$(shop_checks order "$ORDER" | tail -1)"
expect "it pays the order" "$(json 'd["state"]' <<<"$order")" "$PAID"
answer="$(post "${BODY/$EVENT/${EVENT}_again}")"
expect "another delivery of the same payment" "${answer##*$'\n'}" 200
order="$(shop_checks order "$ORDER" | tail -1)"
expect "counts it once" "$(json 'len(d["payments"])' <<<"$order")" 1

section "Back from Wajub's page, cancelled"
add_product 2
page="$(payment_step)"
CONFIG="$(fields_config <<<"$page")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
# A card form that could not load posts no payment: Wajub's page, cards only.
where="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code}' -d "kind=card&token=$TOKEN&cart=$CART&wajub_prepared=" "$VALIDATION")"
expect "a card form that could not load: Wajub's page" "$where" 302
ORDER="$(shop_checks orders-of-cart "$CART" | tail -1)"
order="$(shop_checks order "$ORDER" | tail -1)"
CARDPAY="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
expect "cards only" "$(json 'd["accept"]' <<<"$(stub_payment "$CARDPAY")")" "{'types': ['card']}"
CALLBACK="$(json 'd["callback"]' <<<"$(stub_payment "$CARDPAY")")"
stub_set "$CARDPAY" '{"status": "cancelled"}'
where="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' "$CALLBACK&reference=$CARDPAY")"
expect "back from Wajub's page" "${where%% *}" 302
contains "to the checkout" "$where" '/order'
order="$(shop_checks order "$ORDER" | tail -1)"
expect "the order is cancelled" "$(json 'd["state"]' <<<"$order")" "$CANCELED"
contains "noted for the merchant, why in words" "$order" 'without paying (the payment was cancelled)'
page="$(curl -s -c "$jar" -b "$jar" "$FO/order")"
CONFIG="$(fields_config <<<"$page")"
NEWCART="$(json 'd["cart"]' <<<"$CONFIG")"
[ -n "$NEWCART" ] && [ "$NEWCART" != "$CART" ] && ok "the payer has a new cart ($NEWCART)" || fail "the payer has a new cart (got: $NEWCART)"
expect "with the products of the order" "$(json 'd["products"][0]["id"]' <<<"$(shop_checks cart "$NEWCART" | tail -1)")" 2
contains "and reads why" "$page" 'You cancelled the payment.'

section "Use another payment method"
page="$(payment_step)"
CONFIG="$(fields_config <<<"$page")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
answer="$(post_json -d "kind=card&token=$TOKEN&renew=0" "$PREPARE")"
PAYMENT="$(json 'd["data"]["paymentId"]' <<<"$answer")"
answer="$(post_json -d "ajax=1&kind=card&token=$TOKEN&cart=$CART&wajub_prepared=$PAYMENT" "$VALIDATION")"
ORDER="$(json 'd["data"]["orderId"]' <<<"$answer")"
RELEASE="$(json 'd["data"]["releaseUrl"]' <<<"$answer")"
answer="$(post_json -d "ajax=1&reason=switch" "$RELEASE")"
contains "the payer leaves the payment: back to the checkout" "$answer" '"to":"checkout"'
expect "the payment is cancelled with Wajub" "$(json 'd["status"]' <<<"$(stub_payment "$PAYMENT")")" cancelled
expect "the order is cancelled" "$(json 'd["state"]' <<<"$(shop_checks order "$ORDER" | tail -1)")" "$CANCELED"
BACK="$(json 'd["data"]["url"]' <<<"$answer")"
contains "the script is sent through the module, which keeps the message for the page" "$BACK" 'notice='
page="$(curl -sL -c "$jar" -b "$jar" "$BACK")"
contains "the cart back, said so on the checkout (PrestaShop's notifications)" "$page" 'Your cart is back: choose another payment method.'
page="$(curl -sL -c "$jar" -b "$jar" "$BACK")"
lacks "said once: the address said it" "$page" 'Your cart is back: choose another payment method.'
expect "no confirmation e-mail for an order never paid: the one held is forgotten, never to be sent" "$(shop_checks held "$ORDER" | tail -1)" none
expect "never sent: the order was never paid" "$(json 'str(d["state"] in d["history"] and '"$PAID"' not in d["history"])' <<<"$(shop_checks order "$ORDER" | tail -1)")" True
contains "the merchant reads why in words" "$(shop_checks order "$ORDER" | tail -1)" 'without paying (they chose another payment method)'

section "A second tab places the same cart with another method"
page="$(payment_step)"
CONFIG="$(fields_config <<<"$page")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
MM="$(json 'd["data"]["paymentId"]' <<<"$(post_json -d "kind=mobile_money&token=$TOKEN&renew=0" "$PREPARE")")"
CARD="$(json 'd["data"]["paymentId"]' <<<"$(post_json -d "kind=card&token=$TOKEN&renew=0" "$PREPARE")")"
answer="$(post_json -d "ajax=1&kind=mobile_money&token=$TOKEN&cart=$CART&wajub_prepared=$MM" "$VALIDATION")"
ORDER="$(json 'd["data"]["orderId"]' <<<"$answer")"
contains "placed with Mobile Money in the first tab" "$answer" '"fields":"1"'
answer="$(post_json -d "ajax=1&kind=card&token=$TOKEN&cart=$CART&wajub_prepared=$CARD" "$VALIDATION")"
contains "the second tab's Card form: the card paid on Wajub's page" "$(json 'd["data"].get("redirect", "")' <<<"$answer")" 'https://pay.wajub.com/'
expect "the Mobile Money payment cancelled with Wajub first" "$(json 'd["status"]' <<<"$(stub_payment "$MM")")" cancelled
expect "the Card form's payment never activated" "$(json 'd.get("activation") is None' <<<"$(stub_payment "$CARD")")" True
order="$(shop_checks order "$ORDER" | tail -1)"
expect "one order for the cart" "$(shop_checks orders-of-cart "$CART" | tail -1)" "$ORDER"
expect "holding both payments, the card one open, on Wajub's page" "$(json '",".join(w["state"] + "/" + w["mode"] for w in d["wajub"])' <<<"$order")" "final/fields,active/hosted"
contains "noted for the merchant" "$order" 'was cancelled'
SECOND="$(json 'd["wajub"][-1]["payment_id"]' <<<"$order")"
expect "cards only" "$(json 'd["accept"]' <<<"$(stub_payment "$SECOND")")" "{'types': ['card']}"
stub_set "$SECOND" '{"status": "processing"}'
answer="$(post_json -d "ajax=1&kind=other&token=$TOKEN&cart=$CART" "$VALIDATION")"
expect "placed again while the card is being paid: the confirmation page follows it" "$(json 'd["data"]["code"]' <<<"$answer")" pending
expect "and nothing new starts" "$(json 'len(d["wajub"])' <<<"$(shop_checks order "$ORDER" | tail -1)")" 2
add_product 1

section "The order page in the back office"
login_page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$PS_ADMIN_URL")"
if [ "$PS_FAMILY" = 8 ]; then
    curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" -d submitLogin=1 "${PS_ADMIN_URL}index.php?controller=AdminLogin"
    ORDERS_TOKEN="$(php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; echo Tools::getAdminToken("AdminOrders" . (int) Tab::getIdFromClassName("AdminOrders") . "1");' | tail -1)"
    CONFIG_TOKEN="$(php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; echo Tools::getAdminToken("AdminWajubConfiguration" . (int) Tab::getIdFromClassName("AdminWajubConfiguration") . "1");' | tail -1)"
    ORDER_PAGE="${PS_ADMIN_URL}index.php?controller=AdminOrders&id_order=$ORDER_PAID&vieworder=1&token=$ORDERS_TOKEN"
else
    token="$(grep -oE 'id="_token" name="_token" value="[^"]+"' <<<"$login_page" | sed 's/.*value="//; s/"$//')"
    landing="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -w '%{url_effective}' --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" --data-urlencode "_token=$token" -d submit_login= "${PS_ADMIN_URL}login")"
    SESSION_TOKEN="$(grep -oE '[?&]_token=[^&]+' <<<"$landing" | head -1 | sed 's/.*_token=//')"
    CONFIG_TOKEN="$SESSION_TOKEN"
    ORDER_PAGE="${PS_ADMIN_URL}index.php/sell/orders/$ORDER_PAID/view?_token=$SESSION_TOKEN"
fi
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$ORDER_PAGE")"
contains "the order page has the Wajub panel" "$page" 'data-wajub-order-panel'
contains "with the order's Wajub payment" "$page" "$(json 'd["wajub"][0]["payment_id"]' <<<"$(shop_checks order "$ORDER_PAID" | tail -1)")"
contains "a link to it in the Wajub Dashboard" "$page" 'https://dashboard.wajub.com/payments/'
contains "its notes" "$page" 'Wajub confirmed the payment'
lacks "and no Check status: the order is paid" "$page" 'data-wajub-check-status'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
WTOKEN="$(grep -oE 'data-wajub-order-panel data-order="[0-9]+" data-ajax-url="[^"]*" data-token="[^"]+"' <<<"$page" | sed 's/.*data-token="//; s/"$//')"
AJAX_URL="$(grep -oE 'data-wajub-order-panel data-order="[0-9]+" data-ajax-url="[^"]*"' <<<"$page" | sed 's/.*data-ajax-url="//; s/"$//; s/&amp;/\&/g')"
answer="$(curl -s -c "$bo_jar" -b "$bo_jar" --max-time 120 -d "ajax=1&action=check_order&id_order=$ORDER_PAID&wajub_token=$WTOKEN" "$AJAX_URL")"
contains "Check status of a paid order says so" "$answer" 'This order is already paid.'
answer="$(curl -s -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -w '%{http_code}' -d "ajax=1&action=check_order&id_order=$ORDER_PAID" "$AJAX_URL")"
expect "not without the page's token" "$answer" 403

section "An option the account does not offer"
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary '{"channels": [{"id": "cm.mtn", "slug": "cm.mtn", "type": "mobile_money"}]}' "$STUB/__stub/options"
"$ENV_DIR/exec.sh" "$MAJOR" php -r 'require "config/config.inc.php"; Db::getInstance()->execute("DELETE FROM `" . _DB_PREFIX_ . "wajub_state` WHERE name LIKE \"offer%\"");' >/dev/null
page="$(payment_step)"
contains "Mobile Money still offered" "$page" 'data-wajub-form="mobile_money"'
lacks "Card hidden" "$page" 'data-wajub-form="card"'
lacks "Other payment methods hidden: nothing left besides Mobile Money" "$page" 'Other payment methods'
CONFIG="$(fields_config <<<"$page")"
CART="$(json 'd["cart"]' <<<"$CONFIG")"
TOKEN="$(json 'd["token"]' <<<"$CONFIG")"
answer="$(post_json -d "ajax=1&kind=other&token=$TOKEN&cart=$CART" "$VALIDATION")"
expect "Other payment methods placed from a page drawn before: refused" "$(json 'd["data"]["code"]' <<<"$answer")" method_unavailable
expect "before any order is placed" "$(shop_checks orders-of-cart "$CART" | tail -1)" ""

stop_stub
lacks "the stub's address is gone from the shop" "$("$ENV_DIR/exec.sh" "$MAJOR" cat config/defines_custom.inc.php)" 'wajub-integration-stub'

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR payments: $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR payments: all checks passed"

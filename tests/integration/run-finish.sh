#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# What a local shop (of the environment WAJUB_PS_ENV names) shows of the module's last part, against the stand-in for Wajub's API
# (tests/integration/api-stub.py) and a mail server that keeps what it gets
# (tests/integration/smtp-sink.py):
#
#   1. two shops, each at its own address, each with its own connection: the
#      webhook of each verifies its own signing secret only, keeps its own
#      health, and an order of the second shop is settled by a delivery that
#      reached the first shop's address; the scheduled task answers at the
#      second shop's address; its settings page says what is its own, and
#      "Use the connection saved for all shops" makes it use the shared one;
#      then, in Sandbox while all shops are in Live, its test payment's
#      delivery to all shops' address settles its order;
#   2. a key "Connect with Wajub" gave that expires within two weeks: the
#      back office's notice on every page, once (PrestaShop 9's legacy pages
#      among them), one e-mail to the shop's address, in the shop's language
#      (English, then French), never a second one;
#   3. PrestaShop's GDPR module: the customer's Wajub payments exported, then
#      erased (the payments unlinked, the held e-mails gone), and never a
#      guest's to an account opened with the guest's e-mail address;
#   4. the zip: what it holds (the Symfony services of the back office, the
#      notices' script and style), and what it leaves out (the developers'
#      part of composer.json, bitmaps of logos).
#
# Run it after run.sh, on a shop with the module installed:
#
#     tests/integration/run-finish.sh 8
#     tests/integration/run-finish.sh 9
#
# Leaves the module installed from the repository, one shop, the e-mails
# off and the stand-ins' addresses removed from the shop's configuration.

set -euo pipefail

MAJOR="${1:?usage: run-finish.sh <81|8|9>}"
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

CHECKS="wajub-checks-finish-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }
run_checks() { shop_checks "$@" || failures=$((failures + 1)); }

if [ "$PS_FAMILY" = 8 ]; then FO="$PS_URL/en"; else FO="$PS_URL"; fi
# The second shop's address: the same server, under another host name.
PORT="${PS_URL##*:}"
SECOND_HOST="127.0.0.1:$PORT"
jar="$(mktemp -p "$LOG_DIR" wajub-finish-jar.XXXXXX)"
bo_jar="$(mktemp -p "$LOG_DIR" wajub-finish-bo.XXXXXX)"
STUB_PID=""
STUB_LOG="$LOG_DIR/wajub-stub-finish-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-finish-ps$MAJOR.port"
STUB=""
SINK_PID=""
SINK_PORT_FILE="$LOG_DIR/wajub-sink-ps$MAJOR.port"
MAILS="$LOG_DIR/wajub-mails-ps$MAJOR.jsonl"
SECOND_SHOP=""
MOVED_ORDER=""
LANG_BEFORE=""
cleanup() {
    stop_stub
    if [ -n "$SINK_PID" ]; then
        kill "$SINK_PID" 2>/dev/null || true
        wait "$SINK_PID" 2>/dev/null || true
    fi
    shop_checks mail off >/dev/null 2>&1 || true
    if [ -n "$LANG_BEFORE" ]; then
        php_in_shop 'require "config/config.inc.php"; Configuration::updateValue("PS_LANG_DEFAULT", '"$LANG_BEFORE"');' >/dev/null 2>&1 || true
    fi
    if [ -n "$MOVED_ORDER" ]; then
        shop_checks order-to-shop "$MOVED_ORDER" 1 >/dev/null 2>&1 || true
    fi
    if [ -n "$SECOND_SHOP" ]; then
        shop_checks multistore-off "$SECOND_SHOP" >/dev/null 2>&1 || true
    fi
    rm -f "$jar" "$bo_jar" "$SHARE_DIR/$CHECKS" "$SINK_PORT_FILE"
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

# A delivery signed with `secret`, to `url`; prints the status, then the body.
post() {
    local url="$1" secret="$2" body="$3" ts sig
    ts="$(date +%s)"
    sig="$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$secret" -r | cut -d' ' -f1)"
    curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-Wajub-Timestamp: $ts" -H "X-Wajub-Signature: v1=$sig" --data-binary "$body" "$url"
}
event() { printf '{"id":"%s","type":"payment.processing","data":{"id":"trx_test_finish","status":"processing","sandbox":true}}' "$1"; }
status_of() { tail -1 <<<"$1"; }

# --- The storefront, as the demo customer ---------------------------------------------------

STATIC_TOKEN=""
login_customer() {
    rm -f "$jar"
    curl -s -c "$jar" -b "$jar" -o /dev/null --data-urlencode "email=pub@prestashop.com" --data-urlencode "password=123456789" -d submitLogin=1 "$FO/login"
    STATIC_TOKEN="$(curl -s -c "$jar" -b "$jar" "$FO/" | grep -o 'static_token":"[a-z0-9]*"' | head -1 | sed 's/.*:"//; s/"//')"
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
place_hosted_order() {
    local page config cart token validation
    curl -s -c "$jar" -b "$jar" -o /dev/null -H 'Accept: application/json' -d "token=$STATIC_TOKEN&id_product=1&id_customization=0&qty=1&add=1&action=update&ajax=1" "$FO/cart"
    page="$(payment_step)"
    config="$(fields_config <<<"$page")"
    cart="$(json 'd["cart"]' <<<"$config")"
    token="$(json 'd["token"]' <<<"$config")"
    validation="$(json 'd["validationUrl"]' <<<"$config")"
    curl -s -c "$jar" -b "$jar" -o /dev/null -d "kind=other&token=$token&cart=$cart" "$validation"
    shop_checks orders-of-cart "$cart" | tail -1
}

# --- The back office ------------------------------------------------------------------------

SESSION_TOKEN=""
bo_login() {
    local login_page token landing
    rm -f "$bo_jar"
    login_page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$PS_ADMIN_URL")"
    if [ "$PS_FAMILY" = 8 ]; then
        curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" -d submitLogin=1 "${PS_ADMIN_URL}index.php?controller=AdminLogin"
    else
        token="$(grep -oE 'id="_token" name="_token" value="[^"]+"' <<<"$login_page" | sed 's/.*value="//; s/"$//')"
        landing="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -w '%{url_effective}' --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" --data-urlencode "_token=$token" -d submit_login= "${PS_ADMIN_URL}login")"
        SESSION_TOKEN="$(grep -oE '[?&]_token=[^&]+' <<<"$landing" | head -1 | sed 's/.*_token=//')"
    fi
}
admin_token() {
    if [ "$PS_FAMILY" = 8 ]; then
        php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; $e = new Employee(1); echo Tools::getAdminToken("'"$1"'" . (int) Tab::getIdFromClassName("'"$1"'") . (int) $e->id);' | tail -1
    else
        echo "$SESSION_TOKEN"
    fi
}
page_url() { echo "${PS_ADMIN_URL}index.php?controller=AdminWajubConfiguration&token=$(admin_token AdminWajubConfiguration)"; }
save() {
    local form="$1" token
    shift
    token="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url)" | grep -oE 'name="wajub_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"$//')"
    curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -d "wajub_form=$form" -d "wajub_token=$token" -d submitWajubForm=1 "$@" "$(page_url)"
}

section "Install, the stand-in for Wajub's API, a sandbox key for all shops"
# Installed by run.sh; the repository's files synced, and the hooks of
# this version registered, as an upgrade script does.
"$ENV_DIR/install.sh" "$MAJOR" >/dev/null 2>&1 || "$ENV_DIR/sync.sh" "$MAJOR" >/dev/null || failures=$((failures + 1))
php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; $m = Module::getInstanceByName("wajub"); echo $m->registerHook(Wajub::HOOKS) ? "hooks registered\n" : "hooks not registered\n";' | tail -1
start_stub
ALL_SECRET="whsec_test_finish_all_$(date +%s)"
SECOND_SECRET="whsec_test_finish_two_$(date +%s)"
shop_checks payment-setup "$ALL_SECRET" >/dev/null
for hook in displayAdminAfterHeader actionAdminControllerInitAfter actionExportGDPRData actionDeleteGDPRCustomer; do
    expect "the module is on $hook" "$(shop_checks hooked "$hook" | tail -1)" yes
done

section "Two shops, each at its own address, each with its own connection"
SECOND_SHOP="$(shop_checks multistore-url-on "$SECOND_HOST" | tail -1)" || true
if ! [[ "$SECOND_SHOP" =~ ^[0-9]+$ ]]; then
    fail "a second shop at $SECOND_HOST (got: $SECOND_SHOP)"
    SECOND_SHOP=""
    exit 1
fi
ok "a second shop (#$SECOND_SHOP) at http://$SECOND_HOST/, multistore on"
"$ENV_DIR/exec.sh" "$MAJOR" sh -c 'rm -rf var/cache/dev/*.php var/cache/prod/*.php' >/dev/null 2>&1 || true
ALL_URL="$(shop_checks webhook-url all | tail -1)"
ALL_URL="${ALL_URL/https:/http:}"
SECOND_URL="$(shop_checks scope-connection "s$SECOND_SHOP" "sk_test.secondshop$(openssl rand -hex 24)" "$SECOND_SECRET" | tail -1)"
SECOND_URL="${SECOND_URL/https:/http:}"
echo "  all shops: $ALL_URL"
echo "  second shop: $SECOND_URL"
contains "all shops' webhook address names its scope" "$ALL_URL" 'scope=all'
contains "the second shop's is at its own address, naming it" "$SECOND_URL" "$SECOND_HOST"
contains "and naming its scope" "$SECOND_URL" "scope=s$SECOND_SHOP"
answer="$(post "$SECOND_URL" "$SECOND_SECRET" "$(event "evt_test_two_$(date +%s%N)")")"
expect "the second shop's address verifies a delivery signed with its own secret" "$(status_of "$answer")" 200
lacks "without a PHP notice" "$answer" 'notice|warning|deprecated|fatal'
answer="$(post "$SECOND_URL" "$ALL_SECRET" "$(event "evt_test_cross_$(date +%s%N)")")"
expect "but not one signed with all shops' secret: each endpoint has its own" "$(status_of "$answer")" 403
answer="$(post "$ALL_URL" "$ALL_SECRET" "$(event "evt_test_all_$(date +%s%N)")")"
expect "all shops' address verifies a delivery signed with their secret" "$(status_of "$answer")" 200
answer="$(post "$ALL_URL" "$SECOND_SECRET" "$(event "evt_test_cross2_$(date +%s%N)")")"
expect "and not one signed with the second shop's" "$(status_of "$answer")" 403
answer="$(post "http://$SECOND_HOST/index.php?fc=module&module=wajub&controller=webhook" "$SECOND_SECRET" "$(event "evt_test_plain_$(date +%s%N)")")"
expect "an address that names no scope: the secrets of the shop it reached" "$(status_of "$answer")" 200
expect "each scope keeps its own health: the second shop's" "$(shop_checks health "s$SECOND_SHOP" | tail -1)" receiving
expect "all shops'" "$(shop_checks health all | tail -1)" receiving

login_customer
ORDER="$(place_hosted_order)"
if [[ "$ORDER" =~ ^[0-9]+$ ]]; then
    ok "an order placed with Other payment methods (#$ORDER)"
    shop_checks order-to-shop "$ORDER" "$SECOND_SHOP" >/dev/null
    MOVED_ORDER="$ORDER"
    order="$(shop_checks order "$ORDER" | tail -1)"
    PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
    STATES="$(shop_checks states | tail -1)"
    stub_set "$PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
    data="$(stub_payment "$PAYMENT" | python3 -c 'import json,sys; p=json.load(sys.stdin); print(json.dumps({k: p[k] for k in ("id","status","amount","amount_paid","currency","sandbox","channel","metadata","reference") if k in p}))')"
    answer="$(post "$ALL_URL" "$ALL_SECRET" "{\"id\":\"evt_test_shop2_$(date +%s%N)\",\"type\":\"payment.succeeded\",\"data\":$data}")"
    expect "the payment of an order of the second shop, delivered to all shops' address, is accepted" "$(status_of "$answer")" 200
    order="$(shop_checks order "$ORDER" | tail -1)"
    expect "and pays that order, settled for its own shop" "$(json 'd["state"]' <<<"$order")" "$(json 'd["paid"]' <<<"$STATES")"
    shop_checks order-to-shop "$ORDER" 1 >/dev/null
    MOVED_ORDER=""
else
    fail "an order placed with Other payment methods (got: $ORDER)"
fi

CRON_URL="$(shop_checks cron-url | tail -1)"
CRON_URL="${CRON_URL/https:/http:}"
SECOND_CRON="$(sed -E "s#^http://[^/]+#http://$SECOND_HOST#" <<<"$CRON_URL")"
answer="$(curl -s --max-time 120 -w '\n%{http_code}' "$SECOND_CRON")"
expect "the scheduled task answers at the second shop's address too" "$(status_of "$answer")" 200
contains "and runs" "$answer" '"ran":true'

bo_login
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -w '\n%{http_code}' "$(page_url)&setShopContext=s-$SECOND_SHOP")"
expect "the settings page opens in the second shop's context" "$(status_of "$page")" 200
contains "it says the shop has its own connection" "$page" 'This shop has its own Wajub connection'
contains "which key is its own" "$page" 'Saved for: this shop'
contains "and offers the shared one back" "$page" 'Use the connection saved for all shops'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url)&wajub_tab=webhooks")"
contains "its Webhooks tab shows its own address" "$page" "scope=s$SECOND_SHOP"
page="$(save inherit)"
contains "\"Use the connection saved for all shops\": done" "$page" 'The connection saved for this shop is forgotten'
contains "the page says it uses the shared one now" "$page" 'This shop uses the Wajub connection saved for all shops.'
answer="$(post "$SECOND_URL" "$ALL_SECRET" "$(event "evt_test_inherit_$(date +%s%N)")")"
expect "its address now verifies with all shops' secret" "$(status_of "$answer")" 200
answer="$(post "$SECOND_URL" "$SECOND_SECRET" "$(event "evt_test_inherit2_$(date +%s%N)")")"
expect "and no longer with the secret it had" "$(status_of "$answer")" 403
# The second shop in Sandbox, with the keys of all shops, in Live: its test
# payment's delivery reaches all shops' sandbox endpoint, and settles it.
ORDER="$(place_hosted_order)"
if [[ "$ORDER" =~ ^[0-9]+$ ]]; then
    shop_checks order-to-shop "$ORDER" "$SECOND_SHOP" >/dev/null
    MOVED_ORDER="$ORDER"
    expect "all shops in Live" "$(shop_checks environment all live "sk.finishlive$(openssl rand -hex 32)" | tail -1)" live
    expect "the second shop in Sandbox, with all shops' keys" "$(shop_checks environment "s$SECOND_SHOP" sandbox | tail -1)" sandbox
    STATES="$(shop_checks states | tail -1)"
    order="$(shop_checks order "$ORDER" | tail -1)"
    PAYMENT="$(json 'd["wajub"][0]["payment_id"]' <<<"$order")"
    stub_set "$PAYMENT" '{"status": "succeeded", "channel": "cm.mtn"}'
    data="$(stub_payment "$PAYMENT" | python3 -c 'import json,sys; p=json.load(sys.stdin); print(json.dumps({k: p[k] for k in ("id","status","amount","amount_paid","currency","sandbox","channel","metadata","reference") if k in p}))')"
    answer="$(post "$ALL_URL" "$ALL_SECRET" "{\"id\":\"evt_test_sandbox_shop2_$(date +%s%N)\",\"type\":\"payment.succeeded\",\"data\":$data}")"
    expect "its test payment's delivery, to the address of all shops in Live, is accepted" "$(status_of "$answer")" 200
    order="$(shop_checks order "$ORDER" | tail -1)"
    expect "and pays its order: the environment is the second shop's" "$(json 'd["state"]' <<<"$order")" "$(json 'd["paid"]' <<<"$STATES")"
    shop_checks environment all sandbox none >/dev/null
    shop_checks order-to-shop "$ORDER" 1 >/dev/null
    MOVED_ORDER=""
else
    fail "an order placed with Other payment methods (got: $ORDER)"
fi
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url)&setShopContext=")"
contains "back to All shops" "$page" 'These settings are for all shops, unless a group or a shop has its own.'
expect "the second shop removed, multistore off" "$(shop_checks multistore-off "$SECOND_SHOP" | tail -1)" 'one shop: yes'
SECOND_SHOP=""

section "A key that expires: the notice, and one e-mail"
rm -f "$MAILS" "$SINK_PORT_FILE"
python3 "$ROOT/tests/integration/smtp-sink.py" "$SINK_PORT_FILE" "$MAILS" &
SINK_PID=$!
for i in $(seq 1 50); do [ -s "$SINK_PORT_FILE" ] && break; sleep 0.1; done
SINK_PORT="$(cat "$SINK_PORT_FILE" 2>/dev/null || true)"
[ -n "$SINK_PORT" ] && ok "a mail server that keeps what it gets (port $SINK_PORT)" || fail "a mail server that keeps what it gets"
shop_checks mail on "$SINK_PORT" >/dev/null
LANG_BEFORE="$(php_in_shop 'require "config/config.inc.php"; echo (int) Configuration::get("PS_LANG_DEFAULT");' | tail -1)"
expect "the key all shops pay with expires in 5 days" "$(shop_checks expiring-key 5 en | tail -1)" expiring
bo_login
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url)")"
contains "the back office says the key expires soon" "$page" 'Your Wajub key expires soon.'
contains "and when" "$page" 'The sandbox key Connect with Wajub created for this shop expires on'
contains "with the way to connect again" "$page" 'Connect with Wajub again'
if [ "$PS_FAMILY" = 8 ]; then
    other="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "${PS_ADMIN_URL}index.php?controller=AdminCustomers&token=$(admin_token AdminCustomers)")"
else
    other="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "${PS_ADMIN_URL}index.php/sell/customers/?_token=$SESSION_TOKEN")"
fi
contains "on another page of the back office too" "$other" 'Your Wajub key expires soon.'
expect "once" "$(grep -o 'Your Wajub key expires soon.' <<<"$other" | wc -l | tr -d ' ')" 1
# The dashboard: a legacy page, which PrestaShop 9 draws without
# displayAdminAfterHeader.
legacy="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "${PS_ADMIN_URL}index.php?controller=AdminDashboard&token=$(admin_token AdminDashboard)")"
contains "on the dashboard, a legacy page, too" "$legacy" 'Your Wajub key expires soon.'
expect "once" "$(grep -o 'Your Wajub key expires soon.' <<<"$legacy" | wc -l | tr -d ' ')" 1
for i in $(seq 1 30); do [ -s "$MAILS" ] && break; sleep 0.2; done
expect "one e-mail" "$(wc -l <"$MAILS" 2>/dev/null | tr -d ' ')" 1
mail="$(head -1 "$MAILS" 2>/dev/null || echo '{}')"
expect "to the shop's e-mail address" "$(json 'd["to"][0]' <<<"$mail")" 'owner@wajub-shop.test'
contains "its subject" "$(json 'd["subject"]' <<<"$mail")" 'Connect with Wajub again'
contains "it says which key stops, and when" "$mail" 'The sandbox key Connect with Wajub created for this shop expires on'
contains "where to connect again" "$mail" 'Module Manager'
contains "and how to avoid it next time" "$mail" 'No expiry'
contains "as HTML" "$(json '[p["type"] for p in d["parts"]]' <<<"$mail")" 'text/html'
lacks "never the key" "$mail" 'sk_test\.integration'
[ "$(shop_checks reminded | tail -1)" -gt 0 ] && ok "the key's origin keeps when it went" || fail "the key's origin keeps when it went"
curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null "$(page_url)"
answer="$(curl -s --max-time 120 "$CRON_URL")"
contains "the scheduled task sends none again" "$answer" '"reminded":0'
expect "still one e-mail" "$(wc -l <"$MAILS" | tr -d ' ')" 1
# A new pairing writes a new origin: the shop in French this time.
expect "a new key expiring in 3 days, the shop in French" "$(shop_checks expiring-key 3 fr | tail -1)" expiring
answer="$(curl -s --max-time 120 "$CRON_URL")"
contains "the scheduled task sends its e-mail" "$answer" '"reminded":1'
for i in $(seq 1 30); do [ "$(wc -l <"$MAILS" | tr -d ' ')" -ge 2 ] && break; sleep 0.2; done
mail="$(sed -n 2p "$MAILS" 2>/dev/null || echo '{}')"
contains "in French" "$(json 'd["subject"]' <<<"$mail")" 'Reconnectez la boutique avec Wajub'
contains "its words in French" "$mail" 'La clé de test créée par la connexion avec Wajub pour cette boutique expire le'
php_in_shop 'require "config/config.inc.php"; Configuration::updateValue("PS_LANG_DEFAULT", '"$LANG_BEFORE"');' >/dev/null
LANG_BEFORE=""
shop_checks mail off >/dev/null
kill "$SINK_PID" 2>/dev/null || true
wait "$SINK_PID" 2>/dev/null || true
SINK_PID=""
expect "the key no longer expires" "$(shop_checks expiring-key 400 | tail -1)" expiring
page="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url)")"
lacks "and the notice is gone" "$page" 'Your Wajub key expires soon'

section "PrestaShop's GDPR module: the customer's data"
run_checks gdpr pub@prestashop.com

section "The zip"
zip_dir="$LOG_DIR/wajub-zip-finish-ps$MAJOR"
rm -rf "$zip_dir"
mkdir -p "$zip_dir"
if WAJUB_BUILD_STAGE="$zip_dir/stage" "$ROOT/bin/build-zip.sh" "$zip_dir/wajub.zip" >/dev/null; then ok "the zip is built"; else fail "the zip is built"; fi
listing="$(unzip -Z1 "$zip_dir/wajub.zip")"
for path in wajub/wajub.php wajub/.htaccess wajub/config.xml wajub/logo.png wajub/LICENSE wajub/vendor/autoload.php wajub/mails/en/key_expiry.html wajub/mails/fr/key_expiry.txt wajub/translations/fr-FR/ModulesWajubAdmin.fr-FR.xlf wajub/views/js/checkout-options.js wajub/views/js/recount.js wajub/controllers/front/recount.php wajub/upgrade/index.php wajub/upgrade/upgrade-1.0.1.php wajub/config/admin/services.yml wajub/config/admin/index.php wajub/views/js/admin-notices.js wajub/views/css/admin-notices.css wajub/views/img/methods/mtn.svg wajub/composer.json; do
    if grep -qxF "$path" <<<"$listing"; then ok "it holds $path"; else fail "it holds $path"; fi
done
composer_json="$(unzip -p "$zip_dir/wajub.zip" wajub/composer.json)"
contains "its composer.json says how the classes load" "$composer_json" '"Wajub\\PrestaShop\\": "src/"'
lacks "and nothing of the developers' (require-dev, scripts naming bin/)" "$composer_json" 'require-dev|"scripts"|bin/'
lacks "every logo an SVG: no bitmap to embed" "$listing" '^wajub/views/img/methods/.*\.png$'
lacks "nothing for developers in it" "$listing" '^wajub/(tests|bin|build|i18n|node_modules|\.git|\.phpstan)/|\.md$|composer\.lock$|php-cs-fixer|phpstan|\.zip$'
lacks "no development package in vendor/" "$listing" '^wajub/vendor/(phpstan|friendsofphp|prestashop|symfony)/'
missing=""
while read -r dir; do
    grep -qxF "${dir}index.php" <<<"$listing" || missing="$missing $dir"
done < <(sed -E 's#/[^/]+$#/#' <<<"$listing" | sort -u)
[ -z "$missing" ] && ok "an index.php in every folder" || fail "an index.php in every folder (none in:$missing)"
rm -rf "$zip_dir"

stop_stub
lacks "the stub's address is gone from the shop" "$("$ENV_DIR/exec.sh" "$MAJOR" cat config/defines_custom.inc.php)" 'wajub-integration-stub'

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR finish: $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR finish: all checks passed"

#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# The module on a local shop (of the environment WAJUB_PS_ENV names): PHP
# syntax and the unit tests under the shop's PHP, install,
# what install() left and the adapters on the shop's own database, the
# webhook over HTTP (signed, unsigned, replayed, stale, empty, unreadable,
# from the other environment, in maintenance mode), who may open the
# settings page, the page and its AJAX in the back office, every form saved
# and read back (the key checked by a stand-in for Wajub's API,
# tests/integration/api-stub.py), settings saved for one shop of two
# (multistore), then uninstall (an order state an order used is kept, marked
# deleted, renamed or not; the record of the orders' Wajub payments kept),
# reinstall (that state and that record back), an install that fails undone,
# and last the zip as merchants get it: built, every PHP file of it linted,
# installed. Leaves the module installed from the zip.
#
#     tests/integration/run.sh 8
#     tests/integration/run.sh 9
#
# Both can run at once: each works on its own shop, with files of its own.
# What a run changes outside the module (the stub's address in the shop's
# config/defines_custom.inc.php, a second shop) is undone on exit, failed
# run or not.

set -euo pipefail

MAJOR="${1:?usage: run.sh <81|8|9>}"
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

# The checks file, under a name of this shop's: the share is the same
# folder for both shops, and a run ending would remove the other's.
CHECKS="wajub-checks-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }
run_checks() { shop_checks "$@" || failures=$((failures + 1)); }

STUB_PID=""
SECOND_SHOP=""
jar="$(mktemp -p "$LOG_DIR" wajub-jar.XXXXXX)"
cleanup() {
    stop_stub
    if [ -n "$SECOND_SHOP" ]; then
        shop_checks multistore-off "$SECOND_SHOP" >/dev/null 2>&1 || true
    fi
    rm -f "$jar" "$SHARE_DIR/$CHECKS"
}
trap cleanup EXIT

# The stand-in for Wajub's API, and its address in the shop: _WAJUB_API_URL_
# in config/defines_custom.inc.php, which the module reads in debug mode only.
# Written and removed by PHP as www-data, so the file keeps its owner.
STUB_LOG="$LOG_DIR/wajub-stub-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-ps$MAJOR.port"
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
    php_in_shop '$f = "config/defines_custom.inc.php"; $c = preg_replace("/^.*wajub-integration-stub.*\n/m", "", (string) file_get_contents($f)); file_put_contents($f, rtrim($c, "\n") . "\n" . "define(\"_WAJUB_API_URL_\", \"http://host.containers.internal:'"$port"'\"); // wajub-integration-stub\n");'
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

section "PHP syntax under the shop's PHP"
"$ENV_DIR/lint.sh" "$MAJOR" || failures=$((failures + 1))

section "Unit tests under the shop's PHP"
# The repository without Composer's packages: tests/bootstrap.php then
# loads the module's classes by their PSR-4 path, as PHP 8.1 sees them.
unit_dir="$(podman exec -u www-data "$WEB" mktemp -d /tmp/wajub-unit.XXXXXX)"
tar -C "$ROOT" --exclude=.git --exclude=vendor --exclude=.phpstan --exclude=build --exclude='*.zip' -cf - . \
    | podman exec -i -u www-data "$WEB" tar -C "$unit_dir" -xf -
if podman exec -u www-data -w "$unit_dir" "$WEB" sh -c 'php -v | head -1; for f in tests/run-*-tests.php; do php "$f" || exit 1; done'; then
    ok "every unit test runner passes"
else
    fail "every unit test runner passes"
fi
podman exec -u www-data "$WEB" rm -rf "$unit_dir"

section "Install"
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajub >/dev/null 2>&1 || true
"$ENV_DIR/install.sh" "$MAJOR" || failures=$((failures + 1))
run_checks installed
run_checks page-access

section "Webhook over HTTP"
SECRET="whsec_test_integration_$(date +%s)"
LIVE_SECRET="whsec_integration_live_$(date +%s)"
URL="$(shop_checks webhook-secret "$SECRET" "$LIVE_SECRET" | tail -1)" || true
URL="${URL/https:/http:}"
echo "  $URL"
post() {
    local body="$1" ts="$2" secret="$3" sig
    sig="$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$secret" -r | cut -d' ' -f1)"
    curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
        -H "X-Wajub-Timestamp: $ts" -H "X-Wajub-Signature: v1=$sig" --data-binary "$body" "$URL"
}
answer="$(curl -s --max-time 60 -o - -w '\n%{http_code}' "$URL")"
expect "GET is not allowed" "${answer##*$'\n'}" 405
EVENT="evt_test_it_$(date +%s)"
BODY="{\"id\":\"$EVENT\",\"event\":\"payment.succeeded\",\"data\":{\"id\":\"trx_test_it\",\"status\":\"succeeded\",\"sandbox\":true}}"
answer="$(curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary "$BODY" "$URL")"
expect "an unsigned delivery is refused" "${answer##*$'\n'}" 403
contains "with JSON" "$answer" '"received":false'
answer="$(curl -s --max-time 60 -o - -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary '' "$URL")"
expect "an empty delivery is refused" "${answer##*$'\n'}" 400
answer="$(post "$BODY" "$(date +%s)" whsec_test_wrong)"
expect "a delivery signed with another secret is refused" "${answer##*$'\n'}" 403
answer="$(post "$BODY" "$(( $(date +%s) - 600 ))" "$SECRET")"
expect "a delivery ten minutes old is refused" "${answer##*$'\n'}" 403
answer="$(post 'not json' "$(date +%s)" "$SECRET")"
expect "a signed delivery that is not JSON is refused" "${answer##*$'\n'}" 400
expect "nothing noted for the refused ones" "$(shop_checks event "$EVENT" | tail -1)" none
answer="$(post "$BODY" "$(date +%s)" "$SECRET")"
expect "a signed delivery is accepted" "${answer##*$'\n'}" 200
contains "and answered as received" "$answer" '"received":true'
lacks "without a PHP notice" "$answer" 'notice|warning|deprecated|fatal'
status="$(shop_checks event "$EVENT" | tail -1)" || true
if [ "$status" = processed ] || [ "$status" = ignored ]; then ok "the event is noted, once ($status)"; else fail "the event is noted, once (got: $status)"; fi
answer="$(post "$BODY" "$(date +%s)" "$SECRET")"
contains "the same event again is a duplicate" "$answer" '"duplicate":true'
ts="$(date +%s)"
sig="$(printf '%s.%s' "$ts" "${BODY/$EVENT/${EVENT}_c}" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
headers="$(curl -s --max-time 60 -o /dev/null -D - -X POST -H 'Content-Type: application/json' -H "X-Wajub-Timestamp: $ts" -H "X-Wajub-Signature: v1=$sig" --data-binary "${BODY/$EVENT/${EVENT}_c}" "$URL")"
lacks "the webhook writes no cookie" "$headers" '^set-cookie:'
contains "and is not stored by a cache" "$headers" 'no-store'
LIVE_EVENT="${EVENT}_live"
answer="$(post "{\"id\":\"$LIVE_EVENT\",\"event\":\"payment.succeeded\",\"data\":{\"id\":\"trx_live_it\",\"status\":\"succeeded\",\"sandbox\":false}}" "$(date +%s)" "$LIVE_SECRET")"
# The local shops are in debug mode, so copies of a shop.
expect "a delivery signed with the live secret is verified, but a copy of the shop in Sandbox asks for it again later" "${answer##*$'\n'}" 503
contains "for Wajub to send it again" "$answer" '"retry":true'
expect "and keeps nothing of it: the shop it is for acts on it" "$(shop_checks event "$LIVE_EVENT" | tail -1)" none
php_in_shop 'require "config/config.inc.php"; Configuration::updateValue("PS_SHOP_ENABLE", 0);' >/dev/null
answer="$(post "${BODY/$EVENT/${EVENT}_m}" "$(date +%s)" "$SECRET")"
expect "the shop in maintenance still hears from Wajub" "${answer##*$'\n'}" 200
php_in_shop 'require "config/config.inc.php"; Configuration::updateValue("PS_SHOP_ENABLE", 1);' >/dev/null
FRIENDLY_URL="$URL"
URL="${PS_URL}/index.php?fc=module&module=wajub&controller=webhook"
answer="$(post "${BODY/$EVENT/${EVENT}_plain}" "$(date +%s)" "$SECRET")"
expect "the webhook answers at its plain address too, as a shop without friendly URLs registers it" "${answer##*$'\n'}" 200
URL="$FRIENDLY_URL"

section "Back office"
admin_token() {
    if [ "$PS_FAMILY" = 8 ]; then
        # PrestaShop 8: a token per employee and page (its tab id, new at
        # each install), from the shop's cookie key.
        php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; $e = new Employee(1); echo Tools::getAdminToken("AdminWajubConfiguration" . (int) Tab::getIdFromClassName("AdminWajubConfiguration") . (int) $e->id);' | tail -1
    else
        echo "$SESSION_TOKEN"
    fi
}
login_page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 "$PS_ADMIN_URL")"
SESSION_TOKEN=""
if [ "$PS_FAMILY" = 8 ]; then
    curl -sL -c "$jar" -b "$jar" --max-time 120 -o /dev/null --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" -d submitLogin=1 "${PS_ADMIN_URL}index.php?controller=AdminLogin"
else
    token="$(grep -oE 'id="_token" name="_token" value="[^"]+"' <<<"$login_page" | sed 's/.*value="//; s/"$//')"
    landing="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -o /dev/null -w '%{url_effective}' --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "passwd=$ADMIN_PASSWORD" --data-urlencode "_token=$token" -d submit_login= "${PS_ADMIN_URL}login")"
    # PrestaShop 9: Symfony's CSRF token of the session, as `_token` in the
    # address the login lands on (its `token` there is not one).
    SESSION_TOKEN="$(grep -oE '[?&]_token=[^&]+' <<<"$landing" | head -1 | sed 's/.*_token=//')"
fi
TOKEN="$(admin_token)"
[ -n "$TOKEN" ] || fail "a back office token"
PAGE_URL="${PS_ADMIN_URL}index.php?controller=AdminWajubConfiguration&token=$TOKEN"
page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' "$PAGE_URL")"
expect "the settings page opens" "${page##*$'\n'}" 200
contains "with the module's page" "$page" 'data-wajub-admin'
contains "its four tabs" "$page" 'wajub-panel-webhooks'
contains "the Connect with Wajub panel" "$page" 'data-wajub-pairing-start="sandbox"'
contains "its scripts" "$page" 'views/js/admin-pairing.js'
contains "and their words" "$page" 'wajubPairing'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
lacks "without a key in it" "$page" 'sk_test\.integration'
WTOKEN="$(grep -oE 'name="wajub_token" value="[^"]+"' <<<"$page" | head -1 | sed 's/.*value="//; s/"$//')"
[ -n "$WTOKEN" ] && ok "the page carries its own token" || fail "the page carries its own token"
for tab in methods appearance webhooks; do
    tab_page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' "$PAGE_URL&wajub_tab=$tab")"
    expect "the $tab tab opens" "${tab_page##*$'\n'}" 200
done
contains "the Webhooks tab shows the last delivery" "$tab_page" 'Last delivery received'
contains "with its event" "$tab_page" 'payment.succeeded'
ajax="$(curl -s -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' -d ajax=1 -d action=test_connection -d "wajub_token=$WTOKEN" "$PAGE_URL&ajax=1")"
expect "Test connection answers" "${ajax##*$'\n'}" 200
contains "in JSON, with words" "$ajax" '"label"'
ajax="$(curl -s -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' -d ajax=1 -d action=test_connection "$PAGE_URL&ajax=1")"
expect "an AJAX call without the page's token is refused" "${ajax##*$'\n'}" 403
form="$(curl -s -c "$jar" -b "$jar" --max-time 120 -o /dev/null -w '%{http_code} %{redirect_url}' -d wajub_form=appearance -d form_theme=dark -d "wajub_token=$WTOKEN" -d submitWajubForm=1 "$PAGE_URL")"
expect "the Appearance form saves, then reloads the tab" "${form%% *}" 302
contains "on its tab" "$form" 'wajub_tab=appearance'
saved="$(php_in_shop 'require "config/config.inc.php"; echo Configuration::get("WAJUB_FORM_THEME");' | tail -1)"
expect "the form theme is saved" "$saved" dark
form="$(curl -s -c "$jar" -b "$jar" --max-time 120 -o /dev/null -w '%{http_code}' -d wajub_form=appearance -d form_theme=light -d submitWajubForm=1 "$PAGE_URL")"
saved="$(php_in_shop 'require "config/config.inc.php"; echo Configuration::get("WAJUB_FORM_THEME");' | tail -1)"
expect "a form without the page's token saves nothing" "$saved" dark

section "Every form saved, and read back"
# Saves a form as the page posts it, follows the redirect, and answers the
# page it lands on, with the notices of the save.
save() {
    local form="$1"
    shift
    curl -sL -c "$jar" -b "$jar" --max-time 120 -d "wajub_form=$form" -d "wajub_token=$WTOKEN" -d submitWajubForm=1 "$@" "$PAGE_URL"
}
EN="$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_lang FROM ${DB_PREFIX}lang WHERE iso_code = 'en'")"
FR="$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_lang FROM ${DB_PREFIX}lang WHERE iso_code = 'fr'")"
start_stub
KEY="sk_test.formsave$(openssl rand -hex 24)"
page="$(save connection -d environment=sandbox --data-urlencode "sandbox_key=$KEY" -d debug_log=1)"
contains "a sandbox key pasted is checked with Wajub, then saved" "$page" 'Sandbox key saved.'
contains "with the business Wajub names" "$page" 'Wajub accepted the key, for Stub Business.'
lacks "the page shows no more of the key than its end" "$page" "${KEY:16:24}"
page="$(save connection -d environment=live -d debug_log=1)"
contains "Live is refused without a live key" "$page" 'Live was not turned on: add your live secret key first.'
page="$(save connection -d environment=sandbox --data-urlencode "sandbox_key=sk.formsave$(openssl rand -hex 24)" -d debug_log=1)"
contains "a live key pasted in the Sandbox field is refused" "$page" 'This is a live key: it takes real payments.'
ajax="$(curl -s -c "$jar" -b "$jar" --max-time 120 -d ajax=1 -d action=test_connection -d "wajub_token=$WTOKEN" "$PAGE_URL&ajax=1")"
contains "Test connection: connected, to the business of the key" "$ajax" 'Stub Business'
ajax="$(curl -s -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' -d ajax=1 -d action=register_webhooks -d "wajub_token=$WTOKEN" "$PAGE_URL&ajax=1")"
expect "Register automatically answers" "${ajax##*$'\n'}" 200
contains "that a local shop is set up by hand" "$ajax" '"manual":true'
contains "with the Wajub CLI's command for its address" "$ajax" 'wajub listen --forward-to http:'
lacks "never a tunnel to expose it" "$ajax" 'tunnel'
user_agents="$(jq -r '.user_agent' "$STUB_LOG" | sort -u)" || true
if [ -n "$user_agents" ] && ! grep -qvE '^Wajub-PrestaShop/[0-9.]+ PrestaShop/[0-9.]+ PHP/[0-9.]+$' <<<"$user_agents"; then
    ok "the API is called as Wajub-PrestaShop/<module> PrestaShop/<shop> PHP/<php> ($(head -1 <<<"$user_agents"))"
else
    fail "the API is called as Wajub-PrestaShop/<module> PrestaShop/<shop> PHP/<php> (got: $user_agents)"
fi
expect "only the sandbox key ever reached the API" "$(jq -r '.key_prefix' "$STUB_LOG" | sort -u | tr '\n' ' ')" 'sk_test. '
expect "a GET carries no idempotency key" "$(jq -r 'select(.method == "GET") | .idempotency_key' "$STUB_LOG" | sort -u | tr -d '\n')" ''
page="$(save methods -d mobile_money_enabled=1 -d other_enabled=1 \
    --data-urlencode "mobile_money_title[$EN]=Pay by phone" --data-urlencode "mobile_money_title[$FR]=Payer par téléphone" \
    --data-urlencode "card_title[$EN]=" --data-urlencode "card_title[$FR]=" \
    --data-urlencode "other_description[$EN]=First line"$'\n'"Second line & more" --data-urlencode "other_description[$FR]=" \
    -d card_no_description=1 -d 'mobile_money_logos[]=orange' -d 'mobile_money_logos[]=visa' -d 'card_logos[]=visa' \
    -d mobile_money_operator=detect)"
contains "the Payment methods form saves" "$page" 'Payment methods saved.'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
WEBHOOK_SECRET="whsec_test_formsave$(openssl rand -hex 16)"
page="$(save webhooks --data-urlencode "sandbox_secret=$WEBHOOK_SECRET" --data-urlencode "live_secret=whsec_test_wrongfield$(openssl rand -hex 8)")"
contains "a sandbox signing secret pasted is saved" "$page" 'Sandbox signing secret saved.'
contains "a sandbox secret pasted in the Live field is refused" "$page" 'This is the signing secret of a sandbox webhook.'
run_checks saved-forms
FORM_EVENT="evt_test_form_$(date +%s)"
answer="$(post "{\"id\":\"$FORM_EVENT\",\"event\":\"payment.failed\",\"data\":{\"id\":\"trx_test_form\",\"status\":\"failed\",\"sandbox\":true}}" "$(date +%s)" "$WEBHOOK_SECRET")"
expect "a delivery signed with the secret pasted in the Webhooks tab is accepted" "${answer##*$'\n'}" 200
answer="$(post "${BODY/$EVENT/${EVENT}_old}" "$(date +%s)" "$SECRET")"
expect "and the secret it replaced no longer verifies" "${answer##*$'\n'}" 403
tab_page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 "$PAGE_URL&wajub_tab=webhooks")"
contains "the Webhooks tab shows that delivery" "$tab_page" 'payment.failed'
contains "a local shop: the Wajub CLI's command, ready to copy" "$tab_page" 'id="wajub-listen-command" value="wajub listen --forward-to http://localhost:'
lacks "never an endpoint to add in the Dashboard" "$tab_page" 'add an endpoint with the address above'

section "Settings for one shop of two (multistore)"
SECOND_SHOP="$(shop_checks multistore-on | tail -1)" || true
if [[ "$SECOND_SHOP" =~ ^[0-9]+$ ]]; then
    ok "a second shop (#$SECOND_SHOP), multistore on"
    page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' "$PAGE_URL&setShopContext=s-$SECOND_SHOP")"
    expect "the settings page opens in that shop's context" "${page##*$'\n'}" 200
    contains "and says whose settings they are" "$page" 'These settings are for the shop Wajub second shop.'
    contains "and that the shop uses the connection of all shops" "$page" 'This shop uses the Wajub connection saved for all shops.'
    lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
    page="$(save appearance -d form_theme=dark -d secured_line=1)"
    contains "the Appearance form saves there" "$page" 'Appearance saved.'
    page="$(save connection -d environment=sandbox --data-urlencode "sandbox_key=sk_test.secondshop$(openssl rand -hex 24)" -d debug_log=1)"
    contains "a key of its own is saved there" "$page" 'Sandbox key saved.'
    contains "the page then says the shop has its own connection" "$page" 'This shop has its own Wajub connection'
    contains "and that the key is saved for it" "$page" 'Saved for: this shop'
    contains "and its environment too, which no longer follows all shops" "$page" 'Environment saved for: this shop'
    page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 "$PAGE_URL&setShopContext=")"
    contains "the page for all shops says so" "$page" 'These settings are for all shops, unless a group or a shop has its own.'
    page="$(save appearance -d form_theme=light -d secured_line=1)"
    contains "the Appearance form saves for all shops" "$page" 'Appearance saved.'
    run_checks multistore-check "$SECOND_SHOP"
    expect "the second shop removed, multistore off" "$(shop_checks multistore-off "$SECOND_SHOP" | tail -1)" 'one shop: yes'
    SECOND_SHOP=""
else
    fail "a second shop, multistore on (got: $SECOND_SHOP)"
fi
stop_stub
lacks "the stub's address is gone from the shop" "$("$ENV_DIR/exec.sh" "$MAJOR" cat config/defines_custom.inc.php)" 'wajub-integration-stub'

section "Back office in French"
# The translation catalogues are built once per container: emptied, as an
# install from the back office does, they take the module's French.
"$ENV_DIR/exec.sh" "$MAJOR" sh -c 'rm -rf var/cache/dev var/cache/prod'
own_lang="$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_lang FROM ${DB_PREFIX}employee WHERE id_employee = 1")"
"$ENV_DIR/db.sh" "$MAJOR" -e "UPDATE ${DB_PREFIX}employee SET id_lang = $FR WHERE id_employee = 1"
page="$(curl -sL -c "$jar" -b "$jar" --max-time 300 "$PAGE_URL")"
"$ENV_DIR/db.sh" "$MAJOR" -e "UPDATE ${DB_PREFIX}employee SET id_lang = $own_lang WHERE id_employee = 1"
contains "the page in French" "$page" 'Connexion avec Wajub'
contains "its tabs in French" "$page" 'Moyens de paiement'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'

section "Uninstall, an order having used a state the merchant renamed"
USED="$(shop_checks order-history add | tail -1)" || true
RENAMED="Wajub: waiting for the payment"
shop_checks rename-state "$RENAMED" >/dev/null
shop_checks payment-record add >/dev/null
"$ENV_DIR/uninstall.sh" "$MAJOR" || failures=$((failures + 1))
run_checks uninstalled "$USED"

section "Reinstall"
"$ENV_DIR/install.sh" "$MAJOR" --no-sync || failures=$((failures + 1))
run_checks installed "$RENAMED"
reused_state() { php_in_shop 'require "config/config.inc.php"; echo Configuration::getGlobalValue("WAJUB_OS_AWAITING");' | tail -1; }
expect "the state an order used is brought back, renamed, not made twice" "$(reused_state)" "$USED"
run_checks payment-record check

section "Reinstall after an uninstall that lost the state's id, its English name changed"
# As an uninstall of an older version did, or a shop in French only, whose
# state has no English name.
shop_checks rename-state "En attente de paiement Wajub" fr >/dev/null
shop_checks rename-state "Pending, paid with Wajub" en >/dev/null
"$ENV_DIR/uninstall.sh" "$MAJOR" || failures=$((failures + 1))
shop_checks forget-state-id >/dev/null
"$ENV_DIR/install.sh" "$MAJOR" --no-sync || failures=$((failures + 1))
expect "the state is found again by its French name" "$(reused_state)" "$USED"
run_checks state-named "Pending, paid with Wajub" en "$USED"
shop_checks order-history remove >/dev/null
shop_checks payment-record remove >/dev/null

section "Uninstall, no order"
"$ENV_DIR/uninstall.sh" "$MAJOR" || failures=$((failures + 1))
run_checks uninstalled 0

section "An install that fails is undone"
run_checks install-fails

section "Install again"
"$ENV_DIR/install.sh" "$MAJOR" --no-sync || failures=$((failures + 1))
run_checks installed

section "The zip, as merchants install it"
# Built from the repository, as Addons and the direct download get it, then
# unpacked in the shop the way the back office's upload does, over the
# module synced so far: its autoloader is Composer's classmap alone.
zip_dir="$LOG_DIR/wajub-zip-ps$MAJOR"
rm -rf "$zip_dir"
mkdir -p "$zip_dir"
if WAJUB_BUILD_STAGE="$zip_dir/stage" "$ROOT/bin/build-zip.sh" "$zip_dir/wajub.zip" >/dev/null; then
    ok "the zip is built"
else
    fail "the zip is built"
fi
"$ENV_DIR/exec.sh" "$MAJOR" sh -c 'rm -rf var/wajub-zip && mkdir -p var/wajub-zip && cat > var/wajub-zip/wajub.zip' <"$zip_dir/wajub.zip"
expect "one folder at the top of the zip: wajub/" "$(php_in_shop '$z = new ZipArchive(); $z->open("var/wajub-zip/wajub.zip"); $top = []; for ($i = 0; $i < $z->numFiles; ++$i) { $top[explode("/", $z->getNameIndex($i))[0]] = true; } $z->extractTo("var/wajub-zip/x"); echo implode(",", array_keys($top));' | tail -1)" wajub
lint="$("$ENV_DIR/exec.sh" "$MAJOR" sh -c 'cd var/wajub-zip/x && total=$(find . -name "*.php" | wc -l); failed=$(find . -name "*.php" -print0 | xargs -0 -r -n 1 sh -c "php -l \"\$0\" 2>&1 || true" | grep -v "^No syntax errors" || true); [ -z "$failed" ] && echo "$total ok" || printf "%s\n" "$failed"')"
if [[ "$lint" =~ ^[0-9]+\ ok$ ]]; then ok "every PHP file of the zip, ${lint% ok} of them, parses under PHP $(php_in_shop 'echo PHP_VERSION;')"; else fail "every PHP file of the zip parses ($lint)"; fi
"$ENV_DIR/uninstall.sh" "$MAJOR" >/dev/null || failures=$((failures + 1))
"$ENV_DIR/exec.sh" "$MAJOR" sh -c 'rm -rf modules/wajub && mv var/wajub-zip/x/wajub modules/wajub && rm -rf var/wajub-zip'
"$ENV_DIR/install.sh" "$MAJOR" --no-sync || failures=$((failures + 1))
run_checks installed
URL="$(shop_checks webhook-secret "$SECRET" | tail -1)" || true
URL="${URL/https:/http:}"
answer="$(post "${BODY/$EVENT/${EVENT}_zip}" "$(date +%s)" "$SECRET")"
expect "the webhook of the zip's module answers a signed delivery" "${answer##*$'\n'}" 200
TOKEN="$(admin_token)"
PAGE_URL="${PS_ADMIN_URL}index.php?controller=AdminWajubConfiguration&token=$TOKEN"
page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 -w '\n%{http_code}' "$PAGE_URL")"
expect "its settings page opens" "${page##*$'\n'}" 200
contains "with the module's page" "$page" 'data-wajub-admin'
lacks "without a PHP error" "$page" '<b>(notice|warning|deprecated|fatal error)</b>|Fatal error:|Stack trace'
rm -rf "$zip_dir"

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR: $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR: all integration checks passed"

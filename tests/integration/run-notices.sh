#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# What the back office of a local shop (of the environment WAJUB_PS_ENV
# names) says of the module, page by page,
# PrestaShop 8 and 9, legacy and Symfony pages (Admin\Notices):
#
#   1. no key: "Finish setting up Wajub" on the dashboard, nowhere else;
#      "Hide for 7 days" hides it for the employee, and takes them back;
#   2. the shop takes test payments: "Wajub · Sandbox" in the header of every
#      page; a shop the public uses (debug mode off) still in Sandbox:
#      "Wajub is in Sandbox" where payments are looked after;
#   3. the environment chosen has no key: "Wajub takes no payments" on every
#      page, and above the tabs of the module's page, never in a page drawn
#      in a frame (Orders > Add new order);
#   4. the scheduled task that never ran, or ran hours ago: said, with its
#      address to copy; a run from its address, or from the Cron tasks
#      manager's hook, noted;
#   5. Wajub not offered at checkout: every method off, or no currency
#      ticked in Payment > Preferences;
#   6. Mobile Money and Card in Live on a shop without HTTPS: said on the
#      module's page, and in the confirmation of the switch to Live, with
#      the settings this PrestaShop has (no "SSL on all pages" on 9); with
#      SSL turned on (for one run, never saved), said when, and only when,
#      the return address PrestaShop builds is not HTTPS;
#   7. the order page's assets in the page's head, the uninstall
#      confirmation, the end of request of the refund forms (Symfony's
#      kernel.terminate) registered;
#   8. the module's .htaccess: a request for one of its PHP files refused,
#      its pages (webhook, scheduled task, recount), styles, scripts and
#      pictures served as before. (run-payment.sh, run-money.sh and
#      run-finish.sh run the checkout, the webhook, the recount and the
#      logos with it in place, run.sh the zip that ships it.)
#
# Run it on a shop with the module installed (run.sh), in debug mode, or
# in production mode (env/prod.sh <v> on) for the notice of a shop the
# public uses:
#
#     tests/integration/run-notices.sh 8
#     tests/integration/run-notices.sh 9
#
# Talks to no Wajub: the notices read what is saved. Leaves the stand-in's
# sandbox key, every method on, every currency ticked, no notice hidden.

set -euo pipefail

MAJOR="${1:?usage: run-notices.sh <81|8|9>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DIR="${WAJUB_PS_ENV:-$HOME/.cache/wajub-prestashop/env}"
source "$ENV_DIR/lib.sh"
ps_select "$MAJOR"

failures=0
ok() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (got: $2, expected: $3)"; fi; }
contains() { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else fail "$1 (no \"$3\" in: ${2:0:300})"; fi; }
lacks() { if grep -qF -- "$3" <<<"$2"; then fail "$1 (\"$3\" found)"; else ok "$1"; fi; }
count() { grep -oF -- "$2" <<<"$1" | wc -l | tr -d ' '; }
section() { printf '\n== %s\n' "$1"; }
php_in_shop() { "$ENV_DIR/exec.sh" "$MAJOR" php -r "$1"; }
json() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {"d": d}))' "$1"; }
# The page's text with its entities read (&amp; → &), as a browser shows it.
unescaped() { python3 -c 'import html,sys; print(html.unescape(sys.stdin.read()))'; }

CHECKS="wajub-checks-notices-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }

bo_jar="$(mktemp -p "$LOG_DIR" wajub-notices-bo.XXXXXX)"
cleanup() {
    shop_checks notices keys sandbox >/dev/null 2>&1 || true
    shop_checks forget-key >/dev/null 2>&1 || true
    shop_checks notices methods on >/dev/null 2>&1 || true
    shop_checks notices currencies all >/dev/null 2>&1 || true
    shop_checks notices unhide >/dev/null 2>&1 || true
    shop_checks notices task clear >/dev/null 2>&1 || true
    rm -f "$bo_jar" "$SHARE_DIR/$CHECKS"
}
trap cleanup EXIT

MODE="$(shop_checks mode | tail -1)"
echo "PrestaShop $PS_VERSION, $MODE mode"

# --- The back office -------------------------------------------------------------------------

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
# PrestaShop 8 asks for each page's own token (employee 1), worked out once.
declare -A TOKENS=()
admin_token() {
    if [ "$PS_FAMILY" != 8 ]; then
        echo "$SESSION_TOKEN"
        return
    fi
    if [ -z "${TOKENS[$1]:-}" ]; then
        TOKENS[$1]="$(php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; $e = new Employee(1); echo Tools::getAdminToken("'"$1"'" . (int) Tab::getIdFromClassName("'"$1"'") . (int) $e->id);' | tail -1)"
    fi
    echo "${TOKENS[$1]}"
}
# A page by its controller; on PrestaShop 9, a Symfony page by its path.
page_url() {
    if [ "$PS_FAMILY" = 8 ] || [ -z "${2:-}" ]; then
        echo "${PS_ADMIN_URL}index.php?controller=$1&token=$(admin_token "$1")"
    else
        echo "${PS_ADMIN_URL}index.php/$2?_token=$SESSION_TOKEN"
    fi
}
page() { curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url "$@")"; }
settings() { curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url AdminWajubConfiguration)${1:+&wajub_tab=$1}"; }
dashboard() { page AdminDashboard; }
orders() { page AdminOrders sell/orders/; }
customers() { page AdminCustomers sell/customers/; }
carriers() { page AdminCarriers; }
payment_methods() { page AdminPayment improve/payment/payment_methods; }
payment_preferences() { page AdminPaymentPreferences improve/payment/preferences; }

section "Install"
"$ENV_DIR/install.sh" "$MAJOR" >/dev/null 2>&1 || "$ENV_DIR/sync.sh" "$MAJOR" >/dev/null || failures=$((failures + 1))
php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; $m = Module::getInstanceByName("wajub"); echo $m->registerHook(Wajub::HOOKS) ? "hooks registered\n" : "hooks not registered\n";' | tail -1
shop_checks notices unhide >/dev/null
shop_checks notices task clear >/dev/null
shop_checks notices methods on >/dev/null
bo_login
[ "$PS_FAMILY" = 8 ] || [ -n "$SESSION_TOKEN" ] && ok "the employee signs in" || fail "the employee signs in"

section "No key: \"Finish setting up Wajub\""
shop_checks notices keys none >/dev/null
DASH="$(dashboard)"
contains "the dashboard says to finish setting up" "$DASH" 'Finish setting up Wajub.'
contains "with the way to" "$DASH" 'Set up Wajub'
contains "and \"Hide for 7 days\"" "$DASH" 'Hide for 7 days'
lacks "no \"Wajub · Sandbox\" without a key" "$DASH" 'data-wajub-env-badge'
lacks "not on the orders" "$(orders)" 'Finish setting up Wajub.'
lacks "nor on the module's page, where it is set up" "$(settings)" 'Finish setting up Wajub.'
HIDE="$(grep -oE 'href="[^"]*wajub_hide=setup[^"]*"' <<<"$DASH" | head -1 | sed 's/^href="//; s/"$//' | unescaped)"
DASH_URL="$(page_url AdminDashboard)"
if [ -n "$HIDE" ]; then
    curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -e "$DASH_URL" "$(sed 's/&wajub_token=[^&]*//' <<<"$HIDE")"
    expect "a link to hide it without the page's token hides nothing" "$(shop_checks notices state | tail -1 | json 'len(d["hidden"])')" 0
    landing="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -w '%{url_effective}' -e "$DASH_URL" "$HIDE")"
    contains "\"Hide for 7 days\" takes the employee back to the page they were on" "$landing" 'controller=AdminDashboard'
    state="$(shop_checks notices state | tail -1)"
    expect "hidden for a week" "$(json 'round((list(d["hidden"].values())[0]["setup"] - d["now"]) / 86400)' <<<"$state")" 7
    lacks "the dashboard no longer says it" "$(dashboard)" 'Finish setting up Wajub.'
    shop_checks notices unhide >/dev/null
    contains "shown again once no longer hidden" "$(dashboard)" 'Finish setting up Wajub.'
    for referer in "${PS_URL}//evil.example/${ADMIN_FOLDER}/index.php" "${PS_URL}/\\evil.example/${ADMIN_FOLDER}/index.php" "https://evil.example/${ADMIN_FOLDER}/index.php"; do
        location="$(curl -s -c "$bo_jar" -b "$bo_jar" --max-time 120 -o /dev/null -w '%{redirect_url}' -e "$referer" "$HIDE")"
        if grep -qF "$PS_ADMIN_URL" <<<"$location" && ! grep -qF 'evil.example' <<<"$location"; then
            ok "back to a page of this back office only, never another host's ($referer)"
        else
            fail "back to a page of this back office only, never another host's ($referer: went to \"$location\")"
        fi
    done
    shop_checks notices unhide >/dev/null
else
    fail "the notice has its \"Hide for 7 days\" link"
fi

section "Sandbox with its key: \"Wajub · Sandbox\""
shop_checks notices keys sandbox >/dev/null
DASH="$(dashboard)"
ORDERS="$(orders)"
CUSTOMERS="$(customers)"
SETTINGS="$(settings)"
for name in DASH ORDERS CUSTOMERS SETTINGS; do
    contains "\"Wajub · Sandbox\" in the header ($name)" "${!name}" 'Wajub · Sandbox'
    expect "once ($name)" "$(count "${!name}" 'data-wajub-env-badge')" 1
done
contains "it says what it means" "$DASH" 'Wajub takes test payments: no real money moves. Open the settings to go live.'
contains "it opens the settings" "$(grep -oE '<a class="wajub-env-badge" href="[^"]*"' <<<"$DASH" | head -1)" 'controller=AdminWajubConfiguration'
contains "with its style, in the page's head" "$(sed -n '1,/<body/p' <<<"$DASH")" 'views/css/admin-notices.css'
lacks "\"Finish setting up\" is gone" "$DASH" 'Finish setting up Wajub.'
if [ "$MODE" = production ]; then
    contains "a shop the public uses, in Sandbox: said on the dashboard" "$DASH" 'Wajub is in Sandbox.'
    contains "and on the orders" "$ORDERS" 'Wajub is in Sandbox.'
    contains "with the way to go live" "$ORDERS" 'Go live'
    lacks "not on a page about something else" "$(carriers)" 'Wajub is in Sandbox.'
else
    lacks "a copy in debug mode: Sandbox is right, nothing said" "$DASH" 'Wajub is in Sandbox.'
fi

section "The environment chosen has no key: \"Wajub takes no payments\""
shop_checks notices keys live-only >/dev/null
CARRIERS="$(carriers)"
CUSTOMERS="$(customers)"
METHODS="$(settings methods)"
contains "said on a legacy page (carriers)" "$CARRIERS" 'Wajub takes no payments.'
contains "and why" "$CARRIERS" 'Sandbox is selected and has no key'
contains "said on a Symfony page (customers)" "$CUSTOMERS" 'Wajub takes no payments.'
expect "once" "$(count "$CUSTOMERS" 'Wajub takes no payments.')" 1
contains "above the tabs of the module's page, whichever tab is open" "$METHODS" 'Wajub takes no payments.'
expect "once there too" "$(count "$METHODS" 'Wajub takes no payments.')" 1
lacks "no badge: no payment at all" "$CUSTOMERS" 'data-wajub-env-badge'
lacks "it cannot be hidden" "$(grep -A12 'data-wajub-notice="no_payments"' <<<"$CUSTOMERS")" 'wajub_hide='
# A page drawn small inside another (Orders > Add new order opens the
# customer form in a frame): nothing of the module's there.
if [ "$PS_FAMILY" = 8 ]; then
    LITE="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "$(page_url AdminCustomers)&addcustomer=1&liteDisplaying=1&submitFormAjax=1")"
else
    LITE="$(curl -sL -c "$bo_jar" -b "$bo_jar" --max-time 120 "${PS_ADMIN_URL}index.php/sell/customers/new?liteDisplaying=1&submitFormAjax=1&_token=$SESSION_TOKEN")"
fi
contains "the customer form drawn in a frame" "$LITE" 'name="customer[first_name]"'
lacks "says nothing of the module there" "$LITE" 'Wajub takes no payments.'
shop_checks notices keys live-missing >/dev/null
contains "Live chosen without its key: said too" "$(customers)" 'Live is selected and has no key'
# What a profile is shown: the notices to those who may change the module's
# settings, never to those who may only read them, who see the badge alone.
shop_checks notices keys live-only >/dev/null
PROFILES="$(shop_checks notices profiles | tail -1)"
expect "a profile that may only read the settings: no notice" "$(json 'd["read"]["notices"]' <<<"$PROFILES")" '[]'
expect "one that may change them: told the shop takes no payments" "$(json 'd["update"]["notices"]' <<<"$PROFILES")" "['no_payments']"
shop_checks notices keys sandbox >/dev/null
PROFILES="$(shop_checks notices profiles | tail -1)"
expect "the badge for one that may only read them" "$(json 'd["read"]["badge"]' <<<"$PROFILES")" 'True'
expect "and its rights read as such" "$(json '[d["read"]["allowed"], d["read"]["editable"], d["update"]["editable"]]' <<<"$PROFILES")" '[True, False, True]'

section "The scheduled task"
shop_checks notices keys sandbox >/dev/null
CRON_URL="$(shop_checks cron-url | tail -1)"
TOKEN="${CRON_URL##*token=}"
shop_checks notices task never >/dev/null
DASH="$(dashboard)"
contains "never ran, four hours after the module waits for it: said" "$DASH" 'scheduled task has never run.'
contains "how to run it" "$DASH" 'Cron tasks manager module'
contains "its address, to copy" "$DASH" "$TOKEN"
contains "a Copy button, which its script shows" "$DASH" 'data-wajub-notice-copy'
contains "with its script, in the page's head" "$(sed -n '1,/<body/p' <<<"$DASH")" 'views/js/admin-notices.js'
lacks "not on a page about something else" "$(carriers)" 'scheduled task has never run.'
answer="$(curl -s --max-time 120 "$CRON_URL")"
contains "the host's cron calls its address" "$answer" '"ran":true'
state="$(shop_checks notices state | tail -1)"
expect "the run is noted" "$(json 'd["now"] - d["last_run"] < 300' <<<"$state")" True
lacks "the dashboard no longer says it" "$(dashboard)" 'scheduled task'
contains "the module's page says when it last ran" "$(settings methods)" 'Last run:'
shop_checks notices task late >/dev/null
contains "five hours since: late, and since when" "$(dashboard)" 'scheduled task last ran 5 hours ago.'
answer="$(curl -s --max-time 120 -o /dev/null -w '%{http_code}' "${CRON_URL%token=*}token=wrong")"
expect "a wrong token runs nothing" "$answer" 403
expect "and notes nothing" "$(shop_checks notices state | tail -1 | json 'd["now"] - d["last_run"] > 3600')" True
php_in_shop 'define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev"); require "config/config.inc.php"; Module::getInstanceByName("wajub")->hookActionCronJob([]); echo "ran\n";' | tail -1 >/dev/null
expect "a run from the Cron tasks manager is noted too" "$(shop_checks notices state | tail -1 | json 'd["now"] - d["last_run"] < 300')" True

section "Not offered at checkout"
shop_checks notices methods off >/dev/null
SETTINGS="$(settings)"
contains "every method off: said on the module's page" "$SETTINGS" 'Wajub is not offered at checkout.'
contains "with why" "$SETTINGS" 'Mobile Money, Card and Other payment methods are all turned off.'
contains "and the way to fix it" "$SETTINGS" 'Turn them on'
contains "said on Payment > Payment methods" "$(payment_methods)" 'Wajub is not offered at checkout.'
lacks "not on the dashboard" "$(dashboard)" 'Wajub is not offered at checkout.'
shop_checks notices methods on >/dev/null
expect "no currency ticked for the module" "$(shop_checks notices currencies none | tail -1)" 0
PREFS="$(payment_preferences)"
contains "said on Payment > Preferences" "$PREFS" 'Wajub is not offered at checkout.'
contains "naming the list" "$PREFS" 'under Currency restrictions in Payment &gt; Preferences'
[ "$(shop_checks notices currencies all | tail -1)" -gt 0 ] && ok "the currencies ticked again" || fail "the currencies ticked again"
lacks "then nothing to say" "$(payment_preferences)" 'Wajub is not offered at checkout.'

section "Mobile Money and Card in Live, without HTTPS"
shop_checks notices keys live >/dev/null
CONNECTION="$(settings connection)"
expect "said on the module's page, by the environment and with the methods" "$(count "$CONNECTION" 'data-wajub-plain-http')" 2
if [ "$PS_FAMILY" = 8 ]; then
    contains "what to do: SSL, and SSL on all pages" "$CONNECTION" 'In Shop Parameters &gt; General, enable SSL, and SSL on all pages'
else
    contains "what to do: SSL, the only setting PrestaShop 9 has" "$CONNECTION" 'In Shop Parameters &gt; General, enable SSL before'
    lacks "never \"SSL on all pages\", which PrestaShop 9 dropped" "$CONNECTION" 'SSL on all pages'
fi
# SSL turned on for one run (PrestaShop's cache, not the database): the
# warning follows the scheme of the return address PrestaShop builds. On
# PrestaShop 8, SSL alone leaves it http; PrestaShop 9 reads SSL alone.
for ssl in 0,0 1,0 1,1; do
    answer="$(shop_checks notices https "$ssl" | tail -1)"
    if [ "$ssl" = 0,0 ] || { [ "$ssl" = 1,0 ] && [ "$PS_FAMILY" = 8 ]; }; then want=http/False/True; else want=https/True/False; fi
    expect "SSL and all pages $ssl: the return address, the module's reading of it, a warning" "$(json '"%s/%s/%s" % (d["return"], d["https"], d["warning"] != "")' <<<"$answer")" "$want"
done
shop_checks notices keys sandbox >/dev/null
CONNECTION="$(settings connection)"
if [ "$MODE" = production ]; then
    expect "a shop the public uses, in Sandbox: said before it goes live" "$(count "$CONNECTION" 'data-wajub-plain-http')" 2
else
    expect "a copy in debug mode, in Sandbox: nothing on the page" "$(count "$CONNECTION" 'data-wajub-plain-http')" 0
fi
contains "but said when Live is chosen" "$(grep -o 'var wajubAdmin = .*' <<<"$CONNECTION" | head -1)" 'not served over HTTPS'

section "The order page's assets, the uninstall confirmation, the end of request"
ORDERS="$(orders)"
HEAD="$(sed -n '1,/<body/p' <<<"$ORDERS")"
contains "the order panel's style in the page's head" "$HEAD" 'views/css/admin-order.css'
contains "and its script" "$ORDERS" 'views/js/admin-order.js'
contains "the uninstall confirmation names the tables it keeps" "$(shop_checks notices uninstall-text | tail -1)" 'wajub_payment, wajub_refund and wajub_order_note'
listeners="$("$ENV_DIR/exec.sh" "$MAJOR" php bin/console debug:event-dispatcher kernel.terminate 2>/dev/null || true)"
contains "the refund forms' end of request is a listener of kernel.terminate" "$listeners" 'RefundFormsEnd::onTerminate()'

section "The module's .htaccess"
contains "it is in the shop's copy of the module" "$("$ENV_DIR/exec.sh" "$MAJOR" cat modules/wajub/.htaccess)" 'Require all denied'
status() { curl -s -o /dev/null --max-time 120 -w '%{http_code}' "$@"; }
for path in wajub.php index.php controllers/front/webhook.php controllers/front/cron.php src/Services.php vendor/autoload.php upgrade/upgrade-1.0.1.php; do
    expect "a request for modules/wajub/$path is refused" "$(status "$PS_URL/modules/wajub/$path")" 403
done
expect "the webhook still answers, at its address" "$(status "$PS_URL/module/wajub/webhook")" 405
expect "and at the one without friendly URLs" "$(status "$PS_URL/index.php?fc=module&module=wajub&controller=webhook")" 405
contains "an unsigned delivery: refused by the module, not by Apache" "$(curl -s --max-time 120 -X POST -H 'Content-Type: application/json' -d '{}' "$PS_URL/module/wajub/webhook")" '"received":false'
answer="$(curl -s --max-time 120 "$CRON_URL")"
contains "the scheduled task's address runs it" "$answer" '"ran":true'
expect "the pages' recount request answers" "$(status -X POST "$PS_URL/module/wajub/recount")" 204
for path in views/css/admin-notices.css views/css/admin-order.css views/js/admin-notices.js views/js/admin-order.js views/js/recount.js views/img/methods/mtn.svg views/img/methods/orange.svg logo.png; do
    expect "modules/wajub/$path is served" "$(status "$PS_URL/modules/wajub/$path")" 200
done

printf '\n'
if [ "$failures" -eq 0 ]; then
    echo "run-notices: all checks passed"
else
    echo "run-notices: $failures check(s) failed"
fi
exit $((failures > 0 ? 1 : 0))

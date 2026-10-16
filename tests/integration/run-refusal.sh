#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# A refund of the back office's order page that Wajub, or the module, refuses
# makes no credit slip, whatever mode the shop is in: PrestaShop 8 swallows
# what a module's hook throws once debug mode is off (Hook::callHookOn()), so
# the module's refusal travels in the form's data instead
# (Refund\RefusedRefund). Run in production mode (prod.sh <v> on) and in
# debug mode, on a local shop of the environment WAJUB_PS_ENV names:
#
#   1. refused by the module, without asking Wajub: an order never paid in
#      full (Partial refund, Standard refund), an order paid in Live while
#      Wajub uses Sandbox: no slip, no product refunded, the module's message
#      as it is;
#   1b. another module on the refund forms' hook, installed after the module:
#      the module goes back to the last place, the other module reads the
#      products, and the refusal is shown, never a PHP error;
#   2. with a Sandbox key of a real Wajub account (--sandbox-key-file, the
#      Wajub CLI's config.json; the CLI makes the payments): an order paid by
#      a payment Wajub has and an older one it has not. Wajub refunds the
#      first, then refuses the rest: one slip, the module's, of what Wajub
#      refunded, none of the refund asked, and asked again, nothing refunded
#      twice (read back from Wajub);
#   3. with that key too: another module's actionOrderSlipAdd fails before the
#      module's. In production PrestaShop swallows it, and the module's hook
#      records the refund on PrestaShop's slip; in debug mode the hook stops
#      there, the end of the request records it, and the merchant is told to
#      check the refunded quantities;
#   3b. the same with a PHP Error, which nothing catches, in production as in
#      debug mode: the request ends there, and PrestaShop's slip records the
#      refund as PrestaShop added it, the merchant told to check the
#      quantities PrestaShop did not write.
#
#     env/prod.sh 8 on && tests/integration/run-refusal.sh 8 --sandbox-key-file ~/.config/wajub/config.json
#     env/prod.sh 8 off && tests/integration/run-refusal.sh 8
#
# The key is read from the file and handed to the shop on its standard
# input, saved as the connection form saves it (checked with Wajub), never
# printed, and the shop is given the integration stand-in's key back at the
# end. The payments the real account gets say "PrestaShop module test".

set -euo pipefail

MAJOR="${1:?usage: run-refusal.sh <8|9> [--sandbox-key-file <config.json>]}"
shift
KEY_FILE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --sandbox-key-file) KEY_FILE="${2:?}"; shift 2 ;;
        *) echo "unknown option $1"; exit 2 ;;
    esac
done
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

CHECKS="wajub-checks-refusal-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }
source "$ROOT/tests/integration/lib-bo.sh"

bo_jar="$(mktemp -p "$LOG_DIR" wajub-refusal-bo.XXXXXX)"
FAILING=""
KEYED=""
ARRAY=""
cleanup() {
    if [ -n "$ARRAY" ]; then
        "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubarrayhook >/dev/null 2>&1 || true
        shop_checks array-hook remove >/dev/null 2>&1 || true
    fi
    if [ -n "$FAILING" ]; then
        "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
        shop_checks failing-hook remove >/dev/null 2>&1 || true
    fi
    if [ -n "$KEYED" ]; then
        shop_checks forget-key >/dev/null 2>&1 || true
    fi
    shop_checks returns 0 >/dev/null 2>&1 || true
    rm -f "$bo_jar" "$SHARE_DIR/$CHECKS"
}
trap cleanup EXIT

slips_of() { json 'len(d["slips"])' <<<"$(shop_checks refunds "$1" | tail -1)"; }
refunded_quantity() { "$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT IFNULL(SUM(product_quantity_refunded), 0) FROM ${DB_PREFIX}order_detail WHERE id_order = $1"; }

MODE="$(shop_checks mode | tail -1)"
section "PrestaShop $PS_VERSION in $MODE mode"
if [ "$(shop_checks hooked actionBeforeUpdateCancelProductFormHandler 2>/dev/null | tail -1)" != yes ]; then
    "$ENV_DIR/install.sh" "$MAJOR" >/dev/null
fi
# The module of the repository; in production, PrestaShop reads no file
# again until its caches are cleared.
"$ENV_DIR/sync.sh" "$MAJOR" --clear-cache >/dev/null
expect "the module is on the refund forms' hook" "$(shop_checks hooked actionBeforeUpdateCancelProductFormHandler | tail -1)" yes
shop_checks payment-setup "whsec_test_refusal_$(date +%s)" >/dev/null
STATES="$(shop_checks states | tail -1)"
PAID="$(json 'd["paid"]' <<<"$STATES")"
BO_TOKEN="$(bo_login "$bo_jar")"
[ -n "$BO_TOKEN" ] && ok "the back office signs in" || fail "the back office signs in"
run="$(date +%s)"

section "Refused by the module: an order never paid in full"
ORDER_A="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"trx_test_refusal_a_$run\", \"counted\": 4000, \"environment\": \"sandbox\"}], \"paid\": false}" | tail -1)"
after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_A" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-refusal-a-$MODE-ps$MAJOR.html"
notice="$(bo_notice <<<"$after")"
contains "a partial refund: PrestaShop says why, as the module wrote it" "$notice" 'This order is not paid in full: refund its Wajub payments from the Wajub Dashboard.'
lacks "never as an unexpected error, nor a slip made" "$notice" 'An unexpected error occurred|CoreException|successfully created'
expect "no credit slip" "$(slips_of "$ORDER_A")" 0
expect "no product refunded" "$(refunded_quantity "$ORDER_A")" 0
expect "nothing asked of Wajub" "$(json 'len(d["refunds"])' <<<"$(shop_checks refunds "$ORDER_A" | tail -1)")" 0
# Standard refund (product returns on, the order not delivered).
expect "product returns on" "$(shop_checks returns 1 | tail -1)" 1
BO_TOKEN="$(bo_login "$bo_jar")"
detail="$(shop_checks order-detail-ids "$ORDER_A" | tail -1)"
after="$(refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_A" standard-refund --data-urlencode "cancel_product[selected_$detail]=1" --data-urlencode "cancel_product[quantity_$detail]=1")"
printf '%s' "$after" > "$LOG_DIR/wajub-refusal-a-standard-$MODE-ps$MAJOR.html"
contains "a standard refund: refused the same way" "$(bo_notice <<<"$after")" 'This order is not paid in full'
expect "no credit slip" "$(slips_of "$ORDER_A")" 0
expect "no product refunded" "$(refunded_quantity "$ORDER_A")" 0
expect "product returns off" "$(shop_checks returns 0 | tail -1)" 0
BO_TOKEN="$(bo_login "$bo_jar")"

section "Refused by the module: paid in Live while Wajub uses Sandbox"
ORDER_B="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"trx_refusal_b_$run\", \"counted\": \"rest\", \"environment\": \"live\"}], \"paid\": true}" | tail -1)"
expect "an order paid" "$(json 'd["state"]' <<<"$(shop_checks order "$ORDER_B" | tail -1)")" "$PAID"
after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_B" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-refusal-b-$MODE-ps$MAJOR.html"
contains "PrestaShop says why" "$(bo_notice <<<"$after")" 'This order was paid in Wajub Live'
expect "no credit slip" "$(slips_of "$ORDER_B")" 0
expect "no product refunded" "$(refunded_quantity "$ORDER_B")" 0

section "Another module on the refund forms, installed after the module"
# PrestaShop puts a module installed later after the module on the hook,
# where it would meet the refusal in place of the products to refund (an
# object its array functions refuse: a PHP error, no message). The module
# goes back to the last place: the other module reads the products, and the
# merchant reads why the refund was refused.
shop_checks array-hook write >/dev/null
ARRAY=1
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubarrayhook >/dev/null 2>&1 || true
position="$(shop_checks array-hook position | tail -1)"
expect "the module back in the last place on the hook, the other module before it" "${position%% of*}/$([ "${position##* of }" -ge 2 ] && echo shared)" last/shared
BO_TOKEN="$(bo_login "$bo_jar")"
after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_A" 1000)"
printf '%s' "$after" > "$LOG_DIR/wajub-refusal-array-$MODE-ps$MAJOR.html"
contains "refused: PrestaShop says why, as the module wrote it" "$(bo_notice <<<"$after")" 'This order is not paid in full'
lacks "never a PHP error, an unexpected error or a slip" "$after" 'An unexpected error occurred|CoreException|TypeError|must be of type array|successfully created'
contains "the other module read the products, an array" "$(shop_checks array-hook seen | tail -1)" 'array of 1:'
expect "no credit slip" "$(slips_of "$ORDER_A")" 0
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubarrayhook >/dev/null 2>&1 || true
expect "the other module removed" "$(shop_checks array-hook remove | tail -1)" removed
ARRAY=""

if [ -n "$KEY_FILE" ]; then
    section "A Sandbox key of a real Wajub account"
    answer="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["profiles"]["sandbox"]["secret_key"])' "$KEY_FILE" | "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" paste-key | tail -1)"
    KEYED=1
    expect "saved as the connection form saves it, Wajub accepting it" "$(json 'str(d["saved"])' <<<"$answer")" True
    lacks "the key appears nowhere" "$answer" 'sk_test\.[A-Za-z0-9]'
    # A real payment of the account, made by the Wajub CLI (+237670000000:
    # it succeeds, and so do its refunds): its id.
    real_payment() {
        timeout 180 wajub trigger payment.succeeded --amount "$1" -d "description=PrestaShop module test (refusal, $MODE)" 2>&1 | grep -oE 'payment trx_test_[A-Za-z0-9]+ is succeeded' | head -1 | sed 's/^payment //; s/ is succeeded$//'
    }
    # A payment the Sandbox did not say succeeded (still processing after
    # three minutes, say) stops the run, said so: what follows needs it.
    real_payment_or_stop() {
        if [ -n "$2" ]; then
            ok "a real payment of $1 ($2)"
            return
        fi
        fail "a real payment of $1: Wajub's Sandbox did not say it succeeded within three minutes, so the rest is not run"
        echo
        echo "PrestaShop $MAJOR refusals ($MODE): $failures failure(s)"
        exit 1
    }
    real_refunds() { timeout 60 wajub get "/payments/$1/refunds" --json | json 'len(d["items"]) if isinstance(d.get("items"), list) else -1'; }

    section "Refused by Wajub after a part went through"
    REAL="$(real_payment 6000)" || true
    real_payment_or_stop "6 000" "$REAL"
    GONE="trx_test_refusal_gone_$run"
    ORDER_D="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"$GONE\", \"counted\": \"rest\", \"environment\": \"sandbox\", \"made_at\": $((run - 86400))}, {\"id\": \"$REAL\", \"counted\": 6000, \"environment\": \"sandbox\", \"made_at\": $run}], \"paid\": true}" | tail -1)"
    expect "an order paid by it and by an older payment Wajub has not" "$(json 'd["state"]' <<<"$(shop_checks order "$ORDER_D" | tail -1)")" "$PAID"
    after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_D" 7000)"
    printf '%s' "$after" > "$LOG_DIR/wajub-refusal-d-$MODE-ps$MAJOR.html"
    notice="$(bo_notice <<<"$after")"
    contains "PrestaShop says what Wajub refunded, and why the rest did not go" "$notice" "Wajub refunded"
    contains "from the payment made last" "$notice" "$REAL"
    lacks "never as an unexpected error, nor PrestaShop's success" "$notice" 'An unexpected error occurred|CoreException|successfully created'
    refunds="$(shop_checks refunds "$ORDER_D" | tail -1)"
    expect "one slip, the module's, of the 6 000 Wajub refunded" "$(json 'str(len(d["slips"])) + "/" + str(int(float(d["slips"][0]["amount"]))) + "/" + str(d["slips"][0]["order_slip_type"])' <<<"$refunds")" "1/6000/2"
    expect "the refund known by its Wajub id, on that slip" "$(json 'str(d["refunds"][0]["refund_id"].startswith("rfd_")) + "/" + str(d["refunds"][0]["id_order_slip"] == d["slips"][0]["id_order_slip"])' <<<"$refunds")" "True/True"
    expect "no product refunded by PrestaShop" "$(refunded_quantity "$ORDER_D")" 0
    expect "Wajub made one refund of the payment" "$(real_refunds "$REAL")" 1
    again="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_D" 7000)"
    printf '%s' "$again" > "$LOG_DIR/wajub-refusal-d-again-$MODE-ps$MAJOR.html"
    contains "asked again: refused, PrestaShop says why" "$(bo_notice <<<"$again")" "Wajub"
    expect "no second slip" "$(slips_of "$ORDER_D")" 1
    expect "nothing refunded twice" "$(real_refunds "$REAL")" 1

    section "Another module's hook fails before the module's"
    REAL2="$(real_payment 5000)" || true
    real_payment_or_stop "5 000" "$REAL2"
    ORDER_E="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"trx_test_refusal_older_$run\", \"counted\": \"rest\", \"environment\": \"sandbox\", \"made_at\": $((run - 86400))}, {\"id\": \"$REAL2\", \"counted\": 5000, \"environment\": \"sandbox\", \"made_at\": $run}], \"paid\": true}" | tail -1)"
    shop_checks failing-hook write >/dev/null
    FAILING=1
    "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubfailinghook >/dev/null 2>&1 || true
    expect "a module whose actionOrderSlipAdd fails runs first" "$(shop_checks failing-hook first | tail -1)" first
    BO_TOKEN="$(bo_login "$bo_jar")"
    after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_E" 1000)"
    printf '%s' "$after" > "$LOG_DIR/wajub-refusal-e-$MODE-ps$MAJOR.html"
    refunds="$(shop_checks refunds "$ORDER_E" | tail -1)"
    expect "Wajub refunded 1 000 from the payment made last" "$(real_refunds "$REAL2")" 1
    expect "PrestaShop's slip only, which records it" "$(json 'str(len(d["slips"])) + "/" + str(d["refunds"][-1]["id_order_slip"] == d["slips"][-1]["id_order_slip"])' <<<"$refunds")" "1/True"
    order="$(shop_checks order "$ORDER_E" | tail -1)"
    if [ "$MODE" = production ]; then
        # The other module's Exception swallowed: the module's hook ran.
        lacks "production: nothing for the merchant to check" "$order" 'without the module being told'
        expect "production: PrestaShop refunded the product" "$(refunded_quantity "$ORDER_E")" 1
    else
        contains "debug mode: the merchant is told to check the refunded quantities" "$order" 'without the module being told'
    fi
    "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
    expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed

    section "Another module's hook throws a PHP Error before the module's"
    # Neither PrestaShop's hooks nor Symfony's kernel catch an Error: the
    # request ends there, before the module's actionOrderSlipAdd, in
    # production as in debug mode. The slip records the refund all the same,
    # as PrestaShop added it (actionObjectOrderSlipAddAfter).
    REAL3="$(real_payment 5000)" || true
    real_payment_or_stop "5 000" "$REAL3"
    ORDER_F="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"trx_test_refusal_older_f_$run\", \"counted\": \"rest\", \"environment\": \"sandbox\", \"made_at\": $((run - 86400))}, {\"id\": \"$REAL3\", \"counted\": 5000, \"environment\": \"sandbox\", \"made_at\": $run}], \"paid\": true}" | tail -1)"
    shop_checks failing-hook write error >/dev/null
    "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubfailinghook >/dev/null 2>&1 || true
    expect "a module whose actionOrderSlipAdd throws a PHP Error runs first" "$(shop_checks failing-hook first | tail -1)" first
    BO_TOKEN="$(bo_login "$bo_jar")"
    after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_F" 1000)"
    printf '%s' "$after" > "$LOG_DIR/wajub-refusal-f-$MODE-ps$MAJOR.html"
    refunds="$(shop_checks refunds "$ORDER_F" | tail -1)"
    expect "Wajub refunded 1 000 of the payment" "$(real_refunds "$REAL3")" 1
    expect "PrestaShop's slip only, which records it" "$(json 'str(len(d["slips"])) + "/" + str(bool(d["refunds"]) and d["refunds"][-1]["id_order_slip"] == d["slips"][-1]["id_order_slip"])' <<<"$refunds")" "1/True"
    expect "PrestaShop stopped before writing the quantity it refunded" "$(refunded_quantity "$ORDER_F")" 0
    contains "so the merchant is told to check the refunded quantities" "$(shop_checks order "$ORDER_F" | tail -1)" 'without the module being told'
    "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubfailinghook >/dev/null 2>&1 || true
    expect "the failing module removed" "$(shop_checks failing-hook remove | tail -1)" removed
    FAILING=""

    section "Refused by Wajub: a refund the shop never heard of is read then"
    # Refunded in full by the Wajub CLI, as from the Wajub Dashboard: its
    # webhook never reaches this shop (nothing forwards it here). The
    # refund from the order page that follows is refused by Wajub as more
    # than the payment can refund; the module reads the payment's refunds
    # and records the one it missed on a credit slip of its own.
    REAL4="$(real_payment 4000)" || true
    real_payment_or_stop "4 000" "$REAL4"
    ORDER_G="$(shop_checks inject-order "{\"products\": [[1, 1]], \"payments\": [{\"id\": \"trx_test_refusal_older_g_$run\", \"counted\": \"rest\", \"environment\": \"sandbox\", \"made_at\": $((run - 86400))}, {\"id\": \"$REAL4\", \"counted\": 4000, \"environment\": \"sandbox\", \"made_at\": $run}], \"paid\": true}" | tail -1)"
    timeout 120 wajub refunds create -y --json -d "payment=$REAL4" -d amount=4000 -d reason=requested_by_customer -d "metadata.note=PrestaShop module test (refund out of the shop's sight, $MODE)" >/dev/null 2>&1 || true
    outside=""
    for _ in $(seq 1 36); do
        outside="$(timeout 60 wajub get "/payments/$REAL4/refunds" --json 2>/dev/null | json '",".join(r["status"] for r in d.get("items", []))' 2>/dev/null || true)"
        [ "$outside" = succeeded ] && break
        sleep 5
    done
    expect "refunded out of the shop's sight: Wajub says it went through" "$outside" succeeded
    BO_TOKEN="$(bo_login "$bo_jar")"
    after="$(partial_refund_as "$bo_jar" "$BO_TOKEN" "$ORDER_G" 3000)"
    printf '%s' "$after" > "$LOG_DIR/wajub-refusal-g-$MODE-ps$MAJOR.html"
    notice="$(bo_notice <<<"$after")"
    contains "Wajub refuses it, PrestaShop says why" "$notice" 'Wajub did not refund it'
    contains "and that the refund the shop missed is recorded now" "$notice" 'of this order that the shop had not recorded'
    lacks "the HTTP status said once, never a stand-in code" "$notice" 'http_4'
    lacks "never as an unexpected error, nor PrestaShop's success" "$notice" 'An unexpected error occurred|CoreException|successfully created'
    refunds="$(shop_checks refunds "$ORDER_G" | tail -1)"
    expect "one slip, the module's, of the 4 000 refunded out of the shop's sight" "$(json 'str(len(d["slips"])) + "/" + str(int(float(d["slips"][0]["amount"]))) + "/" + str(d["slips"][0]["order_slip_type"])' <<<"$refunds")" "1/4000/2"
    expect "the refund known by its Wajub id, on that slip" "$(json 'str(d["refunds"][0]["refund_id"].startswith("rfd_")) + "/" + str(d["refunds"][0]["id_order_slip"] == d["slips"][0]["id_order_slip"])' <<<"$refunds")" "True/True"
    contains "with its note" "$(shop_checks order "$ORDER_G" | tail -1)" 'made in the Wajub Dashboard, is recorded on the order as a credit slip'
    expect "no product refunded by PrestaShop" "$(refunded_quantity "$ORDER_G")" 0
    expect "Wajub refunded nothing more" "$(real_refunds "$REAL4")" 1

    expect "the integration stand-in's key back" "$(shop_checks forget-key | tail -1)" forgotten
    KEYED=""
fi

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR refusals ($MODE): $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR refusals ($MODE): all checks passed"

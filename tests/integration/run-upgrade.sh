#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# A shop upgraded from an earlier version of the module, as a merchant does:
# the earlier version installed (from a git commit of this repository, the
# first one by default: 1.0.0), its webhook registered for all shops with the
# stand-in for Wajub's API (tests/integration/api-stub.py), then the
# repository's module put in its place and upgraded by PrestaShop
# (bin/console prestashop:module upgrade wajub), which runs upgrade/*.php:
#
#   - the version PrestaShop records is the repository's;
#   - upgrade-1.0.1.php: the recount controller's page exists, the webhook
#     registered before is given the events it lacks (PUT /webhooks/{id}),
#     keeping its own, with nothing created and no new secret, and what
#     1.0.0 recorded under the last order of a cart placed in two orders
#     (a payment, a note) goes to the first; the module is on
#     actionModuleRegisterHookAfter, and last again on the refund forms'
#     hook, after which a module installed later had put itself; on
#     displayBackOfficeTop and actionObjectOrderSlipAddAfter, and its
#     Symfony listener of kernel.terminate loaded once PrestaShop cleared
#     its cache.
#
#     tests/integration/run-upgrade.sh 8 [--from <git ref>]
#
# Leaves the module of the repository installed, the stand-in's address
# removed from the shop's configuration.

set -euo pipefail

MAJOR="${1:?usage: run-upgrade.sh <8|9> [--from <git ref>]}"
shift
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FROM="$(git -C "$ROOT" rev-list --max-parents=0 HEAD | tail -1)"
while [ $# -gt 0 ]; do
    case "$1" in
        --from) FROM="${2:?}"; shift 2 ;;
        *) echo "unknown option $1"; exit 2 ;;
    esac
done
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
CHECKS="wajub-checks-upgrade-ps$MAJOR.php"
shop_checks() { cp "$ROOT/tests/integration/checks.php" "$SHARE_DIR/$CHECKS"; "$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$CHECKS" "$@"; }

OLD="$(mktemp -d -p "$LOG_DIR" wajub-upgrade-from.XXXXXX)"
STUB_PID=""
STUB_LOG="$LOG_DIR/wajub-stub-upgrade-ps$MAJOR.log"
STUB_PORT_FILE="$LOG_DIR/wajub-stub-upgrade-ps$MAJOR.port"
STUB=""
stop_stub() {
    if [ -n "$STUB_PID" ]; then
        kill "$STUB_PID" 2>/dev/null || true
        wait "$STUB_PID" 2>/dev/null || true
        STUB_PID=""
        rm -f "$STUB_PORT_FILE"
        php_in_shop '$f = "config/defines_custom.inc.php"; $c = (string) file_get_contents($f); $d = preg_replace("/^.*wajub-integration-stub.*\n/m", "", $c); if ($d !== $c) { file_put_contents($f, $d); }' || true
    fi
}
ARRAY=""
cleanup() {
    stop_stub
    if [ -n "$ARRAY" ]; then
        "$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubarrayhook >/dev/null 2>&1 || true
        shop_checks array-hook remove >/dev/null 2>&1 || true
    fi
    rm -rf "$OLD" "$SHARE_DIR/$CHECKS"
}
trap cleanup EXIT

section "Version $(git -C "$ROOT" show "$FROM:config.xml" | grep -oE '<version><!\[CDATA\[[^]]+' | sed 's/.*\[//') installed (commit $(git -C "$ROOT" rev-parse --short "$FROM"))"
git -C "$ROOT" archive "$FROM" | tar -x -C "$OLD"
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajub >/dev/null 2>&1 || true
WAJUB_MODULE_SRC="$OLD" "$ENV_DIR/install.sh" "$MAJOR" >/dev/null
before="$(shop_checks version | tail -1)"
expect "the earlier version installed" "$(json 'd["version"]' <<<"$before")" "$(git -C "$ROOT" show "$FROM:config.xml" | grep -oE '<version><!\[CDATA\[[^]]+' | sed 's/.*\[//')"

python3 "$ROOT/tests/integration/api-stub.py" "$STUB_PORT_FILE" "$STUB_LOG" &
STUB_PID=$!
port=""
for i in $(seq 1 50); do
    port="$(cat "$STUB_PORT_FILE" 2>/dev/null || true)"
    [ -n "$port" ] && break
    sleep 0.1
done
[ -n "$port" ] || { fail "the API stub starts"; exit 1; }
STUB="http://127.0.0.1:$port"
php_in_shop '$f = "config/defines_custom.inc.php"; $c = preg_replace("/^.*wajub-integration-stub.*\n/m", "", (string) file_get_contents($f)); file_put_contents($f, rtrim($c, "\n") . "\n" . "define(\"_WAJUB_API_URL_\", \"http://host.containers.internal:'"$port"'\"); // wajub-integration-stub\n");'
WEBHOOK_URL="$(shop_checks payment-setup "whsec_test_upgrade_$(date +%s)" | tail -1)"
expect "its webhook registered for all shops" "$(shop_checks save-endpoint we_upgrade "$WEBHOOK_URL" | tail -1)" saved
OLD_EVENTS='["payment.succeeded", "payment.failed", "payment.expired", "payment.cancelled", "payment.processing", "refund.created", "refund.succeeded", "refund.failed", "refund.cancelled", "dispute.created", "dispute.updated", "dispute.won", "dispute.lost", "dispute.closed"]'
curl -s -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary "{\"items\": [{\"id\": \"we_other\", \"url\": \"https://elsewhere.test/hook\", \"events\": [\"payment.succeeded\"]}, {\"id\": \"we_upgrade\", \"url\": \"$WEBHOOK_URL\", \"events\": $OLD_EVENTS, \"active\": true}]}" "$STUB/__stub/webhooks"

# A cart placed in two orders, as 1.0.0 recorded it: its payment and its
# note under the last order. Two orders made one by giving the second the
# first's reference and cart, as PrestaShop makes them.
run="$(date +%s)"
SPLIT_A="$(shop_checks inject-order '{"products": [[1, 1]], "payments": [], "paid": false}' | tail -1)"
SPLIT_B="$(shop_checks inject-order "{\"products\": [[2, 1]], \"payments\": [{\"id\": \"trx_test_upgrade_split_$run\", \"counted\": 1000, \"environment\": \"sandbox\"}], \"paid\": false}" | tail -1)"
"$ENV_DIR/db.sh" "$MAJOR" -e "UPDATE ${DB_PREFIX}orders o2 INNER JOIN ${DB_PREFIX}orders o1 ON o1.id_order = $SPLIT_A SET o2.reference = o1.reference, o2.id_cart = o1.id_cart WHERE o2.id_order = $SPLIT_B; INSERT INTO ${DB_PREFIX}wajub_order_note (id_order, id_shop, note_key, message, date_add) VALUES ($SPLIT_B, 1, 'upgrade_split_$run', 'A note of 1.0.0', NOW())"
expect "a cart placed in two orders, its payment under the last" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_order FROM ${DB_PREFIX}wajub_payment WHERE payment_id = 'trx_test_upgrade_split_$run'")" "$SPLIT_B"

# A module on the refund forms installed after 1.0.0: after it on the hook.
shop_checks array-hook write >/dev/null
ARRAY=1
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module install wajubarrayhook >/dev/null 2>&1 || true
position="$(shop_checks array-hook position | tail -1)"
expect "a module installed after 1.0.0 runs after it on the refund forms" "${position%% of*}" 'not last'

section "Upgraded to the repository's version"
# As a merchant's files are replaced: PrestaShop's own cache is cleared by
# the upgrade, which then loads the module's Symfony services.
"$ENV_DIR/sync.sh" "$MAJOR" >/dev/null
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module upgrade wajub 2>&1 | grep -vE 'User Deprecated|^\s*$' | tail -3 || true
after="$(shop_checks version | tail -1)"
expect "PrestaShop records the new version" "$(json 'd["version"]' <<<"$after")" "$(grep -oE "public const VERSION = '[^']+'" "$ROOT/wajub.php" | sed "s/.*'\(.*\)'/\1/")"
expect "the recount controller's page made (upgrade-1.0.1.php)" "$(json 'd["recount_page"]' <<<"$after")" 1
endpoints="$(curl -s "$STUB/__stub/webhooks")"
expect "the webhook registered before listens to the instalments and to live refunds under way" "$(json '",".join(e for e in ("payment.split.processing", "payment.split.succeeded", "payment.split.failed", "refund.processing") if e in [w for w in d["items"] if w["id"] == "we_upgrade"][0]["events"])' <<<"$endpoints")" "payment.split.processing,payment.split.succeeded,payment.split.failed,refund.processing"
expect "keeping the events it had" "$(json 'str(all(e in [w for w in d["items"] if w["id"] == "we_upgrade"][0]["events"] for e in '"$OLD_EVENTS"'))' <<<"$endpoints")" True
expect "another address's endpoint untouched" "$(json 'str([w for w in d["items"] if w["id"] == "we_other"][0]["events"])' <<<"$endpoints")" "['payment.succeeded']"
expect "one PUT, nothing created, no new secret" "$(python3 -c '
import json, sys
calls = [json.loads(l) for l in open(sys.argv[1]) if l.strip()]
put = sum(1 for c in calls if c["method"] == "PUT" and c["path"].startswith("/webhooks/we_upgrade"))
created = sum(1 for c in calls if c["method"] == "POST" and c["path"].split("?")[0] == "/webhooks")
rotated = sum(1 for c in calls if "rotate-secret" in c["path"])
print("%d/%d/%d" % (put, created, rotated))
' "$STUB_LOG")" "1/0/0"
expect "the split cart's payment now under its first order" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_order FROM ${DB_PREFIX}wajub_payment WHERE payment_id = 'trx_test_upgrade_split_$run'")" "$SPLIT_A"
expect "and its note" "$("$ENV_DIR/db.sh" "$MAJOR" -N -e "SELECT id_order FROM ${DB_PREFIX}wajub_order_note WHERE note_key = 'upgrade_split_$run'")" "$SPLIT_A"
expect "which the module reads as the group's" "$(json 'str(d["lead"]) + "/" + str(len(d["ids"]))' <<<"$(shop_checks group "$SPLIT_B" | tail -1)")" "$SPLIT_A/2"
for hook in actionBeforeUpdateCancelProductFormHandler actionOrderSlipAdd actionAdminControllerSetMedia actionFrontControllerSetMedia; do
    expect "still on $hook" "$(shop_checks hooked "$hook" | tail -1)" yes
done
expect "on actionModuleRegisterHookAfter (upgrade-1.0.1.php)" "$(shop_checks hooked actionModuleRegisterHookAfter | tail -1)" yes
expect "on displayBackOfficeTop, \"Wajub · Sandbox\" (upgrade-1.0.1.php)" "$(shop_checks hooked displayBackOfficeTop | tail -1)" yes
expect "on actionObjectOrderSlipAddAfter, the slip of a refund recording it as PrestaShop adds it (upgrade-1.0.1.php)" "$(shop_checks hooked actionObjectOrderSlipAddAfter | tail -1)" yes
listeners="$("$ENV_DIR/exec.sh" "$MAJOR" php bin/console debug:event-dispatcher kernel.terminate 2>/dev/null || true)"
if grep -qF 'RefundFormsEnd::onTerminate()' <<<"$listeners"; then ok "the refund forms' end of request listens to kernel.terminate (config/admin/services.yml, loaded once PrestaShop cleared its cache)"; else fail "the refund forms' end of request listens to kernel.terminate"; fi
position="$(shop_checks array-hook position | tail -1)"
expect "last again on the refund forms, after the module installed before the upgrade" "${position%% of*}" last
"$ENV_DIR/exec.sh" "$MAJOR" php bin/console prestashop:module uninstall wajubarrayhook >/dev/null 2>&1 || true
expect "that module removed" "$(shop_checks array-hook remove | tail -1)" removed
ARRAY=""

stop_stub
if "$ENV_DIR/exec.sh" "$MAJOR" cat config/defines_custom.inc.php | grep -q 'wajub-integration-stub'; then
    fail "the stub's address is gone from the shop"
else
    ok "the stub's address is gone from the shop"
fi

echo
if [ "$failures" -gt 0 ]; then
    echo "PrestaShop $MAJOR upgrade: $failures failure(s)"
    exit 1
fi
echo "PrestaShop $MAJOR upgrade: all checks passed"

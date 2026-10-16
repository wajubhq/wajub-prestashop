#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# The back office's order page, as an employee uses it, for the integration
# runners (run-money.sh, run-refusal.sh): sourced after the env's lib.sh
# (ps_select sets PS_FAMILY, PS_ADMIN_URL), with `php_in_shop` and
# `shop_checks` defined by the runner. PrestaShop 8 and 9 differ in their
# login and their order page's address: told by PS_FAMILY, never by the
# shop's key (the 8.1 shop is a PrestaShop 8).

# An employee's session in `$1` (a cookie jar): the token its order pages
# take (PrestaShop 9: the session's own; 8: the employee's).
bo_login() {
    local jar="$1" login_page token landing
    login_page="$(curl -sL -c "$jar" -b "$jar" --max-time 120 "$PS_ADMIN_URL")"
    if [ "${PS_FAMILY:-$MAJOR}" = 8 ]; then
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
    if [ "${PS_FAMILY:-$MAJOR}" = 8 ]; then
        curl -sL -c "$1" -b "$1" --max-time 120 "${PS_ADMIN_URL}index.php?controller=AdminOrders&id_order=$3&vieworder=1&token=$2"
    else
        curl -sL -c "$1" -b "$1" --max-time 120 "${PS_ADMIN_URL}index.php/sell/orders/$3/view?_token=$2"
    fi
}

# The refund forms post the same form, to one route each, which the order
# page's script builds with the page's route token: `$4` in the session `$1`
# whose token is `$2`, of order `$3`, then the form's fields. Prints the
# page PrestaShop answers with (the order page, with its notice).
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

# A partial refund of one of the order's first product, for `$4`, in the
# session `$1` whose token is `$2`, of order `$3`.
partial_refund_as() {
    local detail
    detail="$(shop_checks order-detail-ids "$3" | tail -1)"
    refund_as "$1" "$2" "$3" partial-refund \
        --data-urlencode "cancel_product[quantity_$detail]=1" \
        --data-urlencode "cancel_product[amount_$detail]=$4" \
        --data-urlencode "cancel_product[shipping_amount]=0"
}

# The notice the order page shows after a refund form: its flash messages
# (alert-danger, alert-success), tags and blank lines removed.
bo_notice() {
    python3 -c '
import html, re, sys
page = sys.stdin.read()
found = []
for m in re.finditer(r"<div[^>]*class=\"[^\"]*alert alert-(danger|success|warning)[^\"]*\"[^>]*>(.*?)</div>", page, re.S):
    text = re.sub(r"<[^>]+>", " ", m.group(2))
    text = re.sub(r"\s+", " ", html.unescape(text)).strip()
    if text:
        found.append(m.group(1) + ": " + text)
print(" | ".join(found))
'
}

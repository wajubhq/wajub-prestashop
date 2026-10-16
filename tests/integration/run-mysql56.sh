#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# The module's locks and tables on a real MySQL 5.6 server (db56.sh of the
# environment WAJUB_PS_ENV names), where a connection holds one named lock only:
# tests/integration/mysql56.php, run with the PHP of a shop (PHP 8.1 on the
# PrestaShop 8 shops), against the module's sources of the repository.
#
#     tests/integration/run-mysql56.sh [8]
#
# Starts the 5.6 server if it is not running, and leaves it as it found it.

set -euo pipefail

MAJOR="${1:-8}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_DIR="${WAJUB_PS_ENV:-$HOME/.cache/wajub-prestashop/env}"
source "$ENV_DIR/lib.sh"
ps_select "$MAJOR"

started=""
if ! podman container exists wajub-mysql56 || [ "$(podman inspect -f '{{.State.Running}}' wajub-mysql56)" != true ]; then
    "$ENV_DIR/db56.sh" up >/dev/null
    started=1
fi
dir="$SHARE_DIR/wajub-mysql56-$$"
cleanup() {
    rm -rf "$dir"
    if [ -n "$started" ]; then
        "$ENV_DIR/db56.sh" down >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# The script and the sources it runs, readable by the shop's www-data.
mkdir -p "$dir/src/Support" "$dir/src/Storage" "$dir/src/Install"
cp "$ROOT/tests/integration/mysql56.php" "$dir/"
for file in Support/Lock.php Support/Logger.php Support/DbLock.php Storage/StateStore.php Storage/DbStateStore.php Install/Schema.php; do
    cp "$ROOT/src/$file" "$dir/src/$file"
done
chmod -R a+rX "$dir"

echo "== MySQL 5.6: the module's locks and tables (PHP of PrestaShop $PS_VERSION)"
"$ENV_DIR/exec.sh" "$MAJOR" php "/wajub-share/$(basename "$dir")/mysql56.php" "/wajub-share/$(basename "$dir")/src" wajub-mysql56 wajub wajub wajub-56

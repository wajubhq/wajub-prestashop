#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# Builds wajub.zip, the module as PrestaShop Addons and a direct download
# ship it: one top-level wajub/ folder, the module's own autoloader in
# vendor/ (no dependency: Composer's development tools stay out), an
# index.php in every folder, and nothing for developers (tests, tools,
# notes, Composer's lock file).
#
#     bin/build-zip.sh            # writes ./wajub.zip
#     bin/build-zip.sh out.zip    # writes it elsewhere
#
# Needs composer, php and zip. WAJUB_BUILD_STAGE names another folder to
# build in than build/stage, for builds that run side by side (the
# integration tests of both shops).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/wajub.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
STAGE="${WAJUB_BUILD_STAGE:-$ROOT/build/stage}"
MODULE="$STAGE/wajub"

COMPOSER="${COMPOSER_BIN:-composer}"
PHP="${PHP_BIN:-php}"

rm -rf "$STAGE"
mkdir -p "$MODULE"

# What the module is made of; everything else stays out.
for path in wajub.php config.xml logo.png LICENSE index.php composer.json controllers mails src translations upgrade views; do
    cp -R "$ROOT/$path" "$MODULE/$path"
done
# Notes for developers, wherever they are.
find "$MODULE" -name '*.md' -delete

# The module's autoloader alone: no package is required to run it, and the
# development tools Composer installs in the repository never ship.
(cd "$MODULE" && "$COMPOSER" dump-autoload --no-dev --classmap-authoritative --no-interaction --quiet)
rm -f "$MODULE/vendor/composer/installed.json"

# An index.php in every folder, so no folder of the module can be listed.
find "$MODULE" -type d | while read -r dir; do
    [ -f "$dir/index.php" ] || cp "$ROOT/index.php" "$dir/index.php"
done

"$PHP" "$ROOT/bin/lint-module.php" "$MODULE"

VERSION="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$MODULE/wajub.php" | head -n 1)"
CONFIG_VERSION="$(sed -n 's/.*<version><!\[CDATA\[\([^]]*\)\]\]><\/version>.*/\1/p' "$MODULE/config.xml" | head -n 1)"
if [ "$VERSION" != "$CONFIG_VERSION" ]; then
    echo "wajub.php says $VERSION, config.xml says $CONFIG_VERSION" >&2
    exit 1
fi

rm -f "$OUT"
(cd "$STAGE" && find wajub -type f | LC_ALL=C sort | zip -q -X "$OUT" -@)
echo "Built $OUT (wajub $VERSION, $(unzip -l "$OUT" | tail -n 1 | awk '{print $2}') files)"

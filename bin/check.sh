#!/usr/bin/env bash
#
# Wajub for PrestaShop
#
# @author    Wajub
# @copyright Since 2026 Wajub
# @license   https://opensource.org/licenses/MIT MIT License
#
# Every check the module passes before it ships:
#
#   1. PHP syntax of every file (and, with PHP81_LINT set, under PHP 8.1);
#   2. the Addons rules on the module's files (bin/lint-module.php);
#   3. the tests (tests/run-*-tests.php, and the scripts' tests/run-*-tests.js with node);
#   4. the French catalogue covers every string (bin/i18n.php check);
#   5. php-cs-fixer, PrestaShop's coding standard;
#   6. PHPStan, against a PrestaShop source tree (_PS_ROOT_DIR_), when one is given.
#
#     bin/check.sh
#     _PS_ROOT_DIR_=/path/to/prestashop bin/check.sh
#     PHP81_LINT='podman exec -i ps8 php -l' bin/check.sh
#
# Exits non-zero at the first check that fails.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP_BIN:-php}"
cd "$ROOT"

step() { printf '\n== %s\n' "$1"; }

mapfile -t PHP_FILES < <(find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -not -path './.phpstan/*' | LC_ALL=C sort)

step "PHP syntax ($("$PHP" -r 'echo PHP_VERSION;'))"
# PHP 8.3 and later lint many files in one run: one process, not one per
# file (a PHP that runs in a container starts slowly).
if [ "$("$PHP" -r 'echo PHP_VERSION_ID >= 80300 ? 1 : 0;')" = 1 ]; then
    printf '%s\0' "${PHP_FILES[@]}" | xargs -0 -n 200 "$PHP" -l >/dev/null
else
    for file in "${PHP_FILES[@]}"; do
        "$PHP" -l "$file" >/dev/null
    done
fi
echo "${#PHP_FILES[@]} files"

if [ -n "${PHP81_LINT:-}" ]; then
    step "PHP syntax under PHP 8.1"
    for file in "${PHP_FILES[@]}"; do
        $PHP81_LINT < "$file" >/dev/null
    done
    echo "${#PHP_FILES[@]} files"
fi

step "Module files"
"$PHP" bin/lint-module.php

step "Tests"
for runner in tests/run-*-tests.php; do
    "$PHP" "$runner"
done
if command -v node >/dev/null 2>&1; then
    for runner in tests/run-*-tests.js; do
        node "$runner"
    done
else
    echo "Skipped the scripts' tests: no node on this machine."
fi

step "Translations"
"$PHP" bin/i18n.php check

step "php-cs-fixer"
PHP_CS_FIXER_IGNORE_ENV=1 "$PHP" vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php

step "PHPStan"
if [ -n "${_PS_ROOT_DIR_:-}" ]; then
    # Named in a file as well: a PHP that runs in a container does not see
    # this shell's environment (tests/phpstan-bootstrap.php reads both).
    mkdir -p build
    printf '%s\n' "$_PS_ROOT_DIR_" > build/ps-root.txt
    "$PHP" -d memory_limit=3G vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress
else
    echo "Skipped: set _PS_ROOT_DIR_ to a PrestaShop source tree to run it."
fi

printf '\nAll checks passed.\n'

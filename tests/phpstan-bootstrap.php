<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/*
 * PHPStan's bootstrap: PrestaShop's classes from the source tree named by
 * _PS_ROOT_DIR_, through prestashop/php-dev-tools' own bootstrap. The tree
 * may also be named in build/ps-root.txt, which bin/check.sh writes: some
 * PHP wrappers (a PHP in a container) do not pass the environment on.
 */
$root = getenv('_PS_ROOT_DIR_');
$file = dirname(__DIR__) . '/build/ps-root.txt';
if (!$root && is_file($file)) {
    $root = trim((string) file_get_contents($file));
    putenv('_PS_ROOT_DIR_=' . $root);
}

require dirname(__DIR__) . '/vendor/prestashop/php-dev-tools/phpstan/bootstrap.php';

// Defined by PrestaShop from app/config/parameters.php, which a source tree
// that was never installed does not have.
if (!defined('_NEW_COOKIE_KEY_')) {
    define('_NEW_COOKIE_KEY_', '');
}
if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_', '');
}

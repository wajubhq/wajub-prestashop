<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit; // The test runners run from the command line only.
}

/*
 * What every test runner loads: the module's classes, a check() that counts
 * and prints, and stand-ins for what reaches outside PHP (the network, the
 * database, PrestaShop's translator and log). Stand-ins of PrestaShop's own
 * classes (Configuration, Shop, PhpEncryption…) are in tests/fakes/, loaded
 * by the runners that test the adapters on them.
 *
 *     php tests/run-client-tests.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('_PS_VERSION_', '8.2.8');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
define('_PS_MODE_DEV_', false);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    // No Composer install: the module's classes by their PSR-4 path.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Wajub\\PrestaShop\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

require_once __DIR__ . '/fakes/support.php';

$GLOBALS['__checks'] = ['passed' => 0, 'failed' => []];

/** Counts one check, and prints it when it fails. */
function check(bool $condition, string $message): void
{
    if ($condition) {
        ++$GLOBALS['__checks']['passed'];

        return;
    }
    $GLOBALS['__checks']['failed'][] = $message;
    fwrite(STDOUT, "FAIL: {$message}\n");
}

/** Runs `$code` and returns what it threw, or null. */
function thrown(callable $code): ?Throwable
{
    try {
        $code();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** Prints the count, and ends the runner with 1 when a check failed. */
function done(string $name): void
{
    $failed = count($GLOBALS['__checks']['failed']);
    fwrite(STDOUT, sprintf("%s: %d passed, %d failed\n", $name, $GLOBALS['__checks']['passed'], $failed));
    exit($failed === 0 ? 0 : 1);
}

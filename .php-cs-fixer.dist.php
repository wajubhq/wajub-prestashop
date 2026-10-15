<?php
/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */

// PrestaShop's coding standard (prestashop/php-dev-tools), on the module's
// code, its tests and its tools.
$config = new PrestaShop\CodingStandards\CsFixer\Config();

$config
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->getFinder()
    ->in(__DIR__)
    ->exclude(['vendor', 'build', 'translations'])
    ->notPath('index.php');

return $config;

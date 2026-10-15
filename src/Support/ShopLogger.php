<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Logger on PrestaShop's log (Advanced Parameters > Logs), where the
 * merchant and their support already look. Errors always; the rest while
 * the debug log is on (Settings::debugLog()).
 */
final class ShopLogger implements Logger
{
    /** PrestaShopLogger's severities. */
    private const INFORMATIVE = 1;

    private const ERROR = 3;

    /** A line long enough for its context, short enough to read in the Logs list. */
    private const MAX_LENGTH = 2000;

    /** @var callable(): bool */
    private $debugEnabled;

    /**
     * @param callable(): bool $debugEnabled read on each line, so turning the setting on takes effect at once
     */
    public function __construct(callable $debugEnabled)
    {
        $this->debugEnabled = $debugEnabled;
    }

    public function info(string $message, array $context = []): void
    {
        if (($this->debugEnabled)()) {
            $this->write(self::INFORMATIVE, $message, $context);
        }
    }

    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(int $severity, string $message, array $context): void
    {
        $line = 'Wajub: ' . $message;
        if ($context !== []) {
            $line .= ' ' . (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        // Repeated lines are kept: the same failure twice is two failures.
        \PrestaShopLogger::addLog(mb_substr($line, 0, self::MAX_LENGTH), $severity, null, 'Wajub', 0, true);
    }
}

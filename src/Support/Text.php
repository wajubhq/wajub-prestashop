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
 * Plain text and addresses as the module sends and keeps them: what an
 * answer of Wajub or a form field holds, made safe to store and to print
 * once escaped.
 */
final class Text
{
    /**
     * One line of plain text: no tag, no control character, no run of
     * spaces, trimmed. What a name, a reference or a code may hold.
     */
    public static function line(mixed $value, int $max = 0): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        $text = strip_tags((string) $value);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * Several lines of plain text (a description a merchant writes): no tag
     * and no control character but the line breaks, each line trimmed.
     */
    public static function lines(mixed $value, int $max = 0): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", strip_tags((string) $value));
        $text = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $text);
        $text = trim(implode("\n", array_map('trim', explode("\n", $text))));
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * A code as Wajub writes one (`payment.succeeded`, `cm.mtn`, `trx_…`):
     * letters, digits, dots, dashes and underscores only, else ''.
     */
    public static function code(mixed $value, int $max = 128): string
    {
        $text = self::line($value);

        return $text !== '' && strlen($text) <= $max && preg_match('/^[A-Za-z0-9._-]+$/', $text) === 1 ? $text : '';
    }

    /**
     * An http(s) address with a host, as given, or '' for anything else
     * (javascript:, a relative path, a user before the host).
     */
    public static function url(mixed $value): string
    {
        $url = self::line($value);
        if ($url === '' || preg_match('#^https?://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?(?:[/?\#]|$)#i', $url) !== 1) {
            return '';
        }

        return $url;
    }

    /**
     * An e-mail address in a shape the API takes, or ''.
     */
    public static function email(mixed $value): string
    {
        $email = self::line($value, 254);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}

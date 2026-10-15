<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The transport on ext-curl, which every PrestaShop server has: the module
 * ships no HTTP library of its own.
 *
 * The certificate is always checked, redirects are never followed, and only
 * HTTPS (and HTTP for a test address) is spoken, so a key or a polling token
 * sent in a header never leaves for another address or protocol.
 */
final class CurlTransport implements Transport
{
    /** How long to wait for the connection itself, within the request's own timeout. */
    private const CONNECT_TIMEOUT = 5;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('The PHP cURL extension is not available on this server.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('cURL could not start a request.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $received = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, max(1, $timeout)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($handle, $options);

        $answer = curl_exec($handle);
        if (!is_string($answer)) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);

            throw new TransportException(sprintf('Request to %s failed: %s (cURL %d)', (string) parse_url($url, PHP_URL_HOST), $error !== '' ? $error : 'no answer', $errno));
        }

        // No curl_close(): the handle is an object freed with it since PHP
        // 8.0, and PHP 8.5 reports the call as deprecated.
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, $received, $answer);
    }
}

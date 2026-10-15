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
 * How the module talks to Wajub over HTTPS. One method, so the API client
 * and the pairing client share it, and the tests answer in its place.
 */
interface Transport
{
    /**
     * Sends one request and returns whatever the server answered, an error
     * status included. Redirects are never followed: an answer that moves
     * elsewhere is returned as it is, so a secret header never reaches
     * another address.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException when no answer came: the address could not be reached, TLS failed, or the time ran out
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse;
}

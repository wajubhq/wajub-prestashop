<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Pairing;

use Wajub\PrestaShop\Http\Transport;
use Wajub\PrestaShop\Http\TransportException;
use Wajub\PrestaShop\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The Wajub Dashboard's device pairing (`/api/apps/auth/*`), the flow behind
 * `wajub login`, as the shop calls it: create a session, then poll it until
 * the merchant approves it in the Dashboard. Pairing decides what each
 * answer means; this class only carries the requests.
 *
 * It calls from the server, never from the browser, so the key the poll
 * hands over once never reaches the page. The polling token travels in an
 * `Authorization: Bearer` header only: the Dashboard also reads a `?token=`
 * query, but an address ends up in access logs and proxies. Redirects are
 * not followed (Http\Transport), so the token never goes to another address
 * than the Dashboard's.
 */
final class PairingClient
{
    public const DASHBOARD = 'https://dashboard.wajub.com';

    /** Where a session is created (DeviceAuthSessionController::store). */
    public const SESSIONS_PATH = '/api/apps/auth/sessions';

    /** Where a session is polled (DeviceAuthSessionController::poll). */
    public const POLL_PATH = '/api/apps/auth/sessions/poll';

    /** An admin waits on each call, as on a key check. */
    private const TIMEOUT = 10;

    /** @var Transport */
    private $transport;

    /** @var string */
    private $userAgent;

    /** @var Logger */
    private $logger;

    /** @var string */
    private $base;

    public function __construct(Transport $transport, string $userAgent, Logger $logger, string $base = self::DASHBOARD)
    {
        $this->transport = $transport;
        $this->userAgent = $userAgent;
        $this->logger = $logger;
        $this->base = rtrim($base, '/');
    }

    /**
     * `POST /api/apps/auth/sessions`. `http` is 0 when the Dashboard could not
     * be reached; `body` is its JSON answer, or [] when it sent none.
     *
     * @param array<string, mixed> $body
     *
     * @return array{http: int, body: array<string, mixed>}
     */
    public function createSession(array $body): array
    {
        return $this->send('POST', self::SESSIONS_PATH, $body, '');
    }

    /**
     * `GET /api/apps/auth/sessions/poll`, with the session's polling token.
     *
     * @return array{http: int, body: array<string, mixed>}
     */
    public function poll(string $pollingToken): array
    {
        return $this->send('GET', self::POLL_PATH, null, $pollingToken);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{http: int, body: array<string, mixed>}
     */
    private function send(string $method, string $path, ?array $body, string $pollingToken): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            // The same as the API client's, so the Dashboard's logs name the module and its version.
            'User-Agent' => $this->userAgent,
        ];
        if ($pollingToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $pollingToken;
        }

        try {
            $response = $this->transport->send($method, $this->base . $path, $headers, $body !== null ? (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, self::TIMEOUT);
        } catch (TransportException $e) {
            // The transport's own words: they name the host, never the token.
            $this->logger->error('Pairing request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['http' => 0, 'body' => []];
        }

        return ['http' => $response->status, 'body' => $response->json() ?? []];
    }
}

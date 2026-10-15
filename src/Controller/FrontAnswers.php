<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Controller;

use Symfony\Component\HttpFoundation\Request;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module's storefront controllers share: a JSON answer that no
 * cache keeps, the request's method, headers and the visitor's address (read
 * through Symfony's Request, which PrestaShop ships, rather than PHP's
 * globals), and a message kept for the next page, as PrestaShop's
 * redirectWithNotifications() keeps it, for a script that takes the payer
 * there itself.
 *
 * For ModuleFrontController's subclasses only.
 */
trait FrontAnswers
{
    /** @var Request|null */
    private $wajubRequest;

    /**
     * @param array<string, mixed> $body
     */
    protected function answerJson(int $status, array $body, int $retryAfter = 0): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex');
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
        }
        $this->ajaxRender((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit;
    }

    /** The request as the web server handed it to PHP. */
    protected function httpRequest(): Request
    {
        if ($this->wajubRequest === null) {
            $this->wajubRequest = Request::createFromGlobals();
        }

        return $this->wajubRequest;
    }

    /**
     * Whether the request is a POST, as the web server says: not a method a
     * header claims instead (getRealMethod(), not getMethod()).
     */
    protected function isPost(): bool
    {
        return $this->httpRequest()->getRealMethod() === 'POST';
    }

    /** A request header, trimmed, '' when it is missing. */
    protected function header(string $name, int $max = 200): string
    {
        $value = $this->httpRequest()->headers->get($name);

        return is_string($value) ? substr(trim($value), 0, $max) : '';
    }

    /**
     * The visitor's address, as the web server saw it: not a forwarded-for
     * header, which any visitor can write. Behind a proxy, every visitor
     * shares the proxy's address and its limit.
     */
    protected function clientIp(): string
    {
        $ip = trim((string) $this->httpRequest()->server->get('REMOTE_ADDR', ''));

        return $ip !== '' ? substr($ip, 0, 64) : 'unknown';
    }

    /**
     * Keeps the controller's messages for the next page the payer opens, as
     * redirectWithNotifications() does before it redirects.
     */
    protected function keepNotifications(): void
    {
        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        }
    }

    /** A posted (or, failing that, given) value as one short line of text. */
    protected function text(string $name, int $max = 128): string
    {
        $value = \Tools::getValue($name, '');

        return is_string($value) ? substr(trim($value), 0, $max) : '';
    }
}

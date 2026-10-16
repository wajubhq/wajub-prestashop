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
use Wajub\PrestaShop\Checkout\KeptNotices;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module's storefront controllers share: a JSON answer that no
 * cache keeps, the request's method, headers and the visitor's address (read
 * through Symfony's Request, which PrestaShop ships, rather than PHP's
 * globals), and messages for the page a script takes the payer to next,
 * which go through PrestaShop's own redirectWithNotifications()
 * (Checkout\KeptNotices).
 *
 * For the module's ModuleFrontController subclasses only.
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
     * Where the script that reads this answer takes the payer, for the
     * controller's messages to show there: this controller (`$controller`)
     * again, with their id (Checkout\KeptNotices), which hands them to
     * PrestaShop's redirectWithNotifications() on its way to `$to`
     * (showKeptNotices()). `$to` itself when there is nothing to say.
     */
    protected function throughNotices(string $controller, string $to): string
    {
        $id = (new KeptNotices($this->module->services()->state()))->keep([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ], $to);

        return $id === '' ? $to : $this->context->link->getModuleLink('wajub', $controller, ['notice' => $id], true);
    }

    /**
     * A payer a script sent here (throughNotices()): their messages go to
     * PrestaShop's notifications, read on the page they are taken to, the
     * checkout when their messages are gone. Nothing for a request without
     * `notice`.
     */
    protected function showKeptNotices(): void
    {
        $id = (string) \Tools::getValue('notice', '');
        if ($id === '') {
            return;
        }
        $kept = (new KeptNotices($this->module->services()->state()))->take(substr($id, 0, 64));
        foreach ($kept !== null ? $kept['messages'] : [] as $kind => $messages) {
            foreach ($messages as $message) {
                if ($kind === 'error') {
                    $this->errors[] = $message;
                } elseif ($kind === 'warning') {
                    $this->warning[] = $message;
                } elseif ($kind === 'success') {
                    $this->success[] = $message;
                } else {
                    $this->info[] = $message;
                }
            }
        }
        $this->redirectWithNotifications($kept !== null ? $kept['to'] : $this->context->link->getPageLink('order'));
    }

    /** A posted (or, failing that, given) value, read as text, as one short line. */
    protected function line(string $value, int $max = 128): string
    {
        return substr(trim($value), 0, $max);
    }
}

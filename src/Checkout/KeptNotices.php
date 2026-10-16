<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Checkout;

use Wajub\PrestaShop\Storage\StateStore;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The messages of an answer a checkout script reads (JSON), kept for the
 * page the script takes the payer to next. PrestaShop shows a storefront
 * controller's messages on the next page when the controller redirects to
 * it (FrontController::redirectWithNotifications()), which a script's
 * request does not: the script goes through the same controller first,
 * with the id kept here, and the controller hands the messages to
 * redirectWithNotifications() on its way (Controller\FrontAnswers).
 *
 * Kept in the module's state for TTL seconds and read once. The address
 * the payer goes on to is kept with them, never taken from the request:
 * the id alone leads nowhere else. Only text, by PrestaShop's kinds.
 */
final class KeptNotices
{
    /** Seconds the messages wait for the payer's browser. */
    public const TTL = 600;

    /** PrestaShop's kinds of messages, as redirectWithNotifications() keeps them. */
    public const KINDS = ['error', 'warning', 'success', 'info'];

    private const PREFIX = 'notice:';

    /** @var StateStore */
    private $state;

    public function __construct(StateStore $state)
    {
        $this->state = $state;
    }

    /**
     * Keeps the messages, by kind, and where the payer goes on to: their
     * id, or '' when there is no message.
     *
     * @param array<string, mixed> $messages
     */
    public function keep(array $messages, string $to): string
    {
        $kept = self::only($messages);
        if ($kept === [] || $to === '') {
            return '';
        }
        $id = bin2hex(random_bytes(16));
        $this->state->set(self::PREFIX . $id, ['to' => $to, 'messages' => $kept], self::TTL);

        return $id;
    }

    /**
     * The messages kept under the id, and where the payer goes on to; null
     * for an id that is none, or whose messages were read or expired. Read
     * once.
     *
     * @return array{to: string, messages: array<string, list<string>>}|null
     */
    public function take(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return null;
        }
        $kept = $this->state->get(self::PREFIX . $id);
        $this->state->delete(self::PREFIX . $id);
        if (!is_array($kept) || !is_string($kept['to'] ?? null) || $kept['to'] === '') {
            return null;
        }

        return ['to' => $kept['to'], 'messages' => self::only(is_array($kept['messages'] ?? null) ? $kept['messages'] : [])];
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, list<string>>
     */
    private static function only(array $messages): array
    {
        $kept = [];
        foreach (self::KINDS as $kind) {
            foreach (is_array($messages[$kind] ?? null) ? $messages[$kind] : [] as $message) {
                if (is_string($message) && $message !== '') {
                    $kept[$kind][] = $message;
                }
            }
        }

        return $kept;
    }
}

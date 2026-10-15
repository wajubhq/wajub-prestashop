<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Webhook;

use Wajub\PrestaShop\Settings\Settings;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Ago;
use Wajub\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Whether Wajub's webhooks reach a shop, from what the webhook writes down:
 * the last delivery it accepted and the last one it refused. Only what
 * happened is said: "never received" is not "broken" before the first
 * payment.
 *
 * Refusals anyone could cause (a request that only looks signed: an invalid
 * signature, a missing secret) are written down once a minute at most, and
 * counted; the webhook is called failing once they repeat over a few
 * minutes with no verified delivery since, as Wajub's own retries of a
 * delivery the shop cannot verify do. One request from anyone must not show
 * the merchant a failing webhook and advice of its choosing.
 */
final class WebhookHealth
{
    /** Refusals of a request no saved secret signed. */
    public const UNVERIFIED_REASONS = ['invalid_signature', 'missing_secret'];

    /** Such refusals mean the webhook fails once this many were counted … */
    private const CONFIRMING_REFUSALS = 3;

    /** … over at least this long: Wajub tries a delivery again after 15 to 30 seconds, then less and less often. */
    private const CONFIRMING_SPAN = 120;

    /** @var StateStore */
    private $state;

    /** @var string */
    private $scope;

    /**
     * @param string $scope the slug of the scope whose webhook it is (Settings\Scope::slug()): each
     *                      shop, group or "All shops" that registered its own has its own health
     */
    public function __construct(StateStore $state, string $scope)
    {
        $this->state = $state;
        $this->scope = $scope;
    }

    /**
     * A delivery that passed the signature check: its event, and the
     * environment of the secret that verified it.
     */
    public function recordReceived(string $event, string $environment, ?int $now = null): void
    {
        $now = $now ?? time();
        $previous = $this->state->get($this->name('received'));
        $previous = is_array($previous) ? $previous : [];

        $this->state->set($this->name('received'), [
            'at' => $now,
            'event' => substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $event), 0, 64),
            'environment' => $environment === Settings::LIVE ? Settings::LIVE : Settings::SANDBOX,
            // The last Sandbox delivery is kept apart: a Live one, which a
            // shop in Sandbox hears too, must not hide that test deliveries
            // stopped.
            'sandbox_at' => $environment === Settings::SANDBOX ? $now : (int) ($previous['sandbox_at'] ?? 0),
        ]);
    }

    /**
     * A delivery that was refused: 'missing_secret' when no secret is saved
     * for the environment it says it came from, 'invalid_signature' when
     * the signature is missing or no saved secret's, 'stale' when it is
     * one's but was made more than five minutes ago, 'invalid_payload' when
     * a signed body is not JSON. `$environment` is the verifying secret's for
     * the last two, and only what the body claimed for the others.
     */
    public function recordRejected(string $reason, string $environment = '', ?int $now = null): void
    {
        $now = $now ?? time();
        $verified = !in_array($reason, self::UNVERIFIED_REASONS, true);
        if (!$verified && !$this->state->add($this->name('refusal_lock'), 1, 60)) {
            return;
        }

        $previous = $this->state->get($this->name('rejected'));
        $received = $this->state->get($this->name('received'));
        $receivedAt = is_array($received) ? (int) ($received['at'] ?? 0) : 0;
        // The same kind of refusal, with no verified delivery since the
        // first: one run of refusals, counted on.
        $continues = is_array($previous)
            && isset($previous['count'], $previous['first_at'])
            && (bool) ($previous['verified'] ?? true) === $verified
            && (int) $previous['first_at'] > $receivedAt;

        $this->state->set($this->name('rejected'), [
            'at' => $now,
            'reason' => $reason,
            'environment' => in_array($environment, Settings::ENVIRONMENTS, true) ? $environment : '',
            'verified' => $verified,
            'count' => $continues ? (int) $previous['count'] + 1 : 1,
            'first_at' => $continues ? (int) $previous['first_at'] : $now,
        ]);
    }

    /**
     * - never: no delivery accepted or refused yet;
     * - receiving: the last delivery was accepted;
     * - rejected: the last delivery was refused, after any accepted one, and
     *   for a refusal anyone could cause, once such refusals repeat.
     *
     * @return array{state: string, received: array{at: int, event: string, environment: string, sandbox_at: int}|null, rejected: array{at: int, reason: string, environment: string, count: int, first_at: int}|null, unverified: array{count: int, first_at: int, at: int}|null}
     */
    public function state(): array
    {
        $stored = $this->state->get($this->name('received'));
        $received = is_array($stored) && (int) ($stored['at'] ?? 0) > 0
            ? [
                'at' => (int) $stored['at'],
                'event' => (string) ($stored['event'] ?? ''),
                'environment' => ($stored['environment'] ?? '') === Settings::LIVE ? Settings::LIVE : Settings::SANDBOX,
                'sandbox_at' => (int) ($stored['sandbox_at'] ?? 0),
            ]
            : null;
        $stored = $this->state->get($this->name('rejected'));
        $rejected = is_array($stored) && (int) ($stored['at'] ?? 0) > 0
            ? [
                'at' => (int) $stored['at'],
                'reason' => (string) ($stored['reason'] ?? ''),
                'environment' => (string) ($stored['environment'] ?? ''),
                'count' => max(1, (int) ($stored['count'] ?? 1)),
                'first_at' => (int) ($stored['first_at'] ?? $stored['at']),
            ]
            : null;
        $refusedLast = $rejected !== null && ($received === null || $rejected['at'] >= $received['at']);

        $confirmed = $refusedLast && (
            !in_array($rejected['reason'], self::UNVERIFIED_REASONS, true)
            || (
                $rejected['count'] >= self::CONFIRMING_REFUSALS
                && $rejected['at'] - $rejected['first_at'] >= self::CONFIRMING_SPAN
                && ($received === null || $received['at'] < $rejected['first_at'])
            )
        );

        if ($confirmed) {
            $state = 'rejected';
        } else {
            $state = $received !== null ? 'receiving' : 'never';
        }
        $unverified = $refusedLast && !$confirmed
            ? ['count' => $rejected['count'], 'first_at' => $rejected['first_at'], 'at' => $rejected['at']]
            : null;

        return ['state' => $state, 'received' => $received, 'rejected' => $rejected, 'unverified' => $unverified];
    }

    /**
     * What the merchant can do about a refused delivery, from its reason.
     * Every saved signing secret, Sandbox and Live, is tried on a delivery.
     */
    public static function guidance(string $reason, Translator $t): string
    {
        switch ($reason) {
            case 'missing_secret':
                return $t->trans('No signing secret is saved for the environment this delivery came from. Use “Register automatically”, or paste the signing secret the Wajub Dashboard shows for this webhook.', [], 'Modules.Wajub.Admin');
            case 'invalid_signature':
                return $t->trans('The signature matched none of the signing secrets saved here, Sandbox or Live. The secret was probably renewed in the Wajub Dashboard, or the one saved belongs to another webhook endpoint. Use “Get a new signing secret”, or paste the current one.', [], 'Modules.Wajub.Admin');
            case 'stale':
                return $t->trans('The signing secret is right, but the time of the delivery and this server’s clock were more than five minutes apart. Check that the server’s clock is on time, or ask your web host to set it.', [], 'Modules.Wajub.Admin');
            case 'invalid_payload':
                return $t->trans('The delivery could not be read. If this repeats, contact Wajub support with the time shown here.', [], 'Modules.Wajub.Admin');
            default:
                return $t->trans('The delivery could not be verified. Check that the signing secret saved here is the one the Wajub Dashboard shows for this webhook.', [], 'Modules.Wajub.Admin');
        }
    }

    /**
     * The neutral line of refusals not confirmed yet: how many requests that
     * looked like Wajub's could not be verified, and what happens next.
     *
     * @param array{count: int, first_at: int, at: int} $unverified
     */
    public static function unverifiedNote(array $unverified, Translator $t): string
    {
        $count = max(1, $unverified['count']);
        $ago = Ago::since($unverified['at'], $t);

        return $count === 1
            ? $t->trans('1 request that looked like a Wajub delivery could not be verified, %time% ago. If Wajub’s own deliveries are refused, this will say so within a few minutes, with what to fix.', ['%time%' => $ago], 'Modules.Wajub.Admin')
            : $t->trans('%count% requests that looked like Wajub deliveries could not be verified, the last one %time% ago. If Wajub’s own deliveries are refused, this will say so within a few minutes, with what to fix.', ['%count%' => $count, '%time%' => $ago], 'Modules.Wajub.Admin');
    }

    /** What the webhook of one scope keeps, apart from every other scope's. */
    private function name(string $what): string
    {
        return 'webhook_' . $what . ':' . $this->scope;
    }
}

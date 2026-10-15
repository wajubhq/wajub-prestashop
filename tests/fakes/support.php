<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

use Wajub\PrestaShop\Http\HttpResponse;
use Wajub\PrestaShop\Http\Transport;
use Wajub\PrestaShop\Http\TransportException;
use Wajub\PrestaShop\Services;
use Wajub\PrestaShop\Settings\ConfigStore;
use Wajub\PrestaShop\Settings\Scope;
use Wajub\PrestaShop\Settings\SecretCipher;
use Wajub\PrestaShop\Shop\ShopInfo;
use Wajub\PrestaShop\Storage\StateStore;
use Wajub\PrestaShop\Support\Logger;
use Wajub\PrestaShop\Support\Mailer;
use Wajub\PrestaShop\Support\Translator;
use Wajub\PrestaShop\Webhook\EventStore;

/**
 * The network: every request is recorded, headers included, and answered by
 * its method and address without the query ("GET https://api.wajub.com/"),
 * with a list of answers given in turn (the last one repeated) or a closure.
 * An answer is [status, body array|string, headers] or a TransportException.
 * A request nobody answers gets 418, which no check expects.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    public array $sent = [];

    /** @var array<string, list<mixed>|Closure> */
    public array $routes = [];

    /** @var array<string, int> */
    private array $served = [];

    /**
     * @param array<string, list<mixed>|Closure> $routes
     */
    public function __construct(array $routes = [])
    {
        $this->routes = $routes;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $this->sent[] = $request;
        $route = $method . ' ' . strtok($url, '?');
        $answers = $this->routes[$route] ?? null;
        if ($answers instanceof Closure) {
            $answer = $answers($request);
        } elseif (is_array($answers) && $answers !== []) {
            $index = $this->served[$route] ?? 0;
            $this->served[$route] = $index + 1;
            $answer = $answers[min($index, count($answers) - 1)];
        } else {
            $answer = [418, ['message' => 'No route for ' . $route]];
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }
        [$status, $data] = $answer;
        $headersBack = $answer[2] ?? [];

        return new HttpResponse((int) $status, $headersBack, is_string($data) ? $data : (string) json_encode($data));
    }

    /** @return list<string> "METHOD url" of every request, in order */
    public function routes(): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['url'], $this->sent);
    }

    /** The JSON body of the n-th request. */
    public function json(int $index): mixed
    {
        return json_decode((string) ($this->sent[$index]['body'] ?? ''), true);
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->served = [];
    }
}

/** No answer at all. */
function network_down(): TransportException
{
    return new TransportException('Could not resolve host: api.wajub.com');
}

/** The StateStore in memory, with a clock the tests move. */
final class MemoryStateStore implements StateStore
{
    /** @var array<string, array{value: string, expires_at: int}> */
    public array $rows = [];

    public int $now = 0;

    public function now(): int
    {
        return $this->now > 0 ? $this->now : time();
    }

    public function get(string $name): mixed
    {
        $row = $this->rows[$name] ?? null;
        if ($row === null || ($row['expires_at'] > 0 && $row['expires_at'] <= $this->now())) {
            return null;
        }

        return json_decode($row['value'], true);
    }

    public function set(string $name, mixed $value, int $ttl = 0): void
    {
        $this->rows[$name] = ['value' => (string) json_encode($value), 'expires_at' => $ttl > 0 ? $this->now() + $ttl : 0];
    }

    public function add(string $name, mixed $value, int $ttl = 0): bool
    {
        if ($this->get($name) !== null) {
            return false;
        }
        $this->set($name, $value, $ttl);

        return true;
    }

    public function delete(string $name): void
    {
        unset($this->rows[$name]);
    }

    public function increment(string $name, int $ttl): int
    {
        $value = $this->get($name);
        if ($value === null) {
            $this->set($name, 1, $ttl);

            return 1;
        }
        $this->rows[$name]['value'] = (string) json_encode((int) $value + 1);

        return (int) $value + 1;
    }

    /** Every value kept, as text: where a test looks for a secret that must not be there. */
    public function dump(): string
    {
        return (string) json_encode($this->rows);
    }

    /** Ttl left of a row, in seconds. */
    public function ttl(string $name): int
    {
        return isset($this->rows[$name]) ? $this->rows[$name]['expires_at'] - $this->now() : -1;
    }
}

/**
 * The ConfigStore in memory, shared by every scope as PrestaShop's
 * configuration table is: a read falls back from the shop to its group to
 * all shops.
 */
final class ArrayConfigStore implements ConfigStore
{
    /** @var array<string, array<string, array<int, string>>> scope slug => key => language => value */
    public static array $values = [];

    private Scope $scope;

    public function __construct(?Scope $scope = null)
    {
        $this->scope = $scope ?? Scope::allShops();
    }

    public function get(string $key, ?int $idLang = null): ?string
    {
        $lang = (int) $idLang;
        $chain = [];
        if ($this->scope->idShop !== null) {
            $chain[] = 's' . $this->scope->idShop;
        }
        if ($this->scope->idShopGroup !== null) {
            $chain[] = 'g' . $this->scope->idShopGroup;
        }
        $chain[] = 'all';
        foreach ($chain as $slug) {
            if (isset(self::$values[$slug][$key][$lang])) {
                return self::$values[$slug][$key][$lang];
            }
        }

        return null;
    }

    public function set(string $key, string $value): void
    {
        self::$values[$this->scope->slug()][$key][0] = $value;
    }

    public function setLang(string $key, array $values): void
    {
        foreach ($values as $lang => $value) {
            self::$values[$this->scope->slug()][$key][(int) $lang] = $value;
        }
    }

    public function savedAt(string $key): array
    {
        $saved = [];
        foreach (self::$values as $slug => $keys) {
            if (isset($keys[$key][0])) {
                $saved[$slug] = $keys[$key][0];
            }
        }

        return $saved;
    }

    public function forget(string $key): void
    {
        if (!$this->scope->isAllShops()) {
            unset(self::$values[$this->scope->slug()][$key]);
        }
    }

    public function deleteEverywhere(string $key): void
    {
        foreach (self::$values as $slug => $keys) {
            unset(self::$values[$slug][$key]);
        }
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    /** Everything saved, as text. */
    public static function dump(): string
    {
        return (string) json_encode(self::$values);
    }
}

/** Encryption the tests can see through: the secret, reversed and wrapped. */
final class FakeCipher implements SecretCipher
{
    public function encrypt(string $plain): string
    {
        return 'enc:' . base64_encode(strrev($plain));
    }

    public function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, 'enc:')) {
            return null;
        }
        $decoded = base64_decode(substr($stored, 4), true);

        return $decoded === false ? null : strrev($decoded);
    }
}

/** English as written, with its parameters; every call is kept, with its domain. */
final class TestTranslator implements Translator
{
    /** @var list<array{id: string, domain: ?string}> */
    public array $calls = [];

    public function trans(string $id, array $parameters = [], ?string $domain = null): string
    {
        $this->calls[] = ['id' => $id, 'domain' => $domain];

        return strtr($id, array_map('strval', $parameters));
    }

    public function date(int $timestamp): string
    {
        return gmdate('Y-m-d', $timestamp);
    }
}

final class TestLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $lines = [];

    public function info(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function has(string $message): bool
    {
        foreach ($this->lines as $line) {
            if ($line['message'] === $message) {
                return true;
            }
        }

        return false;
    }

    public function dump(): string
    {
        return (string) json_encode($this->lines);
    }
}

final class FakeShop implements ShopInfo
{
    public int $id = 1;

    public string $name = 'Boutique & Co';

    public string $baseUrl = 'https://boutique.cm/';

    public string $webhookUrl = 'https://boutique.cm/module/wajub/webhook';

    public bool $production = true;

    /** @var list<int> the shops of the scope; the shop itself when empty */
    public array $shopIds = [];

    public function id(): int
    {
        return $this->id;
    }

    public function shopIds(): array
    {
        return $this->shopIds !== [] ? $this->shopIds : [$this->id];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function webhookUrl(): string
    {
        return $this->webhookUrl;
    }

    public function cronUrl(string $token): string
    {
        return 'https://boutique.cm/module/wajub/cron?token=' . $token;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    public function moduleVersion(): string
    {
        return '1.0.0';
    }

    public function prestashopVersion(): string
    {
        return '8.2.8';
    }
}

/** The Mailer, keeping what it would send. */
final class FakeMailer implements Mailer
{
    /** @var array<int, string> by shop id; a shop missing has no e-mail address */
    public array $emails = [1 => 'merchant@boutique.cm'];

    /** @var list<array{shop: int, to: string, subject: string, template: string, vars: array<string, string>}> */
    public array $sent = [];

    public bool $fails = false;

    public function shopEmail(int $idShop): string
    {
        return $this->emails[$idShop] ?? '';
    }

    public function translator(int $idShop): Translator
    {
        return new TestTranslator();
    }

    public function send(int $idShop, string $to, string $subject, string $template, array $vars): bool
    {
        $this->sent[] = ['shop' => $idShop, 'to' => $to, 'subject' => $subject, 'template' => $template, 'vars' => $vars];

        return !$this->fails;
    }
}

/** The webhook's EventStore in memory. */
final class MemoryEventStore implements EventStore
{
    /** @var array<string, array{status: string, attempts: int, error: string, shop: int, environment: string, type: string, object: string}> */
    public array $events = [];

    /** Answered BUSY whatever the event, as while another request acts on it. */
    public bool $busy = false;

    public int $purged = 0;

    public function claim(string $eventId, int $idShop, string $environment, string $type, string $objectId): string
    {
        if ($this->busy) {
            return self::BUSY;
        }
        $event = $this->events[$eventId] ?? null;
        if ($event === null) {
            $this->events[$eventId] = ['status' => 'processing', 'attempts' => 1, 'error' => '', 'shop' => $idShop, 'environment' => $environment, 'type' => $type, 'object' => $objectId];

            return self::CLAIMED;
        }
        if (in_array($event['status'], [self::PROCESSED, self::IGNORED], true)) {
            return self::DUPLICATE;
        }
        if ($event['status'] === 'processing') {
            return self::BUSY;
        }
        $this->events[$eventId]['status'] = 'processing';
        ++$this->events[$eventId]['attempts'];

        return self::CLAIMED;
    }

    public function finish(string $eventId, string $status, string $error = ''): void
    {
        $this->events[$eventId]['status'] = $status;
        $this->events[$eventId]['error'] = $error;
    }

    public function release(string $eventId): void
    {
        if (($this->events[$eventId]['status'] ?? '') === 'processing') {
            unset($this->events[$eventId]);
        }
    }

    public function purge(int $days): void
    {
        ++$this->purged;
    }
}

/**
 * The module's services on stand-ins, for `scope`, with a fresh store of
 * settings unless `keep` is true. The stand-ins are reachable as
 * $GLOBALS['http'] (transport), ['store'] (state), ['logger'], ['translator'],
 * ['shop'], ['events'] and ['mailer']: names no runner uses for a variable
 * of its own, since a runner's top-level variables are globals too.
 *
 * @param array<string, mixed> $settings Configuration keys and values for all shops
 */
function services(array $settings = [], ?Scope $scope = null, bool $keep = false): Services
{
    if (!$keep) {
        ArrayConfigStore::$values = [];
        $GLOBALS['http'] = new FakeTransport();
        $GLOBALS['store'] = new MemoryStateStore();
        $GLOBALS['logger'] = new TestLogger();
        $GLOBALS['translator'] = new TestTranslator();
        $GLOBALS['shop'] = new FakeShop();
        $GLOBALS['events'] = new MemoryEventStore();
        $GLOBALS['mailer'] = new FakeMailer();
    }
    $scope = $scope ?? Scope::allShops();
    $store = new ArrayConfigStore($scope);
    foreach ($settings as $key => $value) {
        (new ArrayConfigStore())->set($key, (string) $value);
    }

    return new Services($scope, '1.0.0', [
        'transport' => $GLOBALS['http'],
        'state' => $GLOBALS['store'],
        'configStore' => $store,
        'cipher' => new FakeCipher(),
        'translator' => $GLOBALS['translator'],
        'logger' => $GLOBALS['logger'],
        'shop' => $GLOBALS['shop'],
        'events' => $GLOBALS['events'],
        'mailer' => $GLOBALS['mailer'],
        'pause' => static function (int $milliseconds): void {
            $GLOBALS['paused'][] = $milliseconds;
        },
    ]);
}

/** A key of the right shape for an environment. */
function key_for(string $environment, string $tag = 'A'): string
{
    $prefix = $environment === 'live' ? 'sk.' : 'sk_test.';

    return $prefix . str_pad($tag, 96, 'x');
}

/** Whether `$secret` shows anywhere in what is given. */
function leaked(string $secret, mixed ...$places): bool
{
    foreach ($places as $place) {
        if (str_contains(is_string($place) ? $place : (string) json_encode($place), $secret)) {
            return true;
        }
    }

    return false;
}

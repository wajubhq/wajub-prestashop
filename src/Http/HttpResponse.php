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
 * What a server answered: its status, its headers (names in lower case, the
 * last value of a repeated one) and its body as sent.
 */
final class HttpResponse
{
    /** @var int */
    public $status;

    /** @var array<string, string> */
    public $headers;

    /** @var string */
    public $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The body as JSON, or null when it is not a JSON object or list.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}

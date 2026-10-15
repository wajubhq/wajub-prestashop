<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wajub answered with an error, or did not answer (ApiConnectionException,
 * `httpStatus` 0). Callers read the status and `errorCode`, never the
 * message, which the API words as it likes: the message goes to the log,
 * and the merchant reads the module's own words.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $body the API's JSON answer, when it sent one
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'api_error',
        public readonly int $httpStatus = 0,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}

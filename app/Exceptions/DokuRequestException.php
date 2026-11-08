<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A DOKU call that failed after a classified boundary (B2B token or payment create/inquiry).
 *
 * Extends RuntimeException on purpose: adapters keep mapping it to the persisted `provider_unavailable` reason, while
 * the typed fields feed safe diagnostics. The message is a fixed string; provider bodies and messages never enter it.
 *
 * Diagnostic codes: doku_token_http_error, doku_token_response_invalid, doku_payment_http_error,
 * doku_payment_response_invalid, doku_internal_exception.
 */
class DokuRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $phase,
        public readonly string $diagnosticCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerResponseCode = null,
    ) {
        parent::__construct('Payment provider unavailable.');
    }
}

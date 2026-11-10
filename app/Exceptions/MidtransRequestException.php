<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Midtrans call that failed or returned something unusable.
 *
 * The message is a fixed string: provider bodies, messages and credentials never enter it. Only the typed fields feed
 * safe diagnostics.
 *
 * Diagnostic codes: midtrans_http_error, midtrans_response_invalid, midtrans_connection_error, midtrans_provider_rejected,
 * midtrans_provider_unauthorized.
 */
class MidtransRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $phase,
        public readonly string $diagnosticCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerStatusCode = null,
    ) {
        parent::__construct('Payment provider unavailable.');
    }
}

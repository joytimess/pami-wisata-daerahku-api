<?php

namespace App\Services\Doku;

use App\Exceptions\DokuRequestException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use OpenSSLAsymmetricKey;
use RuntimeException;

class DokuClient
{
    private const CREATE_PATH = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    private const STATUS_PATH = '/orders/v1.0/transfer-va/status';

    /** @var array{request: array<string, mixed>, response: ?array<string, mixed>}|null */
    private ?array $lastExchange = null;

    private ?int $lastHttpStatus = null;

    public function environment(): string
    {
        return (string) config('doku.environment');
    }

    /**
     * Global and merchant readiness for one channel. Missing parameters of one channel never disable another.
     */
    public function readinessReason(string $channelCode = 'va_bni'): ?string
    {
        return match (true) {
            ! $this->configured() => 'doku_not_configured',
            config('doku.enabled') !== true => 'doku_disabled',
            ! in_array($channelCode, config('doku.channels', []), true) => 'channel_unavailable',
            ! $this->merchantConfigured($channelCode) => 'doku_not_configured',
            default => null,
        };
    }

    /**
     * Bank-specific VA profile: DOKU channel name, merchant BIN/prefix and the notification CHANNEL-ID.
     * Values come only from server configuration; nothing is inferred from another bank or the Client ID.
     *
     * @return array{bank: string, doku_channel: string, partner_service_id: string, customer_prefix: string, notification_channel_id: string, acquirer: ?string}|null
     */
    public function vaBank(string $bank): ?array
    {
        $profiles = ['bni' => ['VIRTUAL_ACCOUNT_BNI', 'BNI'], 'bca' => ['VIRTUAL_ACCOUNT_BCA', null],
            'bri' => ['VIRTUAL_ACCOUNT_BRI', 'BRI'], 'mandiri' => ['VIRTUAL_ACCOUNT_BANK_MANDIRI', 'MANDIRI']];
        if (! isset($profiles[$bank])) {
            return null;
        }

        return ['bank' => $bank, 'doku_channel' => $profiles[$bank][0], 'acquirer' => $profiles[$bank][1],
            'partner_service_id' => (string) ($bank === 'bni' ? config('doku.partner_service_id') : config('doku.va_banks.'.$bank.'.partner_service_id')),
            'customer_prefix' => (string) ($bank === 'bni' ? config('doku.customer_prefix') : config('doku.va_banks.'.$bank.'.customer_prefix')),
            'notification_channel_id' => $bank === 'bni' ? 'VA004' : (string) config('doku.va_banks.'.$bank.'.notification_channel_id')];
    }

    /** Channel-specific merchant parameters; never inferred from the Client ID or documentation samples. */
    public function merchantConfigured(string $channelCode): bool
    {
        if ($channelCode === 'qris') {
            return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) config('doku.qris.merchant_id')) === 1
                && preg_match('/^[A-Za-z0-9]{3,16}$/D', (string) config('doku.qris.terminal_id')) === 1
                && preg_match('/^\d{1,5}$/D', (string) config('doku.qris.postal_code')) === 1;
        }
        if (in_array($channelCode, ['ewallet_dana', 'ewallet_shopeepay'], true)) {
            return app(DokuDirectDebitAdapter::class)->merchantConfigured(substr($channelCode, 8));
        }
        $profile = str_starts_with($channelCode, 'va_') ? $this->vaBank(substr($channelCode, 3)) : null;

        return $profile !== null
            && preg_match('/^\d{1,8}$/D', $profile['partner_service_id']) === 1
            && preg_match('/^\d{1,20}$/D', $profile['customer_prefix']) === 1
            && strlen($profile['partner_service_id'].$profile['customer_prefix']) <= 28;
    }

    public function configured(): bool
    {
        $url = parse_url((string) config('doku.webhook_url'));
        $host = strtolower($url['host'] ?? '');
        if (! in_array($this->environment(), ['sandbox', 'production'], true)
            || ! is_string(config('doku.client_id')) || config('doku.client_id') === ''
            || ! is_string(config('doku.secret_key')) || config('doku.secret_key') === ''
            || ($url['scheme'] ?? '') !== 'https' || $host === '' || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || empty($url['path'])
            || ! $this->privateKey()) {
            return false;
        }
        if ($this->environment() === 'production' && (app()->environment(['local', 'development', 'demo']) || config('app.debug') !== false
            || config('doku.production_approved') !== true || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || str_ends_with($host, '.lhr.life') || str_ends_with($host, '.localhost.run')
            || str_contains($host, 'ngrok') || str_ends_with($host, '.trycloudflare.com'))) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function create(object $attempt, object $booking): array
    {
        if ($attempt->environment !== $this->environment() || $attempt->provider !== 'doku') {
            return ['status' => 'unknown', 'reason' => 'payment_environment_mismatch'];
        }
        if (($attempt->method ?? 'virtual_account') === 'qris') {
            return app(DokuQrisAdapter::class)->create($this, $attempt, $booking);
        }
        if (($attempt->method ?? 'virtual_account') === 'ewallet') {
            return app(DokuDirectDebitAdapter::class)->create($this, $attempt, $booking);
        }
        $bank = $this->vaBank((string) $attempt->channel);
        if (($attempt->method ?? 'virtual_account') !== 'virtual_account' || $bank === null) {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        if ($reason = $this->readinessReason('va_'.$bank['bank'])) {
            return ['status' => 'unknown', 'reason' => $reason];
        }
        $expiry = CarbonImmutable::parse($attempt->instruction_expires_at ?? $booking->payment_expires_at, 'UTC');
        $body = [
            'partnerServiceId' => str_pad($bank['partner_service_id'], 8, ' ', STR_PAD_LEFT),
            'customerNo' => $bank['customer_prefix'],
            'virtualAccountNo' => $bank['partner_service_id'].$bank['customer_prefix'],
            'virtualAccountName' => mb_substr($booking->visitor_name, 0, 255),
            'trxId' => $attempt->merchant_reference,
            'totalAmount' => ['value' => $attempt->amount.'.00', 'currency' => $attempt->currency],
            'virtualAccountTrxType' => 'C', 'expiredDate' => $expiry->toIso8601String(),
            'additionalInfo' => ['channel' => $bank['doku_channel'], 'virtualAccountConfig' => ['reusableStatus' => false]],
        ];
        try {
            $response = $this->request(self::CREATE_PATH, $body, $attempt->provider_request_id, 'H2H', $this->diagnosticContext($attempt));
            if (($response['responseCode'] ?? '') !== '2002700') {
                $this->recordCreateSnapshot($this->diagnosticContext($attempt), 'doku_payment_not_accepted');

                return ['status' => 'unknown', 'reason' => 'provider_create_unresolved'];
            }
            $data = $response['virtualAccountData'] ?? [];
            if (! is_array($data)) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }
            $amount = $this->parseAmount($data['totalAmount'] ?? []);
            $providerExpiry = $this->parseInstant($data['expiredDate'] ?? null);
            if (($data['trxId'] ?? '') !== $attempt->merchant_reference || $amount !== (int) $attempt->amount
                || ($data['virtualAccountTrxType'] ?? '') !== 'C'
                || ($data['additionalInfo']['channel'] ?? '') !== $bank['doku_channel']
                || ! is_string($data['partnerServiceId'] ?? null) || trim($data['partnerServiceId']) !== $bank['partner_service_id']
                || ! $this->validVa($data) || ! str_starts_with($data['customerNo'], $bank['customer_prefix'])
                || ! $providerExpiry || $providerExpiry->gt($expiry) || $providerExpiry->lte(now())) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }

            return ['status' => 'pending', 'provider_reference' => $data['virtualAccountNo'],
                'expires_at' => $providerExpiry->utc()->toDateTimeString(),
                'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => $bank['bank'],
                    'va_number' => $data['virtualAccountNo'], 'copyable' => true,
                    '_provider' => ['partnerServiceId' => $data['partnerServiceId'], 'customerNo' => $data['customerNo'],
                        'virtualAccountNo' => $data['virtualAccountNo']]]];
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'reason' => 'provider_timeout'];
        } catch (RuntimeException|JsonException) {
            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
    }

    /** @return array<string, mixed> */
    public function status(object $attempt): array
    {
        if ($attempt->provider !== 'doku' || $attempt->environment !== $this->environment()) {
            return ['status' => 'unknown', 'reason' => 'payment_environment_mismatch'];
        }
        if (! $this->configured()) {
            return ['status' => 'unknown', 'reason' => 'doku_not_configured'];
        }
        if (($attempt->method ?? 'virtual_account') === 'qris') {
            return ['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified'];
        }
        if (($attempt->method ?? 'virtual_account') === 'ewallet') {
            return app(DokuDirectDebitAdapter::class)->status($this, $attempt);
        }
        $bank = $this->vaBank((string) $attempt->channel);
        if ($bank === null) {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        $instruction = $this->storedInstruction($attempt);
        $provider = $instruction['_provider'] ?? null;
        if (! is_array($provider) || ! $this->validVa($provider)) {
            // shortcut: status inquiry requires a returned VA; unknown creates without it need DOKU investigation.
            return ['status' => 'unknown', 'reason' => 'provider_reference_unavailable'];
        }
        try {
            $response = $this->request(self::STATUS_PATH, $provider + ['additionalInfo' => (object) []], (string) random_int(100000000000000000, 999999999999999999), 'H2H', $this->diagnosticContext($attempt));
            if (($response['responseCode'] ?? '') !== '2002600') {
                return ['status' => 'unknown', 'reason' => 'provider_status_unresolved'];
            }
            $data = $response['virtualAccountData'] ?? [];
            if (! is_array($data)) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }
            if (($response['additionalInfo']['trxId'] ?? '') !== $attempt->merchant_reference
                || ($bank['acquirer'] !== null && ($response['additionalInfo']['acquirer']['id'] ?? '') !== $bank['acquirer'])
                || ($data['virtualAccountNo'] ?? '') !== $provider['virtualAccountNo']
                || ($data['partnerServiceId'] ?? '') !== $provider['partnerServiceId']
                || ($data['customerNo'] ?? '') !== $provider['customerNo']) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }
            if (strtolower($data['paymentFlagReason']['english'] ?? '') === 'pending') {
                return ['status' => 'pending'];
            }

            // shortcut: undocumented terminal status shapes remain unresolved until verified against channel fixtures.
            return ['status' => 'unknown', 'reason' => 'provider_terminal_contract_unverified'];
        } catch (ConnectionException) {
            return ['status' => 'unknown', 'reason' => 'provider_timeout'];
        } catch (RuntimeException|JsonException) {
            return ['status' => 'unknown', 'reason' => 'provider_unavailable'];
        }
    }

    /** @return array<string, mixed> */
    public function verifyNotification(Request $request): array
    {
        abort_unless($this->configured(), 503, 'Payment provider is not configured.');
        $raw = $request->getContent();
        abort_unless(strlen($raw) <= 65536 && $request->isJson() && $request->method() === 'POST', 400, 'Invalid payment notification.');
        $timestamp = $request->header('X-TIMESTAMP', '');
        $instant = $this->parseInstant($timestamp);
        $token = $request->bearerToken();
        abort_unless($instant && abs($instant->getTimestamp() - now()->getTimestamp()) <= 300
            && $request->header('X-PARTNER-ID') === config('doku.client_id')
            && preg_match('/^\d{1,36}$/D', $request->header('X-EXTERNAL-ID', ''))
            && is_string($token) && $token !== '' && strlen($token) <= 4096, 401, 'Invalid payment signature.');
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'Invalid payment notification.');
        }
        abort_unless(is_array($data) && ! array_is_list($data), 400, 'Invalid payment notification.');
        $bank = $this->notificationBank($data, (string) $request->header('CHANNEL-ID', ''));
        $path = $request->getRequestUri();
        abort_unless($path === parse_url((string) config('doku.webhook_url'), PHP_URL_PATH), 401, 'Invalid payment signature.');
        $minified = preg_replace('/("(?:[^"\\\\]|\\\\.)*")|\s+/s', '$1', $raw);
        $expected = $this->signature($path, $token, $minified, $timestamp);
        abort_unless(hash_equals($expected, $request->header('X-SIGNATURE', '')), 401, 'Invalid payment signature.');
        $amount = $this->parseAmount($data['paidAmount'] ?? []);
        $occurred = $this->parseInstant($data['trxDateTime'] ?? null);
        abort_unless($amount !== null && $occurred && $occurred->lte(now()->addMinutes(5))
            && is_string($data['trxId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['trxId'])
            && is_string($data['paymentRequestId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,30}$/D', $data['paymentRequestId'])
            && is_string($data['partnerServiceId'] ?? null) && trim($data['partnerServiceId']) === $bank['partner_service_id'] && $this->validVa($data)
            && ($data['virtualAccountTrxType'] ?? '') === 'C'
            && ($data['additionalInfo']['channel'] ?? '') === $bank['doku_channel']
            && str_starts_with($data['customerNo'], $bank['customer_prefix']), 422, 'Invalid payment evidence.');
        $acknowledgement = ['responseCode' => '2002500', 'responseMessage' => 'Successful',
            'virtualAccountData' => array_intersect_key($data, array_flip(['partnerServiceId', 'customerNo', 'virtualAccountNo',
                'virtualAccountName', 'trxId', 'paymentRequestId', 'paidAmount', 'virtualAccountTrxType', 'additionalInfo']))];

        return ['status' => 'paid', 'environment' => $this->environment(), 'method' => 'virtual_account', 'channel' => $bank['bank'], 'amount' => $amount, 'currency' => 'IDR',
            'merchant_reference' => $data['trxId'], 'provider_reference' => $data['virtualAccountNo'],
            'occurred_at' => $occurred->utc()->toDateTimeString(), 'event_key' => 'va:'.hash('sha256', $data['trxId'].':'.$data['paymentRequestId']),
            'payload_hash' => hash('sha256', $raw), 'acknowledgement' => $acknowledgement];
    }

    /**
     * The route is trusted, the bank is not: the signed body names a channel, but it only counts when that bank is
     * configured to settle and DOKU's CHANNEL-ID header equals the value confirmed for that bank.
     *
     * @param  array<string, mixed>  $data
     * @return array{bank: string, doku_channel: string, partner_service_id: string, customer_prefix: string, notification_channel_id: string, acquirer: ?string}
     */
    private function notificationBank(array $data, string $channelId): array
    {
        $declared = $data['additionalInfo']['channel'] ?? null;
        foreach (['bni', 'bca', 'bri', 'mandiri'] as $bank) {
            $profile = $this->vaBank($bank);
            if (is_string($declared) && $profile['doku_channel'] === $declared) {
                $registry = app(DokuChannelRegistry::class);
                abort_unless($registry->staticReason('va_'.$bank) === null && $this->merchantConfigured('va_'.$bank)
                    && $profile['notification_channel_id'] !== '' && hash_equals($profile['notification_channel_id'], $channelId), 401, 'Invalid payment signature.');

                return $profile;
            }
        }
        abort(422, 'Invalid payment evidence.');
    }

    /** @return array<string, mixed> */
    public function instruction(object $attempt): array
    {
        $instruction = $this->storedInstruction($attempt);
        unset($instruction['_provider']);

        return $instruction;
    }

    private function privateKey(): OpenSSLAsymmetricKey|false
    {
        $path = config('doku.merchant_private_key_path');
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)
            || str_starts_with(strtolower((string) realpath($path)), strtolower(public_path()).DIRECTORY_SEPARATOR)) {
            return false;
        }

        $key = @openssl_pkey_get_private(file_get_contents($path), (string) config('doku.merchant_private_key_passphrase', ''));
        $details = $key ? openssl_pkey_get_details($key) : false;

        return $details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 ? $key : false;
    }

    private function transport(): PendingRequest
    {
        return Http::baseUrl($this->environment() === 'production' ? 'https://api.doku.com' : 'https://api-sandbox.doku.com')
            ->acceptJson()->connectTimeout(5)->timeout(max(1, min(30, (int) config('doku.http_timeout_seconds', 15))))
            ->withOptions(['allow_redirects' => false, 'verify' => true]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array{attempt_guid?: ?string, channel?: ?string}  $context  Safe correlation for diagnostics only.
     * @param  array{timezone?: string, headers?: array<string, string>}  $options  Diagnostic overrides: the zone of the request X-TIMESTAMP and extra headers.
     * @return array<string, mixed>
     */
    private function request(string $path, array $body, string $requestId, ?string $channelId = 'H2H', array $context = [], array $options = []): array
    {
        $this->lastHttpStatus = null;
        $tokenTimestamp = CarbonImmutable::now('UTC')->toIso8601String();
        $token = $this->accessToken($tokenTimestamp, $context);
        // The signature is computed from the exact string sent in X-TIMESTAMP.
        $timestamp = isset($options['timezone']) ? CarbonImmutable::now($options['timezone'])->format('Y-m-d\TH:i:sP') : $tokenTimestamp;
        $phase = str_starts_with($path, '/orders/') ? 'status_inquiry' : 'payment_create';
        $started = hrtime(true);
        try {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            $this->recordFailure($context, $phase, 'doku_internal_exception', ['exception_class' => $e::class, 'request_sent' => false, 'outcome_certainty' => 'not_issued']);
            throw $e;
        }
        $signature = $this->signature($path, $token, $encoded, $timestamp);
        $headers = ['X-PARTNER-ID' => (string) config('doku.client_id'), 'X-EXTERNAL-ID' => $requestId,
            'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => $signature, 'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json', 'Accept' => 'application/json']
            + ($channelId === null ? [] : ['CHANNEL-ID' => $channelId]) + ($options['headers'] ?? []);
        $this->lastExchange = null;
        if ($phase === 'payment_create') {
            $this->lastExchange = ['request' => app(DokuExchangeSanitizer::class)->request('POST', $path, $headers, $body, $this->secrets($token, $signature)), 'response' => null];
        }
        try {
            $response = $this->transport()->withToken($token)->withHeaders(array_diff_key($headers, ['Authorization' => 1, 'Content-Type' => 1, 'Accept' => 1]))
                ->withBody($encoded, 'application/json')->post($path);
        } catch (ConnectionException $e) {
            $this->recordFailure($context, $phase, 'doku_payment_transport_timeout', ['exception_class' => $e::class, 'request_sent' => 'unknown', 'duration_ms' => $this->elapsedMs($started)]);
            $this->recordCreateSnapshot($context, 'doku_payment_transport_timeout');
            throw $e;
        }
        $this->lastHttpStatus = $response->status();
        if ($this->lastExchange !== null) {
            $this->lastExchange['response'] = app(DokuExchangeSanitizer::class)->response($response->status(), $response->json(), $response->body(), $this->secrets($token, $signature));
        }
        if (! $response->successful()) {
            $failure = $this->failure($context, $phase, 'doku_payment_http_error', $started, $response->status(), $response->json('responseCode'));
            $this->recordCreateSnapshot($context, 'doku_payment_http_error');

            throw $failure;
        }
        if (! is_array($response->json())) {
            $failure = $this->failure($context, $phase, 'doku_payment_response_invalid', $started, $response->status());
            $this->recordCreateSnapshot($context, 'doku_payment_response_invalid');

            throw $failure;
        }

        return $response->json();
    }

    /** @return list<string> Credential values scrubbed from every diagnostic snapshot. */
    private function secrets(string $token, string $signature): array
    {
        return array_values(array_filter([$token, $signature, (string) config('doku.secret_key')], fn (string $value) => $value !== ''));
    }

    /**
     * Persists the sanitized snapshot of the last payment-create exchange to the dedicated diagnostics channel. Adapters call
     * this when DOKU answered but the create still did not succeed; request() calls it for its own failures.
     *
     * @param  array{attempt_guid?: ?string, channel?: ?string}  $context
     */
    public function recordCreateSnapshot(array $context, string $diagnosticCode): void
    {
        $exchange = $this->lastExchange;
        $this->lastExchange = null;
        if ($exchange === null) {
            return;
        }
        $guid = $context['attempt_guid'] ?? null;
        try {
            Log::channel('doku_diagnostics')->warning('DOKU create failure snapshot.', [
                'event' => 'doku.create_failure_snapshot',
                'attempt_guid' => is_string($guid) && Str::isUuid($guid) ? $guid : null,
                'environment' => $this->environment(),
                'channel' => $context['channel'] ?? null,
                'diagnostic_code' => $diagnosticCode,
                'request' => $exchange['request'],
                'response' => $exchange['response'],
            ]);
        } catch (\Throwable) {
            // Diagnostics must never change the payment outcome.
        }
    }

    /**
     * B2B access token. A failure here means the payment request was never dispatched.
     *
     * @param  array{attempt_guid?: ?string, channel?: ?string}  $context
     */
    private function accessToken(string $timestamp, array $context): string
    {
        $started = hrtime(true);
        if (! openssl_sign(config('doku.client_id').'|'.$timestamp, $signed, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw $this->failure($context, 'b2b_token', 'doku_internal_exception', $started, requestSent: false);
        }
        try {
            $response = $this->transport()->withHeaders(['X-CLIENT-KEY' => config('doku.client_id'),
                'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode($signed)])
                ->post('/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials']);
        } catch (ConnectionException $e) {
            $this->recordFailure($context, 'b2b_token', 'doku_token_transport_error', ['exception_class' => $e::class, 'request_sent' => 'unknown', 'duration_ms' => $this->elapsedMs($started)]);
            throw $e;
        }
        if (! $response->successful()) {
            throw $this->failure($context, 'b2b_token', 'doku_token_http_error', $started, $response->status(), $response->json('responseCode'));
        }
        $token = $response->json('accessToken');
        if ($response->json('responseCode') !== '2007300' || $response->json('tokenType') !== 'Bearer' || ! is_string($token) || $token === '') {
            throw $this->failure($context, 'b2b_token', 'doku_token_response_invalid', $started, $response->status(), $response->json('responseCode'));
        }

        return $token;
    }

    /**
     * Signed SNAP call for channel adapters. Pass a null CHANNEL-ID for products whose pages do not list that header.
     *
     * @param  array<string, mixed>  $body
     * @param  array{attempt_guid?: ?string, channel?: ?string}  $context
     * @param  array{timezone?: string, headers?: array<string, string>}  $options
     * @return array<string, mixed>
     */
    public function signedRequest(string $path, array $body, string $requestId, ?string $channelId = 'H2H', array $context = [], array $options = []): array
    {
        return $this->request($path, $body, $requestId, $channelId, $context, $options);
    }

    /** HTTP status of the last signed request that got an answer; null before it or after a transport failure. */
    public function lastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    /** @return array{attempt_guid: ?string, channel: ?string} */
    public function diagnosticContext(object $attempt): array
    {
        return ['attempt_guid' => $attempt->guid ?? null, 'channel' => $attempt->channel ?? null];
    }

    /** Provider response codes are surfaced only when they look like a code; HTML, prose and secrets are dropped. */
    public function safeProviderCode(mixed $code): ?string
    {
        return (is_string($code) || is_int($code)) && preg_match('/^[A-Za-z0-9_-]{1,32}$/D', (string) $code) === 1 ? (string) $code : null;
    }

    /**
     * Writes one bounded diagnostic record. Only allow-listed scalar fields are logged: never bodies, headers, tokens,
     * signatures, URLs, provider messages or exception messages.
     *
     * @param  array{attempt_guid?: ?string, channel?: ?string}  $context
     * @param  array{http_status?: ?int, provider_response_code?: mixed, exception_class?: ?string, duration_ms?: ?int, request_sent?: bool|string, outcome_certainty?: string}  $details
     */
    public function recordFailure(array $context, string $phase, string $diagnosticCode, array $details = []): void
    {
        $guid = $context['attempt_guid'] ?? null;
        $channel = $context['channel'] ?? null;
        Log::warning('DOKU provider request failed.', [
            'event' => 'doku.provider_request_failed',
            'attempt_guid' => is_string($guid) && Str::isUuid($guid) ? $guid : null,
            'provider' => 'doku',
            'environment' => $this->environment(),
            'channel' => is_string($channel) && preg_match('/^[a-z0-9_]{1,32}$/D', $channel) === 1 ? $channel : null,
            'phase' => $phase,
            'http_status' => isset($details['http_status']) ? (int) $details['http_status'] : null,
            'provider_response_code' => $this->safeProviderCode($details['provider_response_code'] ?? null),
            'diagnostic_code' => $diagnosticCode,
            'exception_class' => $details['exception_class'] ?? null,
            'duration_ms' => $details['duration_ms'] ?? null,
            'request_sent' => $details['request_sent'] ?? true,
            'outcome_certainty' => $details['outcome_certainty'] ?? ($phase === 'b2b_token' ? 'not_issued' : 'unknown'),
        ]);
    }

    /** @param array{attempt_guid?: ?string, channel?: ?string} $context */
    private function failure(array $context, string $phase, string $code, int $startedAt, ?int $httpStatus = null, mixed $providerCode = null, bool $requestSent = true): DokuRequestException
    {
        $this->recordFailure($context, $phase, $code, ['http_status' => $httpStatus, 'provider_response_code' => $providerCode,
            'duration_ms' => $this->elapsedMs($startedAt), 'request_sent' => $requestSent]);

        return new DokuRequestException($phase, $code, $httpStatus, $this->safeProviderCode($providerCode));
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1000000);
    }

    private function signature(string $path, string $token, string $body, string $timestamp): string
    {
        return base64_encode(hash_hmac('sha512', 'POST:'.$path.':'.$token.':'.hash('sha256', $body).':'.$timestamp, config('doku.secret_key'), true));
    }

    /** @return array<string, mixed> */
    private function storedInstruction(object $attempt): array
    {
        if (empty($attempt->instruction_ciphertext)) {
            return ['presentation_type' => 'unavailable'];
        }
        try {
            $data = json_decode(Crypt::decryptString($attempt->instruction_ciphertext), true, 32, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : ['presentation_type' => 'unavailable'];
        } catch (DecryptException|JsonException) {
            return ['presentation_type' => 'unavailable'];
        }
    }

    private function validVa(mixed $data): bool
    {
        return is_array($data) && is_string($data['virtualAccountNo'] ?? null) && preg_match('/^\d{1,28}$/D', $data['virtualAccountNo'])
            && is_string($data['partnerServiceId'] ?? null) && is_string($data['customerNo'] ?? null)
            && preg_match('/^\d{1,20}$/D', $data['customerNo'])
            && $data['virtualAccountNo'] === trim($data['partnerServiceId']).$data['customerNo'];
    }

    public function parseAmount(mixed $amount): ?int
    {
        return is_array($amount) && ($amount['currency'] ?? '') === 'IDR' && is_string($amount['value'] ?? null)
            && preg_match('/^[1-9]\d{0,13}\.00$/D', $amount['value']) ? (int) substr($amount['value'], 0, -3) : null;
    }

    public function parseInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}

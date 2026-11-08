<?php

namespace App\Services\Doku;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use JsonException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use UnexpectedValueException;

class DokuClient
{
    private const CREATE_PATH = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    private const STATUS_PATH = '/orders/v1.0/transfer-va/status';

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

    /** Channel-specific merchant parameters; never inferred from the Client ID or documentation samples. */
    public function merchantConfigured(string $channelCode): bool
    {
        return match ($channelCode) {
            'va_bni' => preg_match('/^\d{1,8}$/D', (string) config('doku.partner_service_id')) === 1
                && preg_match('/^\d{1,20}$/D', (string) config('doku.customer_prefix')) === 1,
            default => false,
        };
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
        if (($attempt->method ?? 'virtual_account') !== 'virtual_account' || $attempt->channel !== 'bni') {
            return ['status' => 'unknown', 'reason' => 'channel_not_implemented'];
        }
        if ($reason = $this->readinessReason('va_bni')) {
            return ['status' => 'unknown', 'reason' => $reason];
        }
        $expiry = CarbonImmutable::parse($attempt->instruction_expires_at ?? $booking->payment_expires_at, 'UTC');
        $body = [
            'partnerServiceId' => str_pad((string) config('doku.partner_service_id'), 8, ' ', STR_PAD_LEFT),
            'customerNo' => (string) config('doku.customer_prefix'),
            'virtualAccountNo' => (string) config('doku.partner_service_id').config('doku.customer_prefix'),
            'virtualAccountName' => mb_substr($booking->visitor_name, 0, 255),
            'trxId' => $attempt->merchant_reference,
            'totalAmount' => ['value' => $attempt->amount.'.00', 'currency' => $attempt->currency],
            'virtualAccountTrxType' => 'C', 'expiredDate' => $expiry->toIso8601String(),
            'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BNI', 'virtualAccountConfig' => ['reusableStatus' => false]],
        ];
        try {
            $response = $this->request(self::CREATE_PATH, $body, $attempt->provider_request_id);
            if (($response['responseCode'] ?? '') !== '2002700') {
                return ['status' => 'unknown', 'reason' => 'provider_create_unresolved'];
            }
            $data = $response['virtualAccountData'] ?? [];
            if (! is_array($data)) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }
            $amount = $this->amount($data['totalAmount'] ?? []);
            $providerExpiry = $this->instant($data['expiredDate'] ?? null);
            if (($data['trxId'] ?? '') !== $attempt->merchant_reference || $amount !== (int) $attempt->amount
                || ($data['virtualAccountTrxType'] ?? '') !== 'C'
                || ($data['additionalInfo']['channel'] ?? '') !== 'VIRTUAL_ACCOUNT_BNI'
                || ! is_string($data['partnerServiceId'] ?? null) || trim($data['partnerServiceId']) !== (string) config('doku.partner_service_id')
                || ! $this->validVa($data) || ! str_starts_with($data['customerNo'], (string) config('doku.customer_prefix'))
                || ! $providerExpiry || $providerExpiry->gt($expiry) || $providerExpiry->lte(now())) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }

            return ['status' => 'pending', 'provider_reference' => $data['virtualAccountNo'],
                'expires_at' => $providerExpiry->utc()->toDateTimeString(),
                'instruction' => ['presentation_type' => 'virtual_account', 'bank_code' => 'bni',
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
        $instruction = $this->storedInstruction($attempt);
        $provider = $instruction['_provider'] ?? null;
        if (! is_array($provider) || ! $this->validVa($provider)) {
            // shortcut: status inquiry requires a returned VA; unknown creates without it need DOKU investigation.
            return ['status' => 'unknown', 'reason' => 'provider_reference_unavailable'];
        }
        try {
            $response = $this->request(self::STATUS_PATH, $provider + ['additionalInfo' => (object) []], (string) random_int(100000000000000000, 999999999999999999));
            if (($response['responseCode'] ?? '') !== '2002600') {
                return ['status' => 'unknown', 'reason' => 'provider_status_unresolved'];
            }
            $data = $response['virtualAccountData'] ?? [];
            if (! is_array($data)) {
                return ['status' => 'unknown', 'reason' => 'provider_response_mismatch'];
            }
            if (($response['additionalInfo']['trxId'] ?? '') !== $attempt->merchant_reference
                || ($response['additionalInfo']['acquirer']['id'] ?? '') !== 'BNI'
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
        $instant = $this->instant($timestamp);
        $token = $request->bearerToken();
        abort_unless($instant && abs($instant->getTimestamp() - now()->getTimestamp()) <= 300
            && $request->header('X-PARTNER-ID') === config('doku.client_id')
            && $request->header('CHANNEL-ID') === 'VA004'
            && preg_match('/^\d{1,36}$/D', $request->header('X-EXTERNAL-ID', ''))
            && is_string($token) && $token !== '' && strlen($token) <= 4096, 401, 'Invalid payment signature.');
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'Invalid payment notification.');
        }
        abort_unless(is_array($data) && ! array_is_list($data), 400, 'Invalid payment notification.');
        $path = $request->getRequestUri();
        abort_unless($path === parse_url((string) config('doku.webhook_url'), PHP_URL_PATH), 401, 'Invalid payment signature.');
        $minified = preg_replace('/("(?:[^"\\\\]|\\\\.)*")|\s+/s', '$1', $raw);
        $expected = $this->signature($path, $token, $minified, $timestamp);
        abort_unless(hash_equals($expected, $request->header('X-SIGNATURE', '')), 401, 'Invalid payment signature.');
        $amount = $this->amount($data['paidAmount'] ?? []);
        $occurred = $this->instant($data['trxDateTime'] ?? null);
        abort_unless($amount !== null && $occurred && $occurred->lte(now()->addMinutes(5))
            && is_string($data['trxId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['trxId'])
            && is_string($data['paymentRequestId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,30}$/D', $data['paymentRequestId'])
            && is_string($data['partnerServiceId'] ?? null) && trim($data['partnerServiceId']) === (string) config('doku.partner_service_id') && $this->validVa($data)
            && ($data['virtualAccountTrxType'] ?? '') === 'C'
            && ($data['additionalInfo']['channel'] ?? '') === 'VIRTUAL_ACCOUNT_BNI', 422, 'Invalid payment evidence.');
        $acknowledgement = ['responseCode' => '2002500', 'responseMessage' => 'Successful',
            'virtualAccountData' => array_intersect_key($data, array_flip(['partnerServiceId', 'customerNo', 'virtualAccountNo',
                'virtualAccountName', 'trxId', 'paymentRequestId', 'paidAmount', 'virtualAccountTrxType', 'additionalInfo']))];

        return ['status' => 'paid', 'environment' => $this->environment(), 'amount' => $amount, 'currency' => 'IDR',
            'merchant_reference' => $data['trxId'], 'provider_reference' => $data['virtualAccountNo'],
            'occurred_at' => $occurred->utc()->toDateTimeString(), 'event_key' => 'va:'.hash('sha256', $data['trxId'].':'.$data['paymentRequestId']),
            'payload_hash' => hash('sha256', $raw), 'acknowledgement' => $acknowledgement];
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

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $path, array $body, string $requestId): array
    {
        $timestamp = CarbonImmutable::now('UTC')->toIso8601String();
        if (! openssl_sign(config('doku.client_id').'|'.$timestamp, $signed, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Payment authentication unavailable.');
        }
        $tokenResponse = $this->transport()->withHeaders(['X-CLIENT-KEY' => config('doku.client_id'),
            'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode($signed)])
            ->post('/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials']);
        $token = $tokenResponse->json('accessToken');
        if (! $tokenResponse->successful() || $tokenResponse->json('responseCode') !== '2007300'
            || $tokenResponse->json('tokenType') !== 'Bearer' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Payment authentication unavailable.');
        }
        $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->transport()->withToken($token)->withHeaders([
            'X-PARTNER-ID' => config('doku.client_id'), 'X-EXTERNAL-ID' => $requestId, 'CHANNEL-ID' => 'H2H',
            'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => $this->signature($path, $token, $encoded, $timestamp),
        ])->withBody($encoded, 'application/json')->post($path);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new UnexpectedValueException('Payment provider unavailable.');
        }

        return $response->json();
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

    private function amount(mixed $amount): ?int
    {
        return is_array($amount) && ($amount['currency'] ?? '') === 'IDR' && is_string($amount['value'] ?? null)
            && preg_match('/^[1-9]\d{0,13}\.00$/D', $amount['value']) ? (int) substr($amount['value'], 0, -3) : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
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

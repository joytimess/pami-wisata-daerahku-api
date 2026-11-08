<?php

namespace App\Services\Doku;

/**
 * Builds the sanitized request/response snapshot kept for failed DOKU create calls.
 *
 * Everything the operator needs to reproduce a rejection is kept (method, path, X-EXTERNAL-ID, X-TIMESTAMP, full body,
 * non-secret headers, status and full response body). Credentials never are: the signature and bearer token are replaced
 * by a marker, secret-looking keys are masked, and the literal secret key, access token and signature are scrubbed out of
 * every string, so a provider that echoes them back cannot leak them either.
 */
class DokuExchangeSanitizer
{
    public const REDACTED = '[REDACTED]';

    private const MAX_RAW_BODY_BYTES = 8192;

    /** Headers that are safe to keep verbatim; every other header is dropped, credentials are shown only as present. */
    private const SAFE_HEADERS = ['X-PARTNER-ID', 'X-EXTERNAL-ID', 'X-TIMESTAMP', 'CHANNEL-ID', 'Content-Type', 'Accept', 'X-DEVICE-ID', 'X-IP-ADDRESS'];

    private const CREDENTIAL_HEADERS = ['Authorization', 'X-SIGNATURE'];

    /** Personal data that is not needed to debug a rejection. */
    private const PERSONAL_KEYS = ['virtualaccountname'];

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  list<string>  $secrets  Literal credential values to scrub from every string.
     * @return array{method: string, path: string, headers: array<string, string>, body: mixed}
     */
    public function request(string $method, string $path, array $headers, array $body, array $secrets): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), array_map('strtolower', self::SAFE_HEADERS), true)) {
                $safe[$name] = (string) $this->scrub($value, $secrets);
            } elseif (in_array(strtolower($name), array_map('strtolower', self::CREDENTIAL_HEADERS), true)) {
                $safe[$name] = self::REDACTED;
            }
        }

        return ['method' => $method, 'path' => $path, 'headers' => $safe, 'body' => $this->clean($body, $secrets, false)];
    }

    /**
     * @param  mixed  $json  Decoded JSON body, or null when the body was not JSON.
     * @param  list<string>  $secrets
     * @return array{status: int, body: mixed}
     */
    public function response(int $status, mixed $json, string $raw, array $secrets): array
    {
        if (is_array($json)) {
            return ['status' => $status, 'body' => $this->clean($json, $secrets, true)];
        }

        return ['status' => $status, 'body' => $this->scrub(mb_strcut($raw, 0, self::MAX_RAW_BODY_BYTES), $secrets)];
    }

    /**
     * @param  list<string>  $secrets
     */
    private function clean(mixed $value, array $secrets, bool $stripUrlQuery): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $clean[$key] = self::REDACTED;
                } elseif (is_string($key) && $stripUrlQuery && preg_match('/url$/i', $key) && is_string($item)) {
                    $clean[$key] = $this->scrub(preg_replace('/[?#].*$/s', '?'.self::REDACTED, $item), $secrets);
                } else {
                    $clean[$key] = $this->clean($item, $secrets, $stripUrlQuery);
                }
            }

            return $clean;
        }

        return $this->scrub($value, $secrets);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        return in_array(str_replace(['_', '-'], '', $normalized), self::PERSONAL_KEYS, true)
            || preg_match('/(secret|token|signature|password|passwd|authorization|credential|private|api_?key|cvv|cvc|otp|(^|[_-])pin($|[_-])|card_?(no|number))/', $normalized) === 1;
    }

    /**
     * @param  list<string>  $secrets
     */
    private function scrub(mixed $value, array $secrets): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $value = str_replace($secret, self::REDACTED, $value);
            }
        }

        return $value;
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ReverseGeocodingService
{
    /** @return array{latitude: float, longitude: float, formatted_address: ?string, provider: string} */
    public function reverse(float $latitude, float $longitude): array
    {
        $store = (string) config('geocoding.cache_store');
        abort_unless(in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis'], true), 503, 'Geocoding unavailable. Enter the address manually.');
        $url = (string) config('geocoding.reverse_url');
        $point = ['lat' => number_format($latitude, 7, '.', ''), 'lon' => number_format($longitude, 7, '.', '')];
        $key = 'geocoding:reverse:'.hash('sha256', $url.json_encode($point));

        try {
            $cache = Cache::store($store);
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return ['latitude' => $latitude, 'longitude' => $longitude] + $cached;
            }
            $lock = $cache->lock('geocoding:provider-lock', 15);
            abort_unless($lock->get(), 429, 'Geocoding busy. Enter the address manually.', ['Retry-After' => '1']);
            try {
                $cached = $cache->get($key);
                if (is_array($cached)) {
                    return ['latitude' => $latitude, 'longitude' => $longitude] + $cached;
                }
                $now = now()->getTimestampMs();
                $next = (int) $cache->get('geocoding:next-request-at', 0);
                abort_if($next > $now, 429, 'Geocoding rate limited. Enter the address manually.', ['Retry-After' => (string) max(1, (int) ceil(($next - $now) / 1000))]);
                // All workers share this timestamp and lock; no automatic provider retries.
                $cache->put('geocoding:next-request-at', $now + 1100, 3600);
                try {
                    $response = Http::acceptJson()->withUserAgent((string) config('geocoding.user_agent'))
                        ->connectTimeout(2)->timeout(max(1, min(5, (int) config('geocoding.timeout'))))
                        ->withOptions(['allow_redirects' => false])
                        ->get($url, $point + ['format' => 'jsonv2', 'accept-language' => 'id']);
                } catch (ConnectionException) {
                    $cache->put('geocoding:next-request-at', now()->getTimestampMs() + 60000, 3600);
                    abort(503, 'Geocoding unavailable. Enter the address manually.');
                }
                if (in_array($response->status(), [429, 503], true)) {
                    $delay = max(60, min(3600, (int) $response->header('Retry-After')));
                    $cache->put('geocoding:next-request-at', now()->getTimestampMs() + $delay * 1000, 3600);
                    abort($response->status(), 'Geocoding unavailable. Enter the address manually.', ['Retry-After' => (string) $delay]);
                }
                abort_unless($response->successful() || $response->notFound(), 502, 'Geocoding failed. Enter the address manually.');
                $address = $response->json('display_name');
                abort_if($response->successful() && (! is_array($response->json()) || ($address !== null && ! is_string($address))), 502, 'Geocoding returned an invalid address. Enter the address manually.');
                $result = ['formatted_address' => is_string($address) && trim($address) !== '' ? trim($address) : null, 'provider' => 'nominatim'];
                $cache->put($key, $result, max(60, (int) config('geocoding.cache_seconds')));

                return ['latitude' => $latitude, 'longitude' => $longitude] + $result;
            } finally {
                $lock->release();
            }
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            abort(503, 'Geocoding unavailable. Enter the address manually.');
        }
    }
}

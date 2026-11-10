<?php

namespace App\Http\Middleware;

use App\Services\Doku\DokuClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Time-boxed, read-only Sandbox observation of the DOKU notification endpoint.
 *
 * Records a sanitized description of every inbound notification (and the HTTP status it got) to the doku_diagnostics log,
 * BEFORE verification runs. It never rejects, alters or short-circuits a request: signature verification, channel gates and
 * the payment handler behave exactly as without it. Active only while the marker file holds a future expiry (at most one
 * hour ahead), the app is local/testing and DOKU is in sandbox.
 */
class ObserveDokuWebhook
{
    public const MARKER = 'framework/doku-webhook-observe';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->active()) {
            return $next($request);
        }
        $this->record('doku.webhook_observed', fn () => ['request' => app(DokuClient::class)->describeNotification($request)]);
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record('doku.webhook_answered', fn () => ['http_status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500,
                'exception' => $exception::class, 'reason' => $exception instanceof HttpExceptionInterface ? $this->safeMessage($exception->getMessage()) : null]);

            throw $exception;
        }
        $this->record('doku.webhook_answered', fn () => ['http_status' => $response->getStatusCode(), 'response_code' => $this->safeResponseCode($response)]);

        return $response;
    }

    private function active(): bool
    {
        try {
            $path = (string) (config('doku.webhook_observe_marker') ?: storage_path(self::MARKER));
            $expires = is_file($path) ? (int) trim((string) file_get_contents($path)) : 0;

            return $expires > time() && $expires <= time() + 3600 && app()->environment(['local', 'testing'])
                && config('doku.environment') === 'sandbox';
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  callable(): array<string, mixed>  $context */
    private function record(string $event, callable $context): void
    {
        try {
            Log::channel('doku_diagnostics')->warning('DOKU webhook observation.', ['event' => $event] + $context());
        } catch (Throwable) {
            // Observation must never change the notification outcome.
        }
    }

    private function safeMessage(string $message): ?string
    {
        return preg_match('/^[A-Za-z0-9 .,_-]{1,80}$/D', $message) === 1 ? $message : null;
    }

    private function safeResponseCode(Response $response): ?string
    {
        $decoded = json_decode((string) $response->getContent(), true);
        $code = is_array($decoded) ? ($decoded['responseCode'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $code) === 1 ? $code : null;
    }
}

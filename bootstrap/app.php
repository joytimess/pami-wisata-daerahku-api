<?php

use App\Exceptions\BookingException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['qr_payload']);
        $exceptions->report(function (Throwable $exception) {
            if (request()->is('api/v1/*/tickets*', 'api/v1/admin/ticket-check-ins/*')) {
                Log::error('Smart ticket request failed.', ['exception_type' => get_class($exception)]);

                return false;
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1/*/tickets*', 'api/v1/admin/ticket-check-ins/*')) {
                return null;
            }
            if ($exception instanceof ValidationException) {
                return response()->json(['message' => 'Data tidak valid.', 'errors' => $exception->errors()], 422);
            }
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode() : ($exception instanceof AuthenticationException ? 401 : 500);

            return response()->json(['message' => $status >= 500 ? 'Layanan tiket sedang tidak tersedia.' : 'Permintaan tiket tidak diizinkan atau tidak ditemukan.',
                'code' => $status >= 500 ? 'ticket_service_unavailable' : 'ticket_request_denied'], $status)
                ->header('Cache-Control', 'private, no-store');
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (BookingException $exception, Request $request) => $request->is('api/*') ? response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode] + ($exception->context ? ['context' => $exception->context] : []), 409) : null);
    })->create();

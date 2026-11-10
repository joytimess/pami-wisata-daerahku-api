<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DemoPaymentRequest;
use App\Http\Requests\DokuPaymentRequest;
use App\Http\Requests\MidtransPaymentRequest;
use App\Http\Requests\PaymentMethodsRequest;
use App\Http\Resources\OperationalResource;
use App\Services\DemoPaymentService;
use App\Services\DokuPaymentService;
use App\Services\MidtransPaymentService;
use App\Services\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentController extends Controller
{
    /**
     * Creates a payment attempt on the one active real gateway. The request class is resolved after the gateway is known so that
     * each provider validates its own channels; Midtrans, once enabled, never falls back to DOKU.
     */
    public function initiate(Request $request, string $booking, PaymentGateway $gateway): JsonResponse
    {
        $data = $gateway->activeProvider() === 'midtrans'
            ? app(MidtransPaymentService::class)->initiate($request->user(), $booking, app(MidtransPaymentRequest::class)->validated())
            : app(DokuPaymentService::class)->initiate($request->user(), $booking, app(DokuPaymentRequest::class)->validated());

        return (new OperationalResource($data))->response()->header('Cache-Control', 'private, no-store');
    }

    public function refreshDoku(Request $request, string $booking, string $attempt, DokuPaymentService $doku, PaymentService $payments): JsonResponse
    {
        $doku->refresh($request->user(), $booking, $attempt);

        return (new OperationalResource($payments->show($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function qrisImage(Request $request, string $booking, string $attempt, DokuPaymentService $service): Response
    {
        return response($service->qrisSvg($request->user(), $booking, $attempt), 200, ['Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function initiateDemo(DemoPaymentRequest $request, string $booking, DemoPaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->initiate($request->user(), $booking, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function simulateDemo(DemoPaymentRequest $request, string $booking, DemoPaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->simulate($request->user(), $booking, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function methods(PaymentMethodsRequest $request, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->methods($request->user(), $request->validated('booking_guid'))))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $booking, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->show($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function adminShow(Request $request, string $booking, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->adminShow($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}

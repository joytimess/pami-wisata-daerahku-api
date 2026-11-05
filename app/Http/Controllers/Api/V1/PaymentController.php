<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DemoPaymentRequest;
use App\Http\Requests\PaymentMethodsRequest;
use App\Http\Resources\OperationalResource;
use App\Services\DemoPaymentService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
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
}

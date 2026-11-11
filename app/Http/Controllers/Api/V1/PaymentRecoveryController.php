<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OperatorRecoveryRequest;
use App\Http\Resources\OperationalResource;
use App\Services\OperatorRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRecoveryController extends Controller
{
    public function __construct(private OperatorRecoveryService $recovery) {}

    public function queue(Request $request): JsonResponse
    {
        return $this->respond($this->recovery->queue($request->user()));
    }

    public function show(Request $request, string $booking): JsonResponse
    {
        return $this->respond($this->recovery->show($request->user(), $booking));
    }

    public function payment(OperatorRecoveryRequest $request, string $booking): JsonResponse
    {
        return $this->respond($this->recovery->recoverPayment($request->user(), $booking, $request->validated()));
    }

    public function refund(OperatorRecoveryRequest $request, string $booking, string $refund): JsonResponse
    {
        return $this->respond($this->recovery->recoverRefund($request->user(), $booking, $refund, $request->validated()));
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data): JsonResponse
    {
        return (new OperationalResource($data))->response()->header('Cache-Control', 'private, no-store');
    }
}

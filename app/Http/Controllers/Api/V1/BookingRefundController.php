<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRefundDecisionRequest;
use App\Http\Requests\BookingRefundRequest;
use App\Http\Requests\BookingRefundSettlementRequest;
use App\Http\Resources\OperationalResource;
use App\Services\BookingRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingRefundController extends Controller
{
    public function __construct(private BookingRefundService $refunds) {}

    public function store(BookingRefundRequest $request, string $booking): JsonResponse
    {
        return (new OperationalResource($this->refunds->request($request->user(), $booking, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, string $booking): JsonResponse
    {
        return (new OperationalResource($this->refunds->listFor($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function settleManually(BookingRefundSettlementRequest $request, string $booking, string $refund): JsonResponse
    {
        return (new OperationalResource($this->refunds->settleManually($request->user(), $booking, $refund, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function decide(BookingRefundDecisionRequest $request, string $booking, string $refund): JsonResponse
    {
        return (new OperationalResource($this->refunds->decide($request->user(), $booking, $refund, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}

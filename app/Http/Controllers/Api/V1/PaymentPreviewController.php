<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationalResource;
use App\Services\PaymentPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentPreviewController extends Controller
{
    public function __invoke(Request $request, string $booking, PaymentPreviewService $service): JsonResponse
    {
        return (new OperationalResource($service->show($request->user(), $booking)))->response()
            ->header('Cache-Control', 'private, no-store');
    }
}

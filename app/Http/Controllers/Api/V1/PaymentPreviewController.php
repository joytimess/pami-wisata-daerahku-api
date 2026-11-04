<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperationalResource;
use App\Services\PaymentPreviewService;
use Illuminate\Http\Request;

class PaymentPreviewController extends Controller
{
    public function __invoke(Request $request, string $booking, PaymentPreviewService $service): OperationalResource
    {
        return new OperationalResource($service->show($request->user(), $booking));
    }
}

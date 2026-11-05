<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialDashboardRequest;
use App\Http\Resources\OperationalResource;
use App\Services\FinancialAnalyticsService;
use Illuminate\Http\JsonResponse;

class FinancialDashboardController extends Controller
{
    public function __invoke(FinancialDashboardRequest $request, FinancialAnalyticsService $service): JsonResponse
    {
        return (new OperationalResource(['financial' => $service->summary($request->user(), $request->validated())]))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReverseGeocodingRequest;
use App\Services\ReverseGeocodingService;
use Illuminate\Http\JsonResponse;

class LocationGeocodingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ReverseGeocodingRequest $request, ReverseGeocodingService $service): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $service->reverse((float) $data['latitude'], (float) $data['longitude'])]);
    }
}

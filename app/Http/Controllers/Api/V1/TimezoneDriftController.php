<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BookingTimezoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimezoneDriftController extends Controller
{
    /** Superadmin: active bookings that still run on a different timezone than their destination now has. Read-only. */
    public function __invoke(Request $request, BookingTimezoneService $timezones): JsonResponse
    {
        return response()->json(['data' => ['bookings' => $timezones->driftList($request->user())]])->header('Cache-Control', 'private, no-store');
    }
}

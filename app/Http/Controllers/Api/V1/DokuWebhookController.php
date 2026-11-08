<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Doku\DokuClient;
use App\Services\DokuPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DokuWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, DokuClient $client, DokuPaymentService $payments): JsonResponse
    {
        $outcome = $client->verifyNotification($request);
        $payments->receive($outcome);

        return response()->json($outcome['acknowledgement'])->header('Cache-Control', 'private, no-store');
    }
}

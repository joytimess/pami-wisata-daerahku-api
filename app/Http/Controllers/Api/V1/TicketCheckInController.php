<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestinationAdminsRequest;
use App\Http\Requests\TicketScanRequest;
use App\Models\Destination;
use App\Services\TicketCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketCheckInController extends Controller
{
    public function __construct(private TicketCheckInService $checkIns) {}

    public function preview(TicketScanRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->checkIns->preview($request->user(), $request->validated('qr_payload'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function confirm(TicketScanRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->checkIns->confirm($request->user(), $request->validated('qr_payload'), $request->validated('idempotency_key'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function scope(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->checkIns->scannerScope($request->user())]);
    }

    public function admins(Request $request, Destination $destination): JsonResponse
    {
        return response()->json(['data' => $this->checkIns->admins($request->user(), $destination)]);
    }

    public function assign(DestinationAdminsRequest $request, Destination $destination): JsonResponse
    {
        return response()->json(['data' => $this->checkIns->assign($request->user(), $destination, $request->validated('user_guids'))]);
    }
}

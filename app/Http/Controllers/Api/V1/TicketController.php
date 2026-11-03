<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    public function index(Request $request, string $booking): JsonResponse
    {
        return response()->json(['data' => $this->tickets->listing($request->user(), $booking)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function qr(Request $request, string $booking, string $ticket): Response
    {
        return response($this->tickets->svg($request->user(), $booking, $ticket), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

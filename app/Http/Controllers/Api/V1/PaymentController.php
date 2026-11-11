<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DemoPaymentRequest;
use App\Http\Requests\MidtransPaymentRequest;
use App\Http\Requests\PaymentMethodsRequest;
use App\Http\Resources\OperationalResource;
use App\Services\DemoPaymentService;
use App\Services\Midtrans\MidtransClient;
use App\Services\MidtransPaymentService;
use App\Services\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Creates a payment attempt on Midtrans, the only real gateway. While DEMO is active, real payments are not offered.
     */
    public function initiate(Request $request, string $booking, PaymentGateway $gateway): JsonResponse
    {
        if ($gateway->activeProvider() !== 'midtrans') {
            app(PaymentService::class)->ownerBooking($request->user(), $booking);
            throw new BookingException('Payment channel is unavailable.', 'payment_provider_conflict');
        }
        $data = app(MidtransPaymentService::class)->initiate($request->user(), $booking, app(MidtransPaymentRequest::class)->validated());

        return (new OperationalResource($data))->response()->header('Cache-Control', 'private, no-store');
    }

    /**
     * Owner refresh of one attempt. Only a Midtrans attempt is checked with its provider. A historical DOKU or DEMO attempt is
     * read-only ledger data: its stored state is returned unchanged and no provider is contacted. Nothing creates a Charge.
     */
    public function refresh(Request $request, string $booking, string $attempt, MidtransPaymentService $midtrans, PaymentService $payments): JsonResponse
    {
        $payments->ownerBooking($request->user(), $booking);
        $provider = DB::table('payment_attempts as a')->join('payment_intents as i', 'i.guid', '=', 'a.payment_intent_guid')
            ->where('i.booking_guid', $booking)->where('a.guid', $attempt)->value('a.provider');
        abort_unless($provider !== null, 404);
        if ($provider === 'midtrans') {
            $midtrans->refresh($request->user(), $booking, $attempt);
        }

        return (new OperationalResource($payments->show($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    /** Authenticated QRIS PNG of a live Midtrans attempt. Anything else, including a historical DOKU attempt, is not found. */
    public function qrisImage(Request $request, string $booking, string $attempt): Response
    {
        $payments = app(PaymentService::class);
        $owned = $payments->ownerBooking($request->user(), $booking);
        $intent = DB::table('payment_intents')->where('booking_guid', $booking)->first();
        $stored = $intent ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->where('guid', $attempt)->first() : null;
        abort_unless($stored?->provider === 'midtrans', 404);
        $client = app(MidtransClient::class);
        abort_unless($intent->provider === 'midtrans' && $intent->status === 'processing' && $stored->status === 'pending'
            && $owned->status === 'awaiting_payment' && $stored->method === 'qris' && $stored->channel === 'qris'
            && $stored->presentation_type === 'qr_image' && $stored->environment === $intent->environment
            && $intent->environment === $client->environment() && $stored->instruction_expires_at && $owned->payment_expires_at
            && now()->lt($stored->instruction_expires_at) && now()->lt($owned->payment_expires_at)
            && (int) $stored->amount === (int) $intent->amount && (int) $intent->amount === (int) $owned->total_amount
            && $stored->currency === $intent->currency && $intent->currency === $owned->currency, 404);
        $png = $client->qrisPng($stored);
        // A slow provider download must not deliver an instruction after the cutoff or a concurrent settlement.
        $freshBooking = $payments->ownerBooking($request->user(), $booking);
        abort_unless($freshBooking->status === 'awaiting_payment' && now()->lt($freshBooking->payment_expires_at)
            && now()->lt($stored->instruction_expires_at)
            && DB::table('payment_intents')->where('guid', $intent->guid)->where('status', 'processing')->exists()
            && DB::table('payment_attempts')->where('guid', $attempt)->where('status', 'pending')->exists(), 404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function initiateDemo(DemoPaymentRequest $request, string $booking, DemoPaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->initiate($request->user(), $booking, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function simulateDemo(DemoPaymentRequest $request, string $booking, DemoPaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->simulate($request->user(), $booking, $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function methods(PaymentMethodsRequest $request, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->methods($request->user(), $request->validated('booking_guid'))))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $booking, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->show($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function adminShow(Request $request, string $booking, PaymentService $service): JsonResponse
    {
        return (new OperationalResource($service->adminShow($request->user(), $booking)))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingDecisionRequest;
use App\Http\Requests\BookingIntentRequest;
use App\Http\Requests\ListBookingsRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingIntentResource;
use App\Http\Resources\BookingResource;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(ListBookingsRequest $request): AnonymousResourceCollection
    {
        return BookingResource::collection($this->bookings->listing($request->user(), false, $request->validated()));
    }

    public function adminIndex(ListBookingsRequest $request): AnonymousResourceCollection
    {
        return BookingResource::collection($this->bookings->listing($request->user(), true, $request->validated()));
    }

    public function adminShow(BookingDecisionRequest $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->adminShow($request->user(), $booking));
    }

    public function store(StoreBookingRequest $request): BookingResource
    {
        return new BookingResource($this->bookings->create($request->user(), $request->validated()));
    }

    public function show(Request $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->show($request->user(), $booking));
    }

    public function cancel(Request $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->cancel($request->user(), $booking));
    }

    public function intent(BookingIntentRequest $request, string $key): BookingIntentResource
    {
        return new BookingIntentResource($this->bookings->intent($request->user(), $request->validated('key')));
    }

    public function abandonIntent(BookingIntentRequest $request, string $key): BookingIntentResource
    {
        return new BookingIntentResource($this->bookings->abandonIntent($request->user(), $request->validated('key')));
    }

    public function approve(BookingDecisionRequest $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->decide($request->user(), $booking, true, $request->validated('reason')));
    }

    public function reject(BookingDecisionRequest $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->decide($request->user(), $booking, false, $request->validated('reason')));
    }
}

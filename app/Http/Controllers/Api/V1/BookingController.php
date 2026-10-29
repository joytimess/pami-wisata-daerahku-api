<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookingDecisionRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BookingResource::collection($this->bookings->listing($request->user()));
    }

    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        return BookingResource::collection($this->bookings->listing($request->user(), true));
    }

    public function store(StoreBookingRequest $request): BookingResource
    {
        return new BookingResource($this->bookings->create($request->user(), $request->validated()));
    }

    public function show(Request $request, string $booking): BookingResource
    {
        return new BookingResource($this->bookings->show($request->user(), $booking));
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

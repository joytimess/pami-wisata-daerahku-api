<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\ProfileStatisticsResource;
use App\Http\Resources\ReviewResource;
use App\Models\Destination;
use App\Services\VisitService;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function __construct(private VisitService $visits) {}

    public function checkIn(Request $request, string $booking): BookingResource
    {
        return new BookingResource($this->visits->checkIn($request->user(), $booking));
    }

    public function review(ReviewRequest $request, string $booking): ReviewResource
    {
        return new ReviewResource($this->visits->review($request->user(), $booking, $request->validated()));
    }

    public function history(ReviewRequest $request)
    {
        return ReviewResource::collection($this->visits->history($request->user(), $request->validated()));
    }

    public function destinationReviews(ReviewRequest $request, Destination $destination)
    {
        return ReviewResource::collection($this->visits->destinationReviews($destination, $request->validated()));
    }

    public function statistics(Request $request): ProfileStatisticsResource
    {
        return new ProfileStatisticsResource($this->visits->statistics($request->user()));
    }
}

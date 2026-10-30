<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'booking_guid' => $this->when(! $request->routeIs('destinations.reviews'), $this->booking_guid),
            'destination_guid' => $this->destination_guid,
            'destination_name' => $this->destination_name,
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'created_at' => CarbonImmutable::parse($this->created_at, 'UTC')->toIso8601String(),
        ];
    }
}

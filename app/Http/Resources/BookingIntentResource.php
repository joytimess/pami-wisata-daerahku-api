<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource['key'],
            'state' => $this->resource['state'],
            'replay_safe' => $this->resource['replay_safe'],
            'key_expired' => $this->resource['key_expired'],
            'expires_at' => $this->resource['expires_at'],
            'booking' => $this->resource['booking'] ? new BookingResource($this->resource['booking']) : null,
        ];
    }
}

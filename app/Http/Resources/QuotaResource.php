<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuotaResource extends JsonResource
{
    public function toArray($request): array
    {
        $allocated = (int) ($this->allocated ?? 0);

        return [
            'guid' => $this->guid, 'destination_guid' => $this->destination_guid, 'visit_date' => $this->visit_date->format('Y-m-d'),
            'capacity' => $this->capacity, 'allocated' => $allocated, 'remaining' => max(0, $this->capacity - $allocated),
            'booking_opens_at' => $this->booking_opens_at->toIso8601String(), 'booking_closes_at' => $this->booking_closes_at->toIso8601String(),
        ];
    }
}

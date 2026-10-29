<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DestinationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'guid' => $this->guid, 'destination_category_guid' => $this->destination_category_guid,
            'category' => new CategoryResource($this->whenLoaded('category')), 'name' => $this->name,
            'location' => $this->location, 'description' => $this->description, 'ticket_price' => $this->ticket_price,
            'currency' => $this->currency, 'opening_hours' => $this->opening_hours, 'timezone' => $this->timezone,
            'image_path' => $this->image_path, 'is_active' => $this->is_active,
        ];
    }
}

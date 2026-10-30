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
            // Storage paths are internal. Clients load the picture from `image_url`, a path relative to the
            // API base URL (…/api/v1/) that is versioned so a replaced image is never served stale.
            'has_image' => (bool) $this->image_path,
            'image_url' => $this->image_path ? 'destinations/'.$this->guid.'/image?v='.($this->updated_at?->timestamp ?? 0) : null,
            'is_active' => $this->is_active,
        ];
    }
}

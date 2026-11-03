<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DestinationResource extends JsonResource
{
    public function toArray($request): array
    {
        $images = $this->images;
        $cover = $images->first();
        $prefix = ($request->is('api/v1/admin/*') || ! $this->is_active) ? 'admin/' : '';
        $coverUrl = $cover ? $prefix.'destinations/'.$this->guid.'/images/'.$cover->guid.'/display' : ($this->image_path ? $prefix.'destinations/'.$this->guid.'/image?v='.($this->updated_at?->timestamp ?? 0) : null);

        return [
            'guid' => $this->guid, 'destination_category_guid' => $this->destination_category_guid,
            'category' => new CategoryResource($this->whenLoaded('category')), 'name' => $this->name,
            'location' => $this->location, 'description' => $this->description, 'ticket_price' => $this->ticket_price,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'currency' => $this->currency, 'opening_hours' => $this->opening_hours, 'timezone' => $this->timezone,
            // Storage paths are internal. Clients load the picture from `image_url`, a path relative to the
            // API base URL (…/api/v1/) that is versioned so a replaced image is never served stale.
            'gallery_version' => (int) $this->gallery_version,
            'last_gallery_operation_key' => $this->when((int) ($request->user()?->role ?? 0) >= 1, $this->last_gallery_operation_key),
            'images' => $images->map(function ($image) use ($request): array {
                $image->setRelation('destination', $this->resource);

                return (new DestinationImageResource($image))->toArray($request);
            })->all(),
            'cover_image_url' => $coverUrl,
            'has_image' => $coverUrl !== null,
            'image_url' => $coverUrl,
            'is_active' => $this->is_active,
            'facilities' => FacilityResource::collection($this->whenLoaded('activeFacilities')),
            'rating_average' => $this->rating_average === null ? null : (float) $this->rating_average,
            'rating_count' => (int) $this->rating_count,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FacilityResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'guid' => $this->guid, 'name' => $this->name, 'slug' => $this->slug,
            'icon_key' => $this->icon_key,
            'is_active' => $this->when($request->routeIs('superadmin.facilities.*'), $this->is_active),
        ];
    }
}

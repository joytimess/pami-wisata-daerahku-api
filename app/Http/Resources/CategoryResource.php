<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['guid' => $this->guid, 'name' => $this->name, 'slug' => $this->slug, 'is_active' => $this->is_active];
    }
}

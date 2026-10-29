<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['guid' => $this->guid, 'destination' => new DestinationResource($this->whenLoaded('destination'))];
    }
}

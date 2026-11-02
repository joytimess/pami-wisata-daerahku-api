<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DestinationImageResource extends JsonResource
{
    public function toArray($request): array
    {
        $prefix = ($request->is('api/v1/admin/*') || ! $this->destination->is_active) ? 'admin/' : '';
        $base = $prefix.'destinations/'.$this->destination_guid.'/images/'.$this->guid.'/';

        return ['guid' => $this->guid, 'sequence' => $this->sequence, 'is_cover' => $this->sequence === 1, 'urls' => ['thumbnail' => $base.'thumbnail', 'display' => $base.'display', 'fullscreen' => $base.'fullscreen']];
    }
}

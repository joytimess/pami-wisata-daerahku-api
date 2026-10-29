<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingDocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return $this->resource;
    }
}

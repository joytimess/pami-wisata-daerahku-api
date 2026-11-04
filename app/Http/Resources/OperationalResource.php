<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }

    public function with(Request $request): array
    {
        return ['meta' => ['generated_at' => now()->utc()->toIso8601String()]];
    }
}

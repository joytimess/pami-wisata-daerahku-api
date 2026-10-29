<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['access_token' => $this->resource['access_token'], 'refresh_token' => $this->resource['refresh_token'], 'token_type' => 'Bearer', 'expires_in' => 900, 'user' => new UserResource($this->resource['user'])];
    }
}

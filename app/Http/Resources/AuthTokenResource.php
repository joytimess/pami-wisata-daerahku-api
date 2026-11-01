<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['access_token' => $this->resource['access_token'], 'refresh_token' => $this->resource['refresh_token'], 'token_type' => 'Bearer', 'expires_in' => 900, 'user' => new UserResource($this->resource['user'])] + (isset($this->resource['verification_required']) ? ['verification_required' => true, 'expires_in_seconds' => 600, 'resend_after_seconds' => 60, 'message' => 'Akun dibuat. Periksa email untuk kode verifikasi.'] : []);
    }
}

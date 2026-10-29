<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['guid' => $this->guid, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'role' => $this->role, 'status' => $this->status, 'email_verified_at' => $this->email_verified_at];
    }
}

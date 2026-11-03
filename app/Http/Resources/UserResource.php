<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['guid' => $this->guid, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'role' => $this->role, 'status' => $this->status, 'email_verified_at' => $this->email_verified_at,
            'avatar_source' => $this->avatar_source,
            'avatar_url' => $this->avatar_source === 'default' ? null : 'profile/avatar?v='.$this->avatar_version,
            'avatar_version' => $this->avatar_version,
            'google_avatar_available' => $this->google_avatar_snapshot_path !== null,
        ];
    }
}

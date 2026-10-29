<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['guid' => $this->guid, 'booking_guid' => $this->booking_guid, 'type' => $this->type, 'title' => $this->title, 'body' => $this->body, 'read_at' => $this->read_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}

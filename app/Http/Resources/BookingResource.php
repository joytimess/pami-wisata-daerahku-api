<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid' => $this->guid,
            'booking_number' => $this->booking_number,
            'destination_guid' => $this->destination_guid,
            'visit_date' => $this->visit_date,
            'destination_name' => $this->destination_name_snapshot,
            'quantity' => $this->quantity,
            'visitor_name' => $this->visitor_name,
            'visitor_email' => $this->visitor_email,
            'visitor_phone' => $this->visitor_phone,
            'unit_price' => $this->unit_price,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'status' => $this->status,
            'cancellable' => (bool) ($this->cancellable ?? false),
            'ticket_count' => (int) ($this->ticket_count ?? $this->quantity),
            'checked_in_count' => (int) ($this->checked_in_count ?? 0),
            'has_checked_in' => (bool) ($this->has_checked_in ?? false),
            'all_checked_in' => (bool) ($this->all_checked_in ?? false),
            'is_partially_checked_in' => (bool) ($this->is_partially_checked_in ?? false),
            'is_fully_checked_in' => (bool) ($this->is_fully_checked_in ?? false),
            'payment_status' => $this->payment_status ?? 'unverified',
            'checked_in_at' => $this->checked_in_at ?? null,
            'can_check_in' => (bool) ($this->can_check_in ?? false),
            'can_review' => (bool) ($this->can_review ?? false) && $request->user()?->guid === $this->user_guid,
            'review' => isset($this->review) ? new ReviewResource($this->review) : null,
            'approval_expires_at' => $this->approval_expires_at,
            'payment_expires_at' => $this->payment_expires_at,
            'notification_grace_expires_at' => $this->notification_grace_expires_at,
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotaService
{
    public function list(Destination $destination, array $filters)
    {
        return DestinationQuota::where('destination_guid', $destination->guid)->orderBy('visit_date')->paginate($filters['per_page'] ?? 15);
    }

    public function upsert(Destination $destination, array $data): DestinationQuota
    {
        $data['booking_opens_at'] = CarbonImmutable::parse($data['booking_opens_at'])->utc();
        $data['booking_closes_at'] = CarbonImmutable::parse($data['booking_closes_at'])->utc();

        return DB::transaction(function () use ($destination, $data) {
            // Serializes creation of a previously absent quota row.
            Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail();
            $quota = DestinationQuota::where('destination_guid', $destination->guid)->whereDate('visit_date', $data['visit_date'])->lockForUpdate()->first();
            if ($quota) {
                $allocated = DB::table('bookings')->where('destination_quota_guid', $quota->guid)
                    ->whereIn('status', ['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'confirmed'])->sum('quantity');
                if ($data['capacity'] < $allocated) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than allocated visitors.']);
                }
            }
            $quota ??= new DestinationQuota(['destination_guid' => $destination->guid]);
            $quota->fill($data)->save();

            return $quota;
        }, 3);
    }
}

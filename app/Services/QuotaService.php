<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationQuota;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotaService
{
    public function list(Destination $destination, array $filters)
    {
        return DestinationQuota::where('destination_guid', $destination->guid)
            ->select('destination_quotas.*')->selectSub($this->allocatedSub(), 'allocated')
            ->orderBy('visit_date')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * User-facing availability computed on the server from real booking allocations.
     * Exposes aggregates only; no booker data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availability(Destination $destination, array $filters): array
    {
        $today = CarbonImmutable::now($destination->timezone)->startOfDay();
        $query = DestinationQuota::where('destination_guid', $destination->guid)
            ->select('destination_quotas.*')->selectSub($this->allocatedSub(), 'allocated');
        if (! empty($filters['visit_date'])) {
            $query->where('visit_date', $filters['visit_date']);
        } else {
            $from = $filters['from'] ?? $today->toDateString();
            $to = $filters['to'] ?? CarbonImmutable::parse($from)->addDays(60)->toDateString();
            $query->where('visit_date', '>=', $from)->where('visit_date', '<=', $to);
        }
        $rows = $query->orderBy('visit_date')->limit(200)->get();
        $now = CarbonImmutable::now();
        $result = $rows->map(fn (DestinationQuota $quota) => $this->availabilityRow($quota, $destination, $now))->all();
        if (empty($result) && ! empty($filters['visit_date'])) {
            $result[] = [
                'visit_date' => $filters['visit_date'], 'capacity' => 0, 'allocated' => 0, 'remaining' => 0,
                'status' => 'unavailable', 'booking_opens_at' => null, 'booking_closes_at' => null, 'timezone' => $destination->timezone,
            ];
        }

        return $result;
    }

    private function availabilityRow(DestinationQuota $quota, Destination $destination, CarbonImmutable $now): array
    {
        $allocated = (int) $quota->allocated;
        $remaining = max(0, $quota->capacity - $allocated);
        $visitStart = BookingRules::visitStart($quota->visit_date->format('Y-m-d'), $destination->timezone);
        $status = match (true) {
            $now->lt($quota->booking_opens_at) => 'not_open_yet',
            $now->gte($quota->booking_closes_at) => 'closed',
            BookingRules::tooCloseToCreate($now, $visitStart) => 'too_close',
            $remaining <= 0 => 'full',
            default => 'open',
        };

        return [
            'visit_date' => $quota->visit_date->format('Y-m-d'), 'capacity' => $quota->capacity, 'allocated' => $allocated,
            'remaining' => $remaining, 'status' => $status,
            'booking_opens_at' => $quota->booking_opens_at->toIso8601String(), 'booking_closes_at' => $quota->booking_closes_at->toIso8601String(),
            'timezone' => $destination->timezone,
        ];
    }

    public function upsert(Destination $destination, array $data): DestinationQuota
    {
        $data['booking_opens_at'] = CarbonImmutable::parse($data['booking_opens_at'])->utc();
        $data['booking_closes_at'] = CarbonImmutable::parse($data['booking_closes_at'])->utc();
        if ($data['booking_closes_at']->gt(BookingRules::visitStart($data['visit_date'], $destination->timezone))) {
            throw ValidationException::withMessages(['booking_closes_at' => 'The booking window must close before the visit day starts.']);
        }

        return DB::transaction(function () use ($destination, $data) {
            // Serializes creation of a previously absent quota row.
            Destination::whereKey($destination->guid)->lockForUpdate()->firstOrFail();
            $quota = DestinationQuota::where('destination_guid', $destination->guid)->whereDate('visit_date', $data['visit_date'])->lockForUpdate()->first();
            $allocated = 0;
            if ($quota) {
                $allocated = (int) DB::table('bookings')->where('destination_quota_guid', $quota->guid)
                    ->whereIn('status', BookingRules::HELD)->sum('quantity');
                if ($data['capacity'] < $allocated) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than allocated visitors.']);
                }
            }
            $quota ??= new DestinationQuota(['destination_guid' => $destination->guid]);
            $quota->fill($data)->save();
            $quota->setAttribute('allocated', $allocated);

            return $quota;
        }, 3);
    }

    private function allocatedSub(): Builder
    {
        return DB::table('bookings')->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('bookings.destination_quota_guid', 'destination_quotas.guid')
            ->whereIn('bookings.status', BookingRules::HELD);
    }
}

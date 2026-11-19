<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A booking keeps the destination timezone it was created with (`destination_timezone_snapshot`); it is the only timezone source for
 * that booking's deadlines. Changing a destination's timezone therefore only affects NEW bookings. This service reports which active
 * bookings still run on a different zone than their destination now has (for manual handling) and backfills old rows.
 */
class BookingTimezoneService
{
    public const ACTIVE_STATUSES = ['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'confirmed'];

    /** Active bookings of a destination (visit today or later) whose snapshot differs from the given timezone. */
    public function activeDriftQuery(?string $destinationGuid = null, ?string $timezone = null)
    {
        return DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')
            ->join('destinations as d', 'd.guid', '=', 'q.destination_guid')
            ->whereIn('b.status', self::ACTIVE_STATUSES)->where('q.visit_date', '>=', now()->toDateString())
            ->whereNotNull('b.destination_timezone_snapshot')
            ->when($destinationGuid, fn ($query) => $query->where('d.guid', $destinationGuid))
            ->whereColumn('b.destination_timezone_snapshot', '!=', DB::raw($timezone === null ? 'd.timezone' : DB::getPdo()->quote($timezone)));
    }

    /** @return array{previous_timezone: string, timezone: string, active_bookings: int}|null null when the zone did not change */
    public function impactOfChange(string $destinationGuid, ?string $previous): ?array
    {
        $current = DB::table('destinations')->where('guid', $destinationGuid)->value('timezone');
        if ($previous === null || $current === null || $previous === $current) {
            return null;
        }

        return ['previous_timezone' => $previous, 'timezone' => $current,
            'active_bookings' => (int) $this->activeDriftQuery($destinationGuid, $current)->count()];
    }

    /** Superadmin read-only list for manual handling; deadlines already sent to the provider cannot be changed automatically. */
    public function driftList(User $actor, int $limit = 200): array
    {
        if ((int) $actor->role !== 2) {
            throw new AuthorizationException;
        }

        return $this->activeDriftQuery()->orderBy('q.visit_date')->orderBy('b.guid')->limit(min($limit, 200))
            ->get(['b.guid', 'b.booking_number', 'b.status', 'q.visit_date', 'd.guid as destination_guid', 'd.name as destination_name',
                'b.destination_timezone_snapshot as booking_timezone', 'd.timezone as destination_timezone'])
            ->map(fn ($row) => ['guid' => $row->guid, 'booking_number' => $row->booking_number, 'status' => $row->status,
                'visit_date' => substr((string) $row->visit_date, 0, 10), 'destination_guid' => $row->destination_guid,
                'destination_name' => $row->destination_name, 'booking_timezone' => $row->booking_timezone,
                'destination_timezone' => $row->destination_timezone])->all();
    }

    /** Fills missing snapshots from the destination's current timezone (only rows that never had one). Returns the affected count. */
    public function backfillSnapshots(bool $dryRun): int
    {
        $missing = DB::table('bookings as b')->join('destination_quotas as q', 'q.guid', '=', 'b.destination_quota_guid')
            ->join('destinations as d', 'd.guid', '=', 'q.destination_guid')->whereNull('b.destination_timezone_snapshot');
        $count = (int) (clone $missing)->count();
        if (! $dryRun && $count > 0) {
            (clone $missing)->orderBy('b.guid')->select(['b.guid', 'd.timezone'])->get()->each(
                fn ($row) => DB::table('bookings')->where('guid', $row->guid)->whereNull('destination_timezone_snapshot')
                    ->update(['destination_timezone_snapshot' => $row->timezone]));
        }

        return $count;
    }
}

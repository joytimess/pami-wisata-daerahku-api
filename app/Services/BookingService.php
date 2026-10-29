<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    private const HELD = ['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'confirmed'];

    public function __construct(private BookingNotificationService $notifications) {}

    public function create(User $user, array $input): object
    {
        $key = $input['idempotency_key'];
        unset($input['idempotency_key']);
        $input['quantity'] = (int) $input['quantity'];
        $input['notes'] = $input['notes'] ?? null;
        ksort($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $input, $key, $hash) {
            $now = now();
            DB::table('idempotency_records')->insertOrIgnore([
                'guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'operation' => 'booking.create',
                'key' => $key, 'request_hash' => $hash, 'status' => 'processing',
                'expires_at' => $now->copy()->addDays(7), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $record = DB::table('idempotency_records')->where('user_guid', $user->guid)
                ->where('operation', 'booking.create')->where('key', $key)->lockForUpdate()->first();
            if (! hash_equals($record->request_hash, $hash)) {
                throw new BookingException('Idempotency key already used with different input.');
            }
            if ($record->status === 'completed') {
                return json_decode($record->response_body, false, 512, JSON_THROW_ON_ERROR);
            }

            $quota = DB::table('destination_quotas')->where('destination_guid', $input['destination_guid'])
                ->where('visit_date', $input['visit_date'])->lockForUpdate()->first();
            if (! $quota) {
                throw new BookingException('Quota is unavailable.');
            }
            $destination = DB::table('destinations')->join('destination_categories', 'destination_categories.guid', '=', 'destinations.destination_category_guid')
                ->where('destinations.guid', $quota->destination_guid)
                ->select('destinations.*', 'destination_categories.is_active as category_active')->first();
            if (! $destination || ! $destination->is_active || ! $destination->category_active || $now->lt($quota->booking_opens_at) || $now->gte($quota->booking_closes_at)) {
                throw new BookingException('Destination or booking window is unavailable.');
            }
            // shortcut: provisional midnight cutoff, replace after the visit-time policy is decided.
            $visitStart = CarbonImmutable::parse($quota->visit_date, $destination->timezone)->startOfDay()->utc();
            if ($now->copy()->addHours(25)->addMinutes(15)->gte($visitStart)) {
                throw new BookingException('The visit date is too close to book.');
            }
            $allocated = DB::table('bookings')->where('destination_quota_guid', $quota->guid)
                ->whereIn('status', self::HELD)->lockForUpdate()->get(['quantity'])->sum('quantity');
            if ($allocated + $input['quantity'] > $quota->capacity) {
                throw new BookingException('Insufficient capacity.');
            }
            $price = (int) $destination->ticket_price;
            if ($price < 0 || $price > intdiv(PHP_INT_MAX, $input['quantity'])) {
                throw new BookingException('Invalid ticket price.');
            }
            $guid = (string) Str::uuid();
            DB::table('bookings')->insert([
                'guid' => $guid, 'user_guid' => $user->guid, 'destination_quota_guid' => $quota->guid,
                'booking_number' => 'BK-'.strtoupper(str_replace('-', '', $guid)), 'quantity' => $input['quantity'],
                'visitor_name' => $input['visitor_name'], 'visitor_email' => $input['visitor_email'],
                'visitor_phone' => $input['visitor_phone'], 'destination_name_snapshot' => $destination->name,
                'unit_price' => $price, 'total_amount' => $price * $input['quantity'], 'currency' => $destination->currency,
                'notes' => $input['notes'], 'status' => 'pending_approval', 'approval_expires_at' => $now->copy()->addDay(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->history($guid, $user->guid, null, 'pending_approval');
            $this->notifications->record($guid, $user->guid, 'created');
            $booking = $this->find($guid);
            DB::table('idempotency_records')->where('guid', $record->guid)->update([
                'status' => 'completed', 'response_reference' => $guid, 'response_body' => json_encode($booking, JSON_THROW_ON_ERROR), 'updated_at' => $now,
            ]);

            return $booking;
        }, 3);
    }

    public function listing(User $user, bool $admin = false): LengthAwarePaginator
    {
        if ($admin && $user->role < 1) {
            throw new AuthorizationException;
        }
        $query = $this->query()->orderByDesc('bookings.created_at')->orderBy('bookings.guid');
        if (! $admin) {
            $query->where('bookings.user_guid', $user->guid);
        }
        $page = $query->paginate(15);
        $page->getCollection()->transform(fn ($booking) => $this->format($booking));

        return $page;
    }

    public function show(User $user, string $guid): object
    {
        $query = $this->query()->where('bookings.guid', $guid)->where('bookings.user_guid', $user->guid);

        return $this->format($query->first() ?? throw new ModelNotFoundException);
    }

    public function decide(User $actor, string $guid, bool $approve, ?string $reason = null): object
    {
        if ($actor->role < 1) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $guid, $approve, $reason) {
            $ref = DB::table('bookings')->where('guid', $guid)->first(['destination_quota_guid']);
            if (! $ref) {
                throw new ModelNotFoundException;
            }
            $quota = DB::table('destination_quotas')->where('guid', $ref->destination_quota_guid)->lockForUpdate()->first();
            $booking = DB::table('bookings')->where('guid', $guid)->lockForUpdate()->first();
            $now = now();
            if ($booking->status !== 'pending_approval' || $now->gte($booking->approval_expires_at)) {
                throw new BookingException('Booking is no longer awaiting approval.');
            }
            $timezone = DB::table('destinations')->where('guid', $quota->destination_guid)->value('timezone');
            $visitStart = CarbonImmutable::parse($quota->visit_date, $timezone)->startOfDay()->utc();
            if ($approve && $now->copy()->addHour()->addMinutes(15)->gte($visitStart)) {
                throw new BookingException('The visit date is too close for approval.');
            }
            $status = ! $approve ? 'rejected' : ($booking->total_amount == 0 ? 'confirmed' : 'awaiting_payment');
            $updates = ['status' => $status, 'updated_at' => $now];
            if ($status === 'awaiting_payment') {
                $updates['payment_expires_at'] = $now->copy()->addHour();
                $updates['notification_grace_expires_at'] = $now->copy()->addHour()->addMinutes(15);
            }
            DB::table('bookings')->where('guid', $guid)->update($updates);
            $this->history($guid, $actor->guid, 'pending_approval', $status, $reason);
            $this->notifications->record($guid, $booking->user_guid, $approve ? 'approved' : 'rejected');

            return $this->find($guid);
        }, 3);
    }

    public function expireDue(): void
    {
        DB::table('bookings')->where(function ($q) {
            $q->where(fn ($q) => $q->where('status', 'pending_approval')->where('approval_expires_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('payment_expires_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'payment_verification')->where('notification_grace_expires_at', '<=', now()));
        })->orderBy('guid')->chunkById(100, function ($bookings) {
            foreach ($bookings as $booking) {
                $this->expireOne($booking->guid, $booking->destination_quota_guid);
            }
        }, 'guid');
    }

    private function expireOne(string $guid, string $quotaGuid): void
    {
        DB::transaction(function () use ($guid, $quotaGuid) {
            DB::table('destination_quotas')->where('guid', $quotaGuid)->lockForUpdate()->first();
            $booking = DB::table('bookings')->where('guid', $guid)->lockForUpdate()->first();
            if (! $booking) {
                return;
            }
            $now = now();
            $next = match (true) {
                $booking->status === 'pending_approval' && $now->gte($booking->approval_expires_at) => 'expired',
                $booking->status === 'awaiting_payment' && $now->gte($booking->payment_expires_at) => 'payment_verification',
                $booking->status === 'payment_verification' && $now->gte($booking->notification_grace_expires_at) => 'payment_review',
                default => null,
            };
            if ($next) {
                DB::table('bookings')->where('guid', $guid)->update(['status' => $next, 'updated_at' => $now]);
                $this->history($guid, null, $booking->status, $next);
                if (in_array($next, ['expired', 'payment_review'], true)) {
                    $this->notifications->record($guid, $booking->user_guid, $next);
                }
            }
        }, 3);
    }

    private function history(string $guid, ?string $actor, ?string $old, string $new, ?string $reason = null): void
    {
        DB::table('booking_status_histories')->insert([
            'guid' => (string) Str::uuid(), 'booking_guid' => $guid, 'actor_guid' => $actor,
            'old_status' => $old, 'new_status' => $new, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function query(): Builder
    {
        return DB::table('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')
            ->select('bookings.*', 'destination_quotas.destination_guid', 'destination_quotas.visit_date');
    }

    private function find(string $guid): object
    {
        return $this->format($this->query()->where('bookings.guid', $guid)->first());
    }

    private function format(object $booking): object
    {
        foreach (['approval_expires_at', 'payment_expires_at', 'notification_grace_expires_at', 'created_at'] as $field) {
            $booking->$field = $booking->$field ? CarbonImmutable::parse($booking->$field, 'UTC')->toIso8601String() : null;
        }

        return $booking;
    }
}

<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\User;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private BookingNotificationService $notifications) {}

    public function create(User $user, array $input): object
    {
        $key = $input['idempotency_key'];
        unset($input['idempotency_key']);
        $input['quantity'] = (int) $input['quantity'];
        $input['notes'] = $input['notes'] ?? null;
        ksort($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        // Claimed in its own autocommit statement: INSERT IGNORE on a duplicate key takes a shared lock, and
        // upgrading it to FOR UPDATE inside one transaction deadlocks concurrent retries on MySQL.
        $this->claimKey($user, $key, $hash, 'processing');

        return DB::transaction(function () use ($user, $input, $key, $hash) {
            $now = now();
            $record = DB::table('idempotency_records')->where('user_guid', $user->guid)
                ->where('operation', 'booking.create')->where('key', $key)->lockForUpdate()->first();
            if ($record->status === 'abandoned') {
                throw new BookingException('This booking attempt was abandoned; use a new Idempotency-Key.', 'idempotency_key_abandoned');
            }
            if ($record->status === 'processing' && ! hash_equals($record->request_hash, $hash)) {
                // Only a failed earlier attempt leaves `processing` behind (success commits `completed`), and the
                // row lock proves nobody else is in flight, so the key is still unused and may adopt this input.
                DB::table('idempotency_records')->where('guid', $record->guid)->update(['request_hash' => $hash, 'updated_at' => $now]);
                $record->request_hash = $hash;
            }
            if (! hash_equals($record->request_hash, $hash)) {
                throw new BookingException('Idempotency key already used with different input.', 'idempotency_conflict');
            }
            if ($record->status === 'completed') {
                if ($now->gte($record->expires_at)) {
                    // Past retention the key is never reopened: no replay and no silent second booking.
                    throw new BookingException('The Idempotency-Key retention window has passed; check booking history instead of retrying.', 'idempotency_key_expired', ['booking_guid' => $record->response_reference]);
                }

                // A replay reports the booking's current state, not the creation-time snapshot.
                return $this->find($record->response_reference);
            }

            $quota = DB::table('destination_quotas')->where('destination_guid', $input['destination_guid'])
                ->where('visit_date', $input['visit_date'])->lockForUpdate()->first();
            if (! $quota) {
                throw new BookingException('Quota is unavailable.', 'quota_unavailable');
            }
            $destination = DB::table('destinations')->join('destination_categories', 'destination_categories.guid', '=', 'destinations.destination_category_guid')
                ->where('destinations.guid', $quota->destination_guid)
                ->select('destinations.*', 'destination_categories.is_active as category_active')->first();
            if (! $destination || ! $destination->is_active || ! $destination->category_active) {
                throw new BookingException('Quota is unavailable.', 'quota_unavailable');
            }
            if ($now->lt($quota->booking_opens_at) || $now->gte($quota->booking_closes_at)) {
                throw new BookingException('The booking window is closed.', 'booking_window_closed');
            }
            // shortcut: provisional midnight cutoff, replace after the visit-time policy is decided.
            if (BookingRules::tooCloseToCreate($now, BookingRules::visitStart($quota->visit_date, $destination->timezone))) {
                throw new BookingException('The visit date is too close to book.', 'visit_date_too_close');
            }
            $allocated = (int) DB::table('bookings')->where('destination_quota_guid', $quota->guid)
                ->whereIn('status', BookingRules::HELD)->lockForUpdate()->get(['quantity'])->sum('quantity');
            if ($allocated + $input['quantity'] > $quota->capacity) {
                throw new BookingException('Insufficient capacity.', 'insufficient_capacity', ['remaining' => max(0, $quota->capacity - $allocated)]);
            }
            $price = (int) $destination->ticket_price;
            if ($price < 0 || $price > intdiv(PHP_INT_MAX, $input['quantity'])) {
                throw new BookingException('Invalid ticket price.', 'invalid_price');
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

    /**
     * Reconciles an uncertain create. Looks the intent up strictly by (user, key), so another
     * user's booking can never be reached.
     *
     * @return array{key: string, state: string, replay_safe: bool, key_expired: bool, expires_at: ?string, booking: ?object}
     */
    public function intent(User $user, string $key): array
    {
        $record = DB::table('idempotency_records')->where('user_guid', $user->guid)
            ->where('operation', 'booking.create')->where('key', $key)->first();
        if (! $record || $record->status === 'processing') {
            // Nothing committed (yet). Replaying the identical payload with the same key stays idempotent.
            return ['key' => $key, 'state' => 'not_found', 'replay_safe' => true, 'key_expired' => false, 'expires_at' => null, 'booking' => null];
        }
        $expiresAt = CarbonImmutable::parse($record->expires_at, 'UTC');
        if ($record->status === 'abandoned') {
            return ['key' => $key, 'state' => 'abandoned', 'replay_safe' => false, 'key_expired' => false, 'expires_at' => $expiresAt->toIso8601String(), 'booking' => null];
        }
        $expired = now()->gte($expiresAt);

        return [
            'key' => $key, 'state' => 'completed', 'replay_safe' => ! $expired, 'key_expired' => $expired,
            'expires_at' => $expiresAt->toIso8601String(), 'booking' => $this->find($record->response_reference),
        ];
    }

    /**
     * Safe exit for an intent that cannot be reconciled: tombstones the key so a late-arriving
     * original request can never create a booking the user already walked away from.
     */
    public function abandonIntent(User $user, string $key): array
    {
        $this->claimKey($user, $key, str_repeat('0', 64), 'abandoned');
        DB::transaction(function () use ($user, $key) {
            $now = now();
            $record = DB::table('idempotency_records')->where('user_guid', $user->guid)
                ->where('operation', 'booking.create')->where('key', $key)->lockForUpdate()->first();
            if ($record->status === 'completed') {
                throw new BookingException('A booking was already created for this attempt.', 'intent_already_completed', ['booking_guid' => $record->response_reference]);
            }
            if ($record->status !== 'abandoned') {
                // A leftover `processing` row means no booking was committed for this key; close it for good.
                DB::table('idempotency_records')->where('guid', $record->guid)->update(['status' => 'abandoned', 'updated_at' => $now]);
            }
        }, 3);

        return $this->intent($user, $key);
    }

    public function listing(User $user, bool $admin = false, array $filters = []): LengthAwarePaginator
    {
        if ($admin && $user->role < 1) {
            throw new AuthorizationException;
        }
        $query = $this->query()->orderByDesc('bookings.created_at')->orderBy('bookings.guid');
        if (! $admin) {
            $query->where('bookings.user_guid', $user->guid);
        }
        if (! empty($filters['status'])) {
            $query->where('bookings.status', $filters['status']);
        }
        if ($admin && ! empty($filters['search'])) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('bookings.booking_number', 'like', $like)
                ->orWhere('bookings.visitor_name', 'like', $like)
                ->orWhere('bookings.destination_name_snapshot', 'like', $like));
        }
        $page = $query->paginate($filters['per_page'] ?? 15);
        $page->getCollection()->transform(fn ($booking) => $this->format($booking));

        return $page;
    }

    public function show(User $user, string $guid): object
    {
        $query = $this->query()->where('bookings.guid', $guid)->where('bookings.user_guid', $user->guid);

        return $this->format($query->first() ?? throw new ModelNotFoundException);
    }

    public function adminShow(User $actor, string $guid): object
    {
        if ($actor->role < 1) {
            throw new AuthorizationException;
        }

        return $this->format($this->query()->where('bookings.guid', $guid)->first() ?? throw new ModelNotFoundException);
    }

    /** Owner-only cancellation; only a booking still awaiting a decision may be released. */
    public function cancel(User $user, string $guid): object
    {
        return DB::transaction(function () use ($user, $guid) {
            $ref = DB::table('bookings')->where('guid', $guid)->where('user_guid', $user->guid)->first(['destination_quota_guid']);
            if (! $ref) {
                throw new ModelNotFoundException;
            }
            // Same lock order as create/decide (quota first, then booking) so capacity changes are serialized.
            DB::table('destination_quotas')->where('guid', $ref->destination_quota_guid)->lockForUpdate()->first();
            $booking = DB::table('bookings')->where('guid', $guid)->where('user_guid', $user->guid)->lockForUpdate()->first();
            $now = now();
            if ($booking->status === 'cancelled') {
                throw new BookingException('Booking is already cancelled.', 'booking_already_cancelled');
            }
            if ($booking->status !== 'pending_approval' || $now->gte($booking->approval_expires_at)) {
                throw new BookingException('Only a booking awaiting approval can be cancelled. Processed bookings follow the payment policy, which is not available yet.', 'booking_not_cancellable');
            }
            DB::table('bookings')->where('guid', $guid)->update(['status' => 'cancelled', 'updated_at' => $now]);
            $this->history($guid, $user->guid, 'pending_approval', 'cancelled');
            $this->notifications->record($guid, $booking->user_guid, 'cancelled');

            return $this->find($guid);
        }, 3);
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
            if ($booking->status !== 'pending_approval') {
                throw new BookingException('Booking is no longer awaiting approval.', 'booking_not_pending', ['status' => $booking->status]);
            }
            if ($now->gte($booking->approval_expires_at)) {
                throw new BookingException('The approval deadline for this booking has passed.', 'approval_deadline_passed', ['status' => $booking->status]);
            }
            $timezone = DB::table('destinations')->where('guid', $quota->destination_guid)->value('timezone');
            $visitStart = BookingRules::visitStart($quota->visit_date, $timezone);
            if ($approve && $now->copy()->addMinutes(BookingRules::APPROVE_LEAD_MINUTES)->gte($visitStart)) {
                throw new BookingException('The visit date is too close for approval.', 'visit_date_too_close');
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

    private function claimKey(User $user, string $key, string $hash, string $status): void
    {
        $now = now();
        DB::table('idempotency_records')->insertOrIgnore([
            'guid' => (string) Str::uuid(), 'user_guid' => $user->guid, 'operation' => 'booking.create',
            'key' => $key, 'request_hash' => $hash, 'status' => $status,
            'expires_at' => $now->copy()->addDays(BookingRules::IDEMPOTENCY_DAYS), 'created_at' => $now, 'updated_at' => $now,
        ]);
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
            ->join('destinations', 'destinations.guid', '=', 'destination_quotas.destination_guid')
            ->leftJoin('check_ins', 'check_ins.booking_guid', '=', 'bookings.guid')
            ->leftJoin('reviews', 'reviews.booking_guid', '=', 'bookings.guid')
            ->select('bookings.*', 'destination_quotas.destination_guid', 'destination_quotas.visit_date',
                'destinations.timezone as destination_timezone', 'check_ins.checked_in_at',
                'reviews.guid as review_guid', 'reviews.rating as review_rating',
                'reviews.comment as review_comment', 'reviews.created_at as review_created_at');
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
        $booking->visit_date = substr((string) $booking->visit_date, 0, 10);
        $booking->cancellable = $booking->status === 'pending_approval'
            && CarbonImmutable::parse($booking->approval_expires_at)->isFuture();
        $booking->checked_in_at = $booking->checked_in_at ? CarbonImmutable::parse($booking->checked_in_at, 'UTC')->toIso8601String() : null;
        $booking->can_check_in = $booking->status === 'confirmed' && $booking->checked_in_at === null
            && $booking->visit_date === now($booking->destination_timezone)->toDateString();
        $booking->can_review = $booking->status === 'confirmed' && $booking->checked_in_at !== null && $booking->review_guid === null;
        $booking->review = $booking->review_guid === null ? null : (object) [
            'guid' => $booking->review_guid, 'booking_guid' => $booking->guid,
            'destination_guid' => $booking->destination_guid, 'destination_name' => $booking->destination_name_snapshot,
            'rating' => (int) $booking->review_rating, 'comment' => $booking->review_comment, 'created_at' => $booking->review_created_at,
        ];

        return $booking;
    }
}

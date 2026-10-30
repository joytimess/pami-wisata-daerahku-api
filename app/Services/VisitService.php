<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisitService
{
    public function __construct(private BookingService $bookings) {}

    public function checkIn(User $actor, string $guid): object
    {
        return DB::transaction(function () use ($actor, $guid) {
            $booking = $this->lockBooking($actor, $guid, allowAdmin: true);
            if (DB::table('check_ins')->where('booking_guid', $guid)->exists()) {
                throw new BookingException('This booking has already checked in.', 'check_in_duplicate');
            }
            if ($booking->status !== 'confirmed') {
                throw new BookingException('Only a confirmed booking may check in.', 'check_in_not_eligible');
            }
            $quota = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first();
            $timezone = DB::table('destinations')->where('guid', $quota->destination_guid)->value('timezone');
            if (substr((string) $quota->visit_date, 0, 10) !== now($timezone)->toDateString()) {
                throw new BookingException('Check-in is available only on the visit date at the destination.', 'check_in_wrong_date');
            }
            DB::table('check_ins')->insert([
                'guid' => (string) Str::uuid(), 'booking_guid' => $guid,
                'actor_guid' => $actor->guid, 'checked_in_at' => now(),
            ]);

            return $actor->guid === $booking->user_guid
                ? $this->bookings->show($actor, $guid)
                : $this->bookings->adminShow($actor, $guid);
        }, 3);
    }

    public function review(User $actor, string $guid, array $data): object
    {
        return DB::transaction(function () use ($actor, $guid, $data) {
            $booking = $this->lockBooking($actor, $guid);
            if (DB::table('reviews')->where('booking_guid', $guid)->exists()) {
                throw new BookingException('This booking already has a review.', 'review_duplicate');
            }
            if ($booking->status !== 'confirmed' || ! DB::table('check_ins')->where('booking_guid', $guid)->exists()) {
                throw new BookingException('A confirmed, checked-in visit is required before reviewing.', 'review_not_eligible');
            }
            $destination = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->value('destination_guid');
            $reviewGuid = (string) Str::uuid();
            DB::table('reviews')->insert([
                'guid' => $reviewGuid, 'booking_guid' => $guid, 'user_guid' => $actor->guid,
                'destination_guid' => $destination, 'rating' => (int) $data['rating'],
                'comment' => $data['comment'], 'created_at' => now(),
            ]);

            return $this->reviews()->where('reviews.guid', $reviewGuid)->first();
        }, 3);
    }

    private function lockBooking(User $actor, string $guid, bool $allowAdmin = false): object
    {
        $query = DB::table('bookings')->where('guid', $guid);
        if (! $allowAdmin || $actor->role < 1) {
            $query->where('user_guid', $actor->guid);
        }

        return $query->lockForUpdate()->first() ?? throw new ModelNotFoundException;
    }

    public function history(User $actor, array $filters)
    {
        return $this->reviews()->where('reviews.user_guid', $actor->guid)
            ->orderByDesc('reviews.created_at')->orderBy('reviews.guid')->paginate($filters['per_page'] ?? 15);
    }

    public function destinationReviews(Destination $destination, array $filters)
    {
        abort_unless($destination->is_active && $destination->category()->where('is_active', true)->exists(), 404);

        return $this->reviews()->where('reviews.destination_guid', $destination->guid)
            ->orderByDesc('reviews.created_at')->orderBy('reviews.guid')->paginate($filters['per_page'] ?? 15);
    }

    public function statistics(User $actor): array
    {
        return [
            'bookings' => DB::table('bookings')->where('user_guid', $actor->guid)->count(),
            'reviews' => DB::table('reviews')->where('user_guid', $actor->guid)->count(),
            'visits' => DB::table('check_ins')->join('bookings', 'bookings.guid', '=', 'check_ins.booking_guid')
                ->where('bookings.user_guid', $actor->guid)->count(),
        ];
    }

    private function reviews(): Builder
    {
        return DB::table('reviews')->join('bookings', 'bookings.guid', '=', 'reviews.booking_guid')
            ->select('reviews.*', 'bookings.destination_name_snapshot as destination_name');
    }
}

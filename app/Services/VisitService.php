<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Exceptions\TicketException;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisitService
{
    public function __construct(private PaymentEligibility $payments) {}

    public function checkIn(User $actor, string $guid): never
    {
        throw new TicketException('legacy_check_in_disabled', 'Gunakan pemindaian QR oleh petugas untuk check-in.', 410);
    }

    public function review(User $actor, string $guid, array $data): object
    {
        return DB::transaction(function () use ($actor, $guid, $data) {
            $booking = $this->lockBooking($actor, $guid);
            if (DB::table('reviews')->where('booking_guid', $guid)->exists()) {
                throw new BookingException('This booking already has a review.', 'review_duplicate');
            }
            $legacy = DB::table('check_ins')->where('booking_guid', $guid)->exists();
            $hasTickets = DB::table('booking_tickets')->where('booking_guid', $guid)->exists();
            $checked = DB::table('booking_tickets')->where('booking_guid', $guid)->whereNotNull('checked_in_at')->exists()
                || (! $hasTickets && $legacy);
            if ($booking->status !== 'confirmed' || ! $checked || (! $legacy && $this->payments->status($booking) !== 'paid')) {
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
            'visits' => DB::table('bookings')->where('user_guid', $actor->guid)->where(function ($query) {
                $query->whereExists(fn ($tickets) => $tickets->selectRaw('1')->from('booking_tickets')
                    ->whereColumn('booking_tickets.booking_guid', 'bookings.guid')->whereNotNull('checked_in_at'))
                    ->orWhere(fn ($legacy) => $legacy->whereNotExists(fn ($tickets) => $tickets->selectRaw('1')->from('booking_tickets')
                        ->whereColumn('booking_tickets.booking_guid', 'bookings.guid'))
                        ->whereExists(fn ($checkIns) => $checkIns->selectRaw('1')->from('check_ins')->whereColumn('check_ins.booking_guid', 'bookings.guid')));
            })->count(),
        ];
    }

    private function reviews(): Builder
    {
        return DB::table('reviews')->join('bookings', 'bookings.guid', '=', 'reviews.booking_guid')
            ->select('reviews.*', 'bookings.destination_name_snapshot as destination_name');
    }
}

<?php

namespace App\Services;

use App\Exceptions\TicketException;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketCheckInService
{
    public function __construct(private TicketService $tickets, private PaymentEligibility $payments) {}

    public function preview(User $actor, string $payload): array
    {
        $actor = $this->staff($actor);
        $ticket = $this->lookup($payload);
        $booking = $this->tickets->booking($ticket->booking_guid);
        $this->scope($actor, $booking->destination_guid);
        $this->eligible($booking, $ticket);

        return [
            'ticket_guid' => $ticket->guid, 'sequence' => (int) $ticket->sequence, 'quantity' => (int) $booking->quantity,
            'destination_name' => $booking->destination_name_snapshot, 'visit_date' => $booking->visit_date,
            'timezone' => $booking->destination_timezone, 'booker_name' => $booking->visitor_name,
            'payment_status' => 'paid', 'booking_status' => 'confirmed', 'check_in_status' => 'not_checked_in', 'can_confirm' => true,
        ];
    }

    public function confirm(User $actor, string $payload, string $key): array
    {
        $actor = $this->staff($actor);
        $hash = hash('sha256', $payload);
        DB::transaction(function () use ($actor, $key, $hash) {
            if (! DB::table('idempotency_records')->where('user_guid', $actor->guid)->where('operation', 'ticket.check_in')->where('key', $key)->exists()) {
                DB::table('idempotency_records')->insertOrIgnore([
                    'guid' => (string) Str::uuid(), 'user_guid' => $actor->guid, 'operation' => 'ticket.check_in',
                    'key' => $key, 'request_hash' => $hash, 'status' => 'processing',
                    'expires_at' => now()->addYears(100), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);

        return DB::transaction(function () use ($actor, $payload, $key, $hash) {
            $record = DB::table('idempotency_records')->where('user_guid', $actor->guid)
                ->where('operation', 'ticket.check_in')->where('key', $key)->lockForUpdate()->first();
            if (! hash_equals($record->request_hash, $hash)) {
                throw new TicketException('idempotency_conflict', 'Idempotency-Key telah digunakan untuk tiket berbeda.');
            }
            $ticket = $this->lookup($payload);
            $booking = $this->tickets->booking($ticket->booking_guid, true);
            $locked = DB::table('booking_tickets')->where('guid', $ticket->guid)->lockForUpdate()->first();
            $actor = $this->staff($actor, true);
            $this->scope($actor, $booking->destination_guid, true);
            if ($record->status === 'completed') {
                return json_decode($record->response_body, true, flags: JSON_THROW_ON_ERROR);
            }
            $this->eligible($booking, $locked);
            $now = now()->utc();
            DB::table('ticket_check_ins')->insert([
                'guid' => (string) Str::uuid(), 'ticket_guid' => $locked->guid, 'booking_guid' => $booking->guid,
                'actor_guid' => $actor->guid, 'idempotency_key' => $key, 'checked_in_at' => $now, 'created_at' => $now,
            ]);
            DB::table('booking_tickets')->where('guid', $locked->guid)->update([
                'checked_in_at' => $now, 'checked_in_by_user_guid' => $actor->guid, 'updated_at' => $now,
            ]);
            $result = [
                'ticket_guid' => $locked->guid, 'booking_guid' => $booking->guid, 'sequence' => (int) $locked->sequence,
                'quantity' => (int) $booking->quantity, 'status' => 'checked_in',
                'checked_in_count' => DB::table('booking_tickets')->where('booking_guid', $booking->guid)->lockForUpdate()->get(['checked_in_at'])->whereNotNull('checked_in_at')->count(),
                'checked_in_at' => $now->toIso8601String(), 'checked_in_by' => $actor->guid,
            ];
            DB::table('idempotency_records')->where('guid', $record->guid)->update([
                'status' => 'completed', 'response_reference' => $locked->guid,
                'response_body' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => $now,
            ]);

            return $result;
        }, 3);
    }

    private function lookup(string $payload): object
    {
        if (! preg_match('/\AWDR1\.[A-Za-z0-9_-]{43}\z/D', $payload)) {
            throw new TicketException('invalid_qr', 'QR tiket tidak valid.', 422);
        }

        return DB::table('booking_tickets')->where('qr_token_hash', hash('sha256', $payload))->first()
            ?? throw new TicketException('invalid_qr', 'QR tiket tidak valid.', 422);
    }

    private function eligible(object $booking, object $ticket): void
    {
        if ($ticket->qr_revoked_at !== null || $this->tickets->state($booking) === 'revoked') {
            throw new TicketException('ticket_revoked', 'Tiket sudah dicabut atau booking dibatalkan.');
        }
        if ($this->payments->status($booking) !== 'paid') {
            throw new TicketException('payment_not_paid', 'Menunggu pembayaran terverifikasi.');
        }
        if ($booking->status !== 'confirmed') {
            throw new TicketException('booking_not_approved', 'Booking belum disetujui.');
        }
        if ($booking->visit_date !== now($booking->destination_timezone)->toDateString()) {
            throw new TicketException('wrong_visit_date', 'Check-in hanya tersedia pada tanggal kunjungan di destinasi.');
        }
        if ($ticket->qr_issued_at === null || $ticket->qr_token_encrypted === null) {
            throw new TicketException('ticket_not_issued', 'QR tiket belum diterbitkan.');
        }
        if ($ticket->checked_in_at !== null || DB::table('ticket_check_ins')->where('ticket_guid', $ticket->guid)->exists()
            || DB::table('check_ins')->where('booking_guid', $booking->guid)->exists()) {
            throw new TicketException('ticket_already_checked_in', 'Tiket sudah pernah check-in.');
        }
    }

    private function staff(User $actor, bool $lock = false): User
    {
        $query = User::where('guid', $actor->guid);
        if ($lock) {
            $query->sharedLock();
        }
        $current = $query->first();
        if (! $current || $current->status !== 'active' || ! in_array($current->role, [1, 2], true)) {
            throw new TicketException('forbidden', 'Akses hanya untuk petugas aktif.', 403);
        }

        return $current;
    }

    private function scope(User $actor, string $destination, bool $lock = false): void
    {
        if ($actor->role === 2) {
            return;
        }
        if ($lock) {
            DB::table('destinations')->where('guid', $destination)->sharedLock()->first(['guid']);
        }
        $query = DB::table('destination_admin_assignments')->where('destination_guid', $destination)->where('user_guid', $actor->guid);
        if ($lock) {
            $query->sharedLock();
        }
        if (! $query->first()) {
            throw new TicketException('ticket_out_of_scope', 'Anda belum ditugaskan ke destinasi ini.', 403);
        }
    }

    public function scannerScope(User $actor): array
    {
        $actor = $this->staff($actor);
        $query = DB::table('destinations')->select('destinations.guid', 'destinations.name');
        if ($actor->role !== 2) {
            $query->join('destination_admin_assignments', 'destination_admin_assignments.destination_guid', '=', 'destinations.guid')
                ->where('destination_admin_assignments.user_guid', $actor->guid);
        }

        return ['global' => $actor->role === 2, 'destinations' => $query->orderBy('destinations.name')->orderBy('destinations.guid')->get()->all()];
    }

    public function admins(User $actor, Destination $destination): array
    {
        $this->superadmin($actor);

        return ['user_guids' => DB::table('destination_admin_assignments')->where('destination_guid', $destination->guid)->orderBy('user_guid')->sharedLock()->pluck('user_guid')->all()];
    }

    public function assign(User $actor, Destination $destination, array $guids): array
    {
        $this->superadmin($actor);

        return DB::transaction(function () use ($actor, $destination, $guids) {
            $actor = $this->staff($actor, true);
            $this->superadmin($actor);
            DB::table('destinations')->where('guid', $destination->guid)->lockForUpdate()->first();
            if (DB::table('users')->whereIn('guid', $guids)->where('role', 1)->where('status', 'active')->sharedLock()->get(['guid'])->count() !== count($guids)) {
                throw new TicketException('invalid_assignment', 'Penugasan hanya untuk Admin aktif.', 422);
            }
            $before = DB::table('destination_admin_assignments')->where('destination_guid', $destination->guid)->lockForUpdate()->pluck('user_guid')->all();
            foreach (array_diff($before, $guids) as $guid) {
                DB::table('destination_admin_assignments')->where('destination_guid', $destination->guid)->where('user_guid', $guid)->delete();
                $this->assignmentHistory($destination->guid, $guid, $actor->guid, 'removed');
            }
            foreach (array_diff($guids, $before) as $guid) {
                DB::table('destination_admin_assignments')->insert([
                    'guid' => (string) Str::uuid(), 'destination_guid' => $destination->guid, 'user_guid' => $guid,
                    'assigned_by_user_guid' => $actor->guid, 'created_at' => now(),
                ]);
                $this->assignmentHistory($destination->guid, $guid, $actor->guid, 'assigned');
            }

            return $this->admins($actor, $destination);
        }, 3);
    }

    private function assignmentHistory(string $destination, string $user, string $actor, string $action): void
    {
        DB::table('destination_admin_assignment_histories')->insert([
            'guid' => (string) Str::uuid(), 'destination_guid' => $destination, 'user_guid' => $user,
            'actor_guid' => $actor, 'action' => $action, 'created_at' => now(),
        ]);
    }

    private function superadmin(User $actor): void
    {
        $actor = $this->staff($actor);
        if ($actor->role !== 2) {
            throw new TicketException('forbidden', 'Penugasan hanya dapat diatur Superadmin.', 403);
        }
    }
}

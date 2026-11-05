<?php

namespace App\Services;

use App\Exceptions\TicketException;
use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TicketService
{
    public function __construct(private PaymentEligibility $payments) {}

    /** Parent booking must already be locked or newly inserted in the current transaction. */
    public function initialize(object $booking): void
    {
        $existing = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->lockForUpdate()->pluck('sequence')->map(fn ($sequence) => (int) $sequence)->all();
        $legacy = DB::table('check_ins')->where('booking_guid', $booking->guid)->sharedLock()->first();
        for ($sequence = 1; $sequence <= $booking->quantity; $sequence++) {
            if (! in_array($sequence, $existing, true)) {
                DB::table('booking_tickets')->insert([
                    'guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'sequence' => $sequence,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if ($legacy) {
            foreach (DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->lockForUpdate()->get() as $ticket) {
                if (! DB::table('ticket_check_ins')->where('ticket_guid', $ticket->guid)->exists()) {
                    DB::table('ticket_check_ins')->insert([
                        'guid' => (string) Str::uuid(), 'ticket_guid' => $ticket->guid, 'booking_guid' => $booking->guid,
                        'actor_guid' => $legacy->actor_guid, 'checked_in_at' => $legacy->checked_in_at, 'created_at' => now(),
                    ]);
                    DB::table('booking_tickets')->where('guid', $ticket->guid)->update([
                        'checked_in_at' => $legacy->checked_in_at, 'checked_in_by_user_guid' => $legacy->actor_guid, 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function booking(string $guid, bool $lock = false): object
    {
        $reference = DB::table('bookings')->where('guid', $guid)->first(['destination_quota_guid']) ?? throw new ModelNotFoundException;
        $quotaQuery = DB::table('destination_quotas')->where('guid', $reference->destination_quota_guid);
        if ($lock) {
            $quotaQuery->lockForUpdate();
        }
        $quota = $quotaQuery->first() ?? throw new ModelNotFoundException;
        $bookingQuery = DB::table('bookings')->where('guid', $guid);
        if ($lock) {
            $bookingQuery->lockForUpdate();
        }
        $booking = $bookingQuery->first() ?? throw new ModelNotFoundException;
        $booking->destination_guid = $quota->destination_guid;
        $booking->visit_date = substr((string) $quota->visit_date, 0, 10);
        $booking->destination_timezone = $booking->destination_timezone_snapshot
            ?? DB::table('destinations')->where('guid', $quota->destination_guid)->value('timezone');

        return $booking;
    }

    public function issue(string $guid): array
    {
        try {
            return DB::transaction(function () use ($guid) {
                $booking = $this->booking($guid, true);
                $this->initialize($booking);
                $state = $this->state($booking);
                if ($state === 'available') {
                    foreach (DB::table('booking_tickets')->where('booking_guid', $guid)->orderBy('sequence')->lockForUpdate()->get() as $ticket) {
                        if ($ticket->qr_token_hash === null && $ticket->qr_token_encrypted === null
                            && $ticket->checked_in_at === null && $ticket->qr_revoked_at === null) {
                            $payload = 'WDR1.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                            DB::table('booking_tickets')->where('guid', $ticket->guid)->update([
                                'qr_token_hash' => hash('sha256', $payload), 'qr_token_encrypted' => Crypt::encryptString($payload),
                                'qr_issued_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                } elseif ($state === 'revoked') {
                    DB::table('booking_tickets')->where('booking_guid', $guid)->whereNull('checked_in_at')
                        ->whereNotNull('qr_token_hash')->whereNull('qr_revoked_at')->update(['qr_revoked_at' => now(), 'updated_at' => now()]);
                }

                return $this->collection($booking);
            }, 3);
        } catch (TicketException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Ticket QR issuance failed.', ['booking_guid' => $guid, 'exception_type' => get_class($exception)]);
            throw new TicketException('qr_unavailable', 'QR tidak dapat diterbitkan. Coba lagi atau hubungi pengelola.', 503);
        }
    }

    public function listing(User $owner, string $guid): array
    {
        $this->owner($owner, $guid);

        return $this->issue($guid);
    }

    public function svg(User $owner, string $bookingGuid, string $ticketGuid): string
    {
        $list = $this->listing($owner, $bookingGuid);
        $item = collect($list['tickets'])->firstWhere('guid', $ticketGuid) ?? throw new ModelNotFoundException;
        if (! $item['qr_available']) {
            throw new TicketException($item['status'] === 'checked_in' ? 'ticket_already_checked_in' : match ($list['ticket_state']) {
                'awaiting_payment' => 'payment_not_paid', 'awaiting_approval' => 'booking_not_approved',
                'expired' => 'ticket_expired', 'revoked' => 'ticket_revoked', default => 'ticket_not_issued',
            }, 'QR tiket belum tersedia atau sudah tidak dapat digunakan.');
        }
        $ticket = DB::table('booking_tickets')->where('guid', $ticketGuid)->first();
        try {
            $payload = Crypt::decryptString($ticket->qr_token_encrypted);
            if (! hash_equals($ticket->qr_token_hash, hash('sha256', $payload))) {
                throw new \RuntimeException;
            }

            return (new Writer(new ImageRenderer(new RendererStyle(360, 4), new SvgImageBackEnd)))
                ->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());
        } catch (Throwable) {
            Log::error('Ticket QR recovery required.', ['booking_guid' => $bookingGuid, 'ticket_guid' => $ticketGuid]);
            throw new TicketException('qr_unavailable', 'QR tidak dapat ditampilkan. Hubungi pengelola untuk pemulihan.', 503);
        }
    }

    private function owner(User $owner, string $guid): void
    {
        $booking = DB::table('bookings')->where('guid', $guid)->first(['user_guid']) ?? throw new ModelNotFoundException;
        if ($owner->role !== 0 || $booking->user_guid !== $owner->guid) {
            throw new TicketException('forbidden', 'Tiket hanya dapat dibuka oleh pemilik booking.', 403);
        }
    }

    public function state(object $booking): string
    {
        $payment = $this->payments->status($booking);
        if (in_array($booking->status, ['cancelled', 'rejected', 'expired', 'refunded'], true)
            || in_array($payment, ['refunded', 'reversed', 'chargeback'], true)) {
            return 'revoked';
        }
        $today = now($booking->destination_timezone)->toDateString();
        if ($today > $booking->visit_date) {
            return 'expired';
        }
        if ($payment !== 'paid') {
            return 'awaiting_payment';
        }
        if ($booking->status !== 'confirmed') {
            return 'awaiting_approval';
        }
        if ($today < CarbonImmutable::parse($booking->visit_date, $booking->destination_timezone)->subDays(5)->toDateString()) {
            return 'scheduled';
        }

        return 'available';
    }

    private function collection(object $booking): array
    {
        $state = $this->state($booking);
        $tickets = DB::table('booking_tickets')->where('booking_guid', $booking->guid)->orderBy('sequence')->sharedLock()->get();
        $checked = $tickets->whereNotNull('checked_in_at')->count();

        return [
            'booking_guid' => $booking->guid, 'visit_date' => $booking->visit_date, 'timezone' => $booking->destination_timezone,
            'qr_available_from' => CarbonImmutable::parse($booking->visit_date, $booking->destination_timezone)->subDays(5)->toDateString(),
            'quantity' => (int) $booking->quantity, 'checked_in_count' => $checked, 'payment_status' => $this->payments->status($booking),
            'ticket_state' => $checked === (int) $booking->quantity ? 'fully_checked_in' : ($checked > 0 && $state === 'available' ? 'partially_checked_in' : $state),
            'tickets' => $tickets->map(fn ($ticket) => [
                'guid' => $ticket->guid, 'sequence' => (int) $ticket->sequence,
                'status' => $ticket->checked_in_at ? 'checked_in' : ($ticket->qr_revoked_at ? 'revoked' : 'not_checked_in'),
                'qr_available' => $state === 'available' && $ticket->qr_issued_at !== null && $ticket->qr_token_hash !== null
                    && $ticket->qr_token_encrypted !== null && $ticket->qr_revoked_at === null && $ticket->checked_in_at === null,
                'checked_in_at' => $ticket->checked_in_at ? CarbonImmutable::parse($ticket->checked_in_at, 'UTC')->toIso8601String() : null,
            ])->all(),
        ] + $this->payments->provenance($booking);
    }

    public function backfill(): int
    {
        $count = 0;
        DB::table('bookings')->orderBy('guid')->chunkById(100, function ($bookings) use (&$count) {
            foreach ($bookings as $booking) {
                DB::transaction(function () use ($booking) {
                    $this->initialize($this->booking($booking->guid, true));
                }, 3);
                $count++;
            }
        }, 'guid');

        return $count;
    }

    public function issueDue(): int
    {
        $count = 0;
        DB::table('bookings')->join('destination_quotas', 'destination_quotas.guid', '=', 'bookings.destination_quota_guid')
            ->where('bookings.status', 'confirmed')->whereBetween('destination_quotas.visit_date', [now('UTC')->subDay()->toDateString(), now('UTC')->addDays(6)->toDateString()])
            ->select('bookings.guid')->orderBy('bookings.guid')->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $booking) {
                    $this->issue($booking->guid);
                    $count++;
                }
            }, 'bookings.guid', 'guid');

        return $count;
    }
}

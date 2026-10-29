<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBookingDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchBookingDocuments extends Command
{
    protected $signature = 'documents:dispatch';

    protected $description = 'Queue missing confirmed-booking documents';

    public function handle(): int
    {
        DB::table('bookings')->where('status', 'confirmed')->where(function ($query) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.booking_guid', 'bookings.guid'))
                ->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('tickets')->whereColumn('tickets.booking_guid', 'bookings.guid'));
        })->orderBy('guid')->chunkById(100, function ($bookings) {
            foreach ($bookings as $booking) {
                GenerateBookingDocuments::dispatch($booking->guid);
            }
        }, 'guid');

        return self::SUCCESS;
    }
}

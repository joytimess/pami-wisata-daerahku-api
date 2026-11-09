<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class TicketLinkController extends Controller
{
    /**
     * Landing page for the "tickets ready" email link. It is navigation only: it reads no booking, confirms nothing about the
     * booking's existence and reveals no QR. Tickets are shown solely by the authenticated, ownership-checked API
     * (GET /api/v1/bookings/{booking}/tickets) after the user signs in inside the app.
     */
    public function __invoke(string $booking): Response
    {
        $host = parse_url((string) config('operations.ticket_link_base_url'), PHP_URL_HOST) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $package = (string) config('mobile.android.package');
        $appUrl = is_string($host) && $host !== '' && $package !== ''
            ? 'intent://'.$host.route('tickets.link', ['booking' => $booking], false).'#Intent;scheme=https;package='.rawurlencode($package).';end'
            : '';

        return response()->view('ticket-link', ['appUrl' => $appUrl])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

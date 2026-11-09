<?php

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\DestinationQuota;
use App\Models\User;
use App\Services\BookingDocumentService;
use App\Services\BookingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('generates private documents once for confirmed bookings and restricts access to the owner', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = DestinationCategory::create(['name' => 'Nature', 'slug' => 'nature']);
    $destination = Destination::create(['destination_category_guid' => $category->guid, 'name' => 'Park', 'location' => 'Town', 'description' => 'Park', 'ticket_price' => 0, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $quota = DestinationQuota::create(['destination_guid' => $destination->guid, 'visit_date' => '2027-01-01', 'capacity' => 6, 'booking_opens_at' => now(), 'booking_closes_at' => now()->addDay()]);
    $guid = (string) Str::uuid();
    DB::table('bookings')->insert(['guid' => $guid, 'user_guid' => $user->guid, 'destination_quota_guid' => $quota->guid, 'booking_number' => 'B-1', 'quantity' => 4, 'visitor_name' => 'Visitor', 'visitor_email' => 'v@example.com', 'visitor_phone' => '+6281234567890', 'destination_name_snapshot' => 'Park', 'unit_price' => 0, 'total_amount' => 0, 'currency' => 'IDR', 'status' => 'pending_approval', 'approval_expires_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $service = app(BookingDocumentService::class);
    $service->generate($guid);
    expect(DB::table('invoices')->count())->toBe(0);
    DB::table('bookings')->where('guid', $guid)->update(['status' => 'confirmed']);
    $service->generate($guid);
    $service->generate($guid);
    expect(DB::table('invoices')->count())->toBe(1)->and(DB::table('tickets')->count())->toBe(0);
    $invoice = DB::table('invoices')->first();
    expect(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF');
    $metadata = $service->metadata($user, $guid);
    expect((array) $metadata['ticket'])->toMatchArray(['legacy' => false, 'admission' => false, 'download_available' => true])->not->toHaveKeys(['pdf_path', 'token_hash']);
    expect(fn () => $service->download($other, $guid, 'invoice'))->toThrow(ModelNotFoundException::class);
    expect($service->download($user, $guid, 'invoice')->getStatusCode())->toBe(200);
    expect(fn () => $service->download($other, $guid, 'ticket'))->toThrow(ModelNotFoundException::class);
    expect($service->download($user, $guid, 'ticket')->getStatusCode())->toBe(200);
});

it('reserves a canonical invoice on approval without rendering or storing a PDF', function () {
    Storage::fake('local');
    Pdf::shouldReceive('loadHTML')->never();
    [$destination, $date] = seedDestination(price: 12000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'invoice']);
    $this->assertDatabaseCount('invoices', 0);

    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);

    $invoice = DB::table('invoices')->where('booking_guid', $booking->guid)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->invoice_number)->toStartWith('INV-');
    expect($invoice->pdf_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
    $this->assertDatabaseCount('tickets', 0);
});

it('dispatches and repairs awaiting-payment invoice PDFs without changing their identity or issuing a ticket', function () {
    Storage::fake('local');
    [$destination, $date] = seedDestination(price: 12000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'invoice']);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    DB::table('invoices')->insertOrIgnore(['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid,
        'invoice_number' => 'LEGACY-INV', 'status' => 'issued', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $invoice = DB::table('invoices')->where('booking_guid', $booking->guid)->first();
    $path = '/api/v1/bookings/'.$booking->guid.'/documents';
    $headers = bearer($owner);
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.invoice.download_available', false);
    $this->getJson($path.'/invoice', $headers)->assertNotFound();

    $this->artisan('documents:dispatch')->assertSuccessful();

    $generated = DB::table('invoices')->where('booking_guid', $booking->guid)->first();
    expect($generated->guid)->toBe($invoice->guid);
    expect($generated->invoice_number)->toBe($invoice->invoice_number);
    expect(Storage::disk('local')->get($generated->pdf_path))->toStartWith('%PDF');
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.invoice.download_available', true);
    Storage::disk('local')->delete($generated->pdf_path);
    $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.invoice.download_available', false);
    $this->artisan('documents:dispatch')->assertSuccessful();
    expect(Storage::disk('local')->get(DB::table('invoices')->where('guid', $invoice->guid)->value('pdf_path')))->toStartWith('%PDF');
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('tickets', 0);
});

it('publishes one invoice file when another worker finishes rendering first', function () {
    Storage::fake('local');
    [$destination, $date] = seedDestination(price: 12000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'invoice-race']);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    $render = 0;
    Pdf::shouldReceive('loadHTML')->twice()->andReturnUsing(function () use (&$render, $booking) {
        expect(DB::transactionLevel())->toBe(1);
        $first = ++$render === 1;
        if ($first) {
            app(BookingDocumentService::class)->generate($booking->guid);
        }
        $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('output')->once()->andReturn($first ? '%PDF-loser' : '%PDF-winner');

        return $pdf;
    });

    app(BookingDocumentService::class)->generate($booking->guid);

    $invoice = DB::table('invoices')->where('booking_guid', $booking->guid)->first();
    expect(Storage::disk('local')->get($invoice->pdf_path))->toBe('%PDF-winner');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('invoices', 1);
});

it('keeps legacy group-ticket rows as history with accurate availability and never as an entry ticket', function () {
    Storage::fake('local');
    [$destination, $date] = seedDestination(price: 12000, days: 8);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date, 2) + ['idempotency_key' => 'legacy']);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
    Storage::disk('local')->put('booking-documents/legacy.pdf', '%PDF-legacy-token');
    DB::table('tickets')->insert(['guid' => (string) Str::uuid(), 'booking_guid' => $booking->guid, 'token_hash' => hash('sha256', 'legacy'),
        'status' => 'issued', 'pdf_path' => 'booking-documents/legacy.pdf', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $path = '/api/v1/bookings/'.$booking->guid.'/documents';

    $response = $this->getJson($path, bearer($owner))->assertOk()
        ->assertJsonPath('data.ticket.download_available', true)->assertJsonPath('data.ticket.admission', false)
        ->assertJsonPath('data.ticket.legacy', true)
        ->assertJsonPath('data.entry_tickets.qr_available_from', now()->addDays(8)->subDays(5)->toDateString())
        ->assertJsonPath('data.entry_tickets.quantity', 2);

    expect(json_encode($response->json()))->not->toContain('legacy.pdf')->not->toContain('token_hash');
    $download = $this->get($path.'/ticket', bearer($owner))->assertOk();
    expect($download->headers->get('Content-Type'))->toStartWith('application/pdf')->and($download->headers->get('Cache-Control'))->toContain('no-store');
    expect($download->streamedContent())->not->toContain('legacy-token');
    $this->get($path.'/ticket', bearer(makeUser(0)))->assertNotFound();
    $this->assertDatabaseCount('tickets', 1);
    Storage::disk('local')->assertExists('booking-documents/legacy.pdf');

    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'cancelled']);
    $this->getJson($path, bearer($owner))->assertJsonPath('data.ticket.download_available', false)->assertJsonPath('data.ticket.admission', false)
        ->assertJsonPath('data.entry_tickets.state', 'revoked');
    $this->get($path.'/ticket', bearer($owner))->assertNotFound();
});

it('offers a token-free confirmation document only while the booking is confirmed and not revoked', function () {
    Storage::fake('local');
    [$destination, $date] = seedDestination(price: 12000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'confirmation']);
    $path = '/api/v1/bookings/'.$booking->guid.'/documents';
    $this->getJson($path, bearer($owner))->assertJsonPath('data.ticket', null);
    $this->get($path.'/ticket', bearer($owner))->assertNotFound();

    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
    $this->getJson($path, bearer($owner))->assertJsonPath('data.ticket.download_available', true)->assertJsonPath('data.ticket.admission', false)
        ->assertJsonPath('data.ticket.legacy', false);
    Pdf::shouldReceive('loadHTML')->once()->andReturnUsing(function (string $html) {
        expect($html)->toContain('BUKAN tiket masuk')->not->toContain('WDR1')->not->toContain('token');
        $pdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('output')->andReturn('%PDF-confirmation');

        return $pdf;
    });
    $this->get($path.'/ticket', bearer($owner))->assertOk();
    $this->assertDatabaseCount('tickets', 0);
});

it('does not requeue document jobs for confirmed bookings whose invoice is already stored', function () {
    Storage::fake('local');
    Queue::fake();
    [$destination, $date] = seedDestination(price: 12000);
    $owner = makeUser();
    $booking = app(BookingService::class)->create($owner, bookingPayload($destination, $date) + ['idempotency_key' => 'requeue']);
    app(BookingService::class)->decide(makeUser(2), $booking->guid, true);
    DB::table('bookings')->where('guid', $booking->guid)->update(['status' => 'confirmed']);
    app(BookingDocumentService::class)->generate($booking->guid);

    $this->artisan('documents:dispatch')->assertSuccessful();
    $this->artisan('documents:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

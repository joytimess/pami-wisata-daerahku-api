<?php

use App\Models\Destination;
use App\Models\User;
use App\Services\AuthService;
use App\Services\BookingService;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['development.seed' => [
        'superadmin' => ['name' => 'Superadmin Dev', 'email' => 'superadmin.dev@example.test', 'password' => 'dev-super-secret-1'],
        'admin' => ['name' => 'Admin Dev', 'email' => 'admin.dev@example.test', 'password' => 'dev-admin-secret-1'],
    ]]);
});

function seedDevelopment(): void
{
    test()->seed(DevelopmentSeeder::class);
}

/** @return array<string, int> */
function developmentCounts(): array
{
    return collect(['users', 'destination_categories', 'destinations', 'destination_quotas', 'destination_admin_assignments',
        'destination_admin_assignment_histories', 'external_identities', 'bookings', 'payment_intents', 'payment_attempts',
        'payment_events', 'booking_refund_requests', 'booking_tickets', 'tickets', 'invoices'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

it('creates one superadmin, one assigned admin and three bookable destinations without any payment history', function () {
    seedDevelopment();

    $super = User::where('email', 'superadmin.dev@example.test')->firstOrFail();
    $admin = User::where('email', 'admin.dev@example.test')->firstOrFail();
    expect($super->role)->toBe(2)->and($super->status)->toBe('active')->and($super->email_verified_at)->not->toBeNull()
        ->and(Hash::check('dev-super-secret-1', $super->password))->toBeTrue()->and($super->password)->not->toBe('dev-super-secret-1');
    expect($admin->role)->toBe(1)->and($admin->status)->toBe('active')->and(Hash::check('dev-admin-secret-1', $admin->password))->toBeTrue();

    $destinations = DB::table('destinations')->get();
    expect($destinations)->toHaveCount(3)->and($destinations->every(fn ($d) => (bool) $d->is_active && (int) $d->ticket_price > 0 && $d->currency === 'IDR'))->toBeTrue();
    expect(DB::table('destination_categories')->whereIn('guid', $destinations->pluck('destination_category_guid'))->count())->toBeGreaterThan(0);
    foreach ($destinations as $destination) {
        expect(DB::table('destination_admin_assignments')->where('destination_guid', $destination->guid)->where('user_guid', $admin->guid)->exists())->toBeTrue();
        // At least one quota is open now and still far enough ahead for the booking lead time.
        $bookable = DB::table('destination_quotas')->where('destination_guid', $destination->guid)
            ->where('booking_opens_at', '<=', now())->where('booking_closes_at', '>', now()->addDay())->where('capacity', '>', 0)->count();
        expect($bookable)->toBeGreaterThan(0);
    }
    expect(DB::table('destination_admin_assignment_histories')->where('user_guid', $admin->guid)->where('action', 'assigned')->count())->toBe(3);

    $counts = developmentCounts();
    foreach (['external_identities', 'bookings', 'payment_intents', 'payment_attempts', 'payment_events', 'booking_refund_requests', 'booking_tickets', 'tickets', 'invoices'] as $table) {
        expect($counts[$table])->toBe(0, $table);
    }
    Http::assertNothingSent();
});

it('supports a customer booking that the seeded admin can approve into a Midtrans payment window', function () {
    seedDevelopment();
    $destination = Destination::query()->orderBy('name')->firstOrFail();
    $date = (string) DB::table('destination_quotas')->where('destination_guid', $destination->guid)->orderBy('visit_date')->value('visit_date');
    $admin = User::where('email', 'admin.dev@example.test')->firstOrFail();

    $booking = app(BookingService::class)->create(makeUser(), bookingPayload($destination, substr($date, 0, 10), 2) + ['idempotency_key' => 'dev-seed-booking']);
    expect($booking->status)->toBe('pending_approval');
    app(BookingService::class)->decide($admin, $booking->guid, true);

    $row = DB::table('bookings')->where('guid', $booking->guid)->first();
    expect($row->status)->toBe('awaiting_payment')->and((int) $row->total_amount)->toBe(2 * (int) $destination->ticket_price);
    expect(DB::table('payment_intents')->count())->toBe(0);
});

it('lets the seeded accounts sign in with email and password', function () {
    seedDevelopment();
    config(['api_tokens.secret' => str_repeat('s', 64)]);

    expect(app(AuthService::class)->login(['email' => 'admin.dev@example.test', 'password' => 'dev-admin-secret-1']))->toHaveKey('access_token')
        ->and(app(AuthService::class)->login(['email' => 'superadmin.dev@example.test', 'password' => 'dev-super-secret-1']))->toHaveKey('access_token');
});

it('is idempotent and never resets an existing account password', function () {
    seedDevelopment();
    $first = developmentCounts();
    User::where('email', 'admin.dev@example.test')->firstOrFail()->forceFill(['password' => 'changed-by-hand-1'])->save();

    seedDevelopment();

    expect(developmentCounts())->toBe($first);
    expect(Hash::check('changed-by-hand-1', User::where('email', 'admin.dev@example.test')->value('password')))->toBeTrue();
});

it('refuses to change the role of an existing account that uses a seed email', function () {
    $existing = makeUser(0);
    $existing->forceFill(['email' => 'admin.dev@example.test'])->save();

    expect(fn () => seedDevelopment())->toThrow(RuntimeException::class);
    expect(User::where('email', 'admin.dev@example.test')->value('role'))->toBe(0);
    expect(DB::table('destinations')->count())->toBe(0);
});

it('fails closed in production and when credentials are missing', function (string $environment, array $seed) {
    app()['env'] = $environment;
    config(['app.env' => $environment, 'development.seed' => $seed]);

    // Invoked directly, as `db:seed --force` would: the seeder itself must refuse, not only the console prompt.
    expect(fn () => app(DevelopmentSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(DB::table('users')->count())->toBe(0)->and(DB::table('destinations')->count())->toBe(0);
})->with([
    'production' => ['production', [
        'superadmin' => ['name' => 'S', 'email' => 'superadmin.dev@example.test', 'password' => 'dev-super-secret-1'],
        'admin' => ['name' => 'A', 'email' => 'admin.dev@example.test', 'password' => 'dev-admin-secret-1'],
    ]],
    'staging' => ['staging', [
        'superadmin' => ['name' => 'S', 'email' => 'superadmin.dev@example.test', 'password' => 'dev-super-secret-1'],
        'admin' => ['name' => 'A', 'email' => 'admin.dev@example.test', 'password' => 'dev-admin-secret-1'],
    ]],
    'missing password' => ['testing', [
        'superadmin' => ['name' => 'S', 'email' => 'superadmin.dev@example.test', 'password' => ''],
        'admin' => ['name' => 'A', 'email' => 'admin.dev@example.test', 'password' => 'dev-admin-secret-1'],
    ]],
    'short password' => ['testing', [
        'superadmin' => ['name' => 'S', 'email' => 'superadmin.dev@example.test', 'password' => 'dev-super-secret-1'],
        'admin' => ['name' => 'A', 'email' => 'admin.dev@example.test', 'password' => 'short'],
    ]],
    'same email twice' => ['testing', [
        'superadmin' => ['name' => 'S', 'email' => 'same.dev@example.test', 'password' => 'dev-super-secret-1'],
        'admin' => ['name' => 'A', 'email' => 'same.dev@example.test', 'password' => 'dev-admin-secret-1'],
    ]],
]);

it('is not part of the default database seeder', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('DevelopmentSeeder');
});

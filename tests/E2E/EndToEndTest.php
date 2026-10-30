<?php

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
| End-to-end scenarios A-D over real HTTP against a running Laravel server and real MySQL.
| Run: see docs/phase4-5 section "Menjalankan E2E" (php -S on :8099 with DB_DATABASE=wisata_daerahku_e2e),
| then: php artisan test -c phpunit.e2e.xml
*/

beforeEach(function () {
    $db = (string) config('database.connections.mysql.database');
    if (! str_ends_with($db, '_e2e') || config('database.default') !== 'mysql') {
        $this->markTestSkipped('Run with phpunit.e2e.xml against the dedicated *_e2e database.');
    }
    $base = rtrim((string) env('E2E_BASE_URL'), '/');
    try {
        Http::timeout(3)->get($base.'/up');
    } catch (Throwable) {
        $this->markTestSkipped("No Laravel server reachable at {$base}.");
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
});

// The server writes real files (images, PDFs) to the project disk; remove what these tests created.
afterEach(function () {
    foreach ([['destinations', 'image_path'], ['invoices', 'pdf_path'], ['tickets', 'pdf_path']] as [$table, $column]) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
            @unlink(storage_path('app/private/'.$path));
        }
    }
});

function e2e(?string $token = null): PendingRequest
{
    $request = Http::baseUrl(rtrim((string) env('E2E_BASE_URL'), '/').'/api/v1')->acceptJson()->timeout(30);

    return $token ? $request->withToken($token) : $request;
}

function e2eLogin(User $user): string
{
    $response = e2e()->post('auth/login', ['email' => $user->email, 'password' => 'password']);
    expect($response->status())->toBe(200);

    return $response->json('data.access_token');
}

function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==');
}

function e2eQuota(string $token, string $destination, string $date, int $capacity): void
{
    $response = e2e($token)->put("destinations/{$destination}/quotas", [
        'visit_date' => $date, 'capacity' => $capacity,
        'booking_opens_at' => now()->subDay()->toIso8601String(), 'booking_closes_at' => now()->addDay()->toIso8601String(),
    ]);
    expect($response->status())->toBeIn([200, 201]);
}

function e2eDestination(string $adminToken, string $categoryGuid, int $price = 0, string $name = 'Pantai E2E'): string
{
    $response = e2e($adminToken)->post('destinations', [
        'destination_category_guid' => $categoryGuid, 'name' => $name, 'location' => 'Bali', 'description' => 'Indah',
        'ticket_price' => $price, 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Makassar',
    ]);
    expect($response->status())->toBe(201);

    return $response->json('data.guid');
}

it('Scenario A: user books, admin sees and decides, user sees the server status', function () {
    $user = makeUser();
    $admin = makeUser(1);
    $super = makeUser(2);
    $userToken = e2eLogin($user);
    $adminToken = e2eLogin($admin);
    $category = e2e(e2eLogin($super))->post('categories', ['name' => 'Alam', 'slug' => 'alam'])->json('data.guid');
    $paid = e2eDestination($adminToken, $category, 20000, 'Berbayar');
    $free = e2eDestination($adminToken, $category, 0, 'Gratis');
    $date = now()->addDays(6)->toDateString();
    e2eQuota($adminToken, $paid, $date, 10);
    e2eQuota($adminToken, $free, $date, 10);

    expect(e2e($userToken)->get("destinations/{$paid}")->json('data.name'))->toBe('Berbayar');
    $availability = e2e($userToken)->get("destinations/{$paid}/availability", ['visit_date' => $date])->json('data.0');
    expect($availability['status'])->toBe('open')->and($availability['remaining'])->toBe(10);

    $payload = ['destination_guid' => $paid, 'visit_date' => $date, 'quantity' => 3, 'visitor_name' => 'Budi', 'visitor_email' => 'budi@example.org', 'visitor_phone' => '0812'];
    $created = e2e($userToken)->withHeaders(['Idempotency-Key' => 'e2e-a-1'])->post('bookings', $payload);
    expect($created->status())->toBe(200)->and($created->json('data.status'))->toBe('pending_approval');
    $guid = $created->json('data.guid');
    $number = $created->json('data.booking_number');
    expect(DB::table('bookings')->where('guid', $guid)->exists())->toBeTrue();

    $mine = e2e($userToken)->get('bookings')->json('data');
    expect(collect($mine)->firstWhere('guid', $guid)['booking_number'])->toBe($number);
    $adminList = e2e($adminToken)->get('admin/bookings', ['status' => 'pending_approval'])->json('data');
    expect(collect($adminList)->pluck('guid'))->toContain($guid);
    expect(e2e($adminToken)->get("admin/bookings/{$guid}")->json('data.booking_number'))->toBe($number);

    $approved = e2e($adminToken)->post("admin/bookings/{$guid}/approve");
    expect($approved->json('data.status'))->toBe('awaiting_payment');
    expect(e2e($userToken)->get("bookings/{$guid}")->json('data.status'))->toBe('awaiting_payment');
    expect(e2e($userToken)->get('bookings')->json('data.0.status'))->toBe('awaiting_payment');
    expect(e2e($adminToken)->post("admin/bookings/{$guid}/reject")->json('code'))->toBe('booking_not_pending');
    expect(e2e($userToken)->post("bookings/{$guid}/cancel")->json('code'))->toBe('booking_not_cancellable');

    $rejectedPayload = ['destination_guid' => $free, 'quantity' => 1] + $payload;
    $second = e2e($userToken)->withHeaders(['Idempotency-Key' => 'e2e-a-2'])->post('bookings', $rejectedPayload)->json('data.guid');
    e2e($adminToken)->post("admin/bookings/{$second}/reject", ['reason' => 'Penuh']);
    expect(e2e($userToken)->get("bookings/{$second}")->json('data.status'))->toBe('rejected');

    // Free booking -> confirmed -> backend issues documents (queue + scheduler commands run for real).
    $third = e2e($userToken)->withHeaders(['Idempotency-Key' => 'e2e-a-3'])->post('bookings', $rejectedPayload)->json('data.guid');
    expect(e2e($adminToken)->post("admin/bookings/{$third}/approve")->json('data.status'))->toBe('confirmed');
    foreach ([['documents:dispatch'], ['queue:work', '--stop-when-empty', '--quiet']] as $command) {
        $process = new Process([PHP_BINARY, 'artisan', ...$command], base_path());
        $process->setTimeout(120)->run();
        expect($process->getExitCode())->toBe(0);
    }
    $documents = e2e($userToken)->get("bookings/{$third}/documents")->json('data');
    expect($documents['invoice']['status'])->toBe('issued')->and($documents['ticket']['status'])->toBe('issued');
    $pdf = e2e($userToken)->get("bookings/{$third}/documents/invoice");
    expect($pdf->status())->toBe(200)->and($pdf->body())->toStartWith('%PDF');
    expect(e2e(e2eLogin(makeUser()))->get("bookings/{$third}/documents/invoice")->status())->toBe(404);
    expect(e2e($adminToken)->get("bookings/{$third}/documents/ticket")->status())->toBe(200);
    expect(e2e()->get("bookings/{$third}/documents/ticket")->status())->toBe(401);
});

it('Scenario B: quota is never exceeded and cancellation releases capacity', function () {
    $admin = makeUser(1);
    $super = makeUser(2);
    $adminToken = e2eLogin($admin);
    $category = e2e(e2eLogin($super))->post('categories', ['name' => 'Alam', 'slug' => 'alam'])->json('data.guid');
    $destination = e2eDestination($adminToken, $category);
    $date = now()->addDays(6)->toDateString();
    e2eQuota($adminToken, $destination, $date, 5);
    $make = fn (int $quantity) => ['destination_guid' => $destination, 'visit_date' => $date, 'quantity' => $quantity, 'visitor_name' => 'V', 'visitor_email' => 'v@example.org', 'visitor_phone' => '1'];
    $remaining = fn (string $token) => e2e($token)->get("destinations/{$destination}/availability", ['visit_date' => $date])->json('data.0.remaining');

    $a = makeUser();
    $b = makeUser();
    $tokenA = e2eLogin($a);
    $tokenB = e2eLogin($b);
    $first = e2e($tokenA)->withHeaders(['Idempotency-Key' => 'b-1'])->post('bookings', $make(3));
    expect($first->status())->toBe(200)->and($remaining($tokenB))->toBe(2);
    $tooMany = e2e($tokenB)->withHeaders(['Idempotency-Key' => 'b-2'])->post('bookings', $make(3));
    expect($tooMany->status())->toBe(409)->and($tooMany->json('code'))->toBe('insufficient_capacity')->and($tooMany->json('context.remaining'))->toBe(2);
    expect(e2e($tokenB)->withHeaders(['Idempotency-Key' => 'b-3'])->post('bookings', $make(2))->status())->toBe(200);
    expect($remaining($tokenA))->toBe(0)->and(e2e($tokenA)->get("destinations/{$destination}/availability", ['visit_date' => $date])->json('data.0.status'))->toBe('full');
    expect((int) DB::table('bookings')->whereIn('status', ['pending_approval', 'confirmed'])->sum('quantity'))->toBe(5);

    expect(e2e($tokenB)->post('bookings/'.$first->json('data.guid').'/cancel')->status())->toBe(404);
    $cancelled = e2e($tokenA)->post('bookings/'.$first->json('data.guid').'/cancel');
    expect($cancelled->json('data.status'))->toBe('cancelled')->and($remaining($tokenB))->toBe(3);
    expect(e2e($tokenA)->post('bookings/'.$first->json('data.guid').'/cancel')->json('code'))->toBe('booking_already_cancelled');
    expect($remaining($tokenB))->toBe(3);
    // Admin cannot lower capacity below what is still allocated.
    $lower = e2e($adminToken)->put("destinations/{$destination}/quotas", ['visit_date' => $date, 'capacity' => 1, 'booking_opens_at' => now()->subDay()->toIso8601String(), 'booking_closes_at' => now()->addDay()->toIso8601String()]);
    expect($lower->status())->toBe(422);
    $quotas = e2e($adminToken)->get("destinations/{$destination}/quotas")->json('data.0');
    expect([$quotas['capacity'], $quotas['allocated'], $quotas['remaining']])->toBe([5, 2, 3]);

    // Reconciliation over HTTP: same key and payload replay the existing booking; an unknown key is not_found.
    $replay = e2e($tokenA)->withHeaders(['Idempotency-Key' => 'b-1'])->post('bookings', $make(3));
    expect($replay->json('data.guid'))->toBe($first->json('data.guid'))->and($replay->json('data.status'))->toBe('cancelled');
    expect(e2e($tokenA)->get('booking-intents/b-1')->json('data.state'))->toBe('completed');
    expect(e2e($tokenB)->get('booking-intents/b-1')->json('data.state'))->toBe('not_found');
    expect(e2e($tokenA)->get('booking-intents/never-sent')->json('data.state'))->toBe('not_found');
    expect(e2e($tokenA)->post('booking-intents/never-sent/abandon')->json('data.state'))->toBe('abandoned');
    expect(e2e($tokenA)->withHeaders(['Idempotency-Key' => 'never-sent'])->post('bookings', $make(1))->json('code'))->toBe('idempotency_key_abandoned');
});

it('Scenario C: administration to catalog to booking to deactivation stays consistent', function () {
    $super = makeUser(2);
    $admin = makeUser(1);
    $user = makeUser();
    $superToken = e2eLogin($super);
    $adminToken = e2eLogin($admin);
    $userToken = e2eLogin($user);

    $category = e2e($superToken)->post('categories', ['name' => 'Sejarah', 'slug' => 'sejarah']);
    expect($category->status())->toBe(201);
    $categoryGuid = $category->json('data.guid');
    $destination = e2eDestination($adminToken, $categoryGuid, 0, 'Candi E2E');

    $upload = e2e($adminToken)->attach('image', tinyPng(), 'candi.png')->post("destinations/{$destination}/image");
    expect($upload->status())->toBe(200)->and($upload->json('data.has_image'))->toBeTrue();
    $imageUrl = $upload->json('data.image_url');
    expect($imageUrl)->toStartWith("destinations/{$destination}/image?v=")->and($upload->json('data'))->not->toHaveKey('image_path');
    $bad = e2e($adminToken)->attach('image', 'not an image', 'x.png')->post("destinations/{$destination}/image");
    expect($bad->status())->toBe(422);

    $date = now()->addDays(7)->toDateString();
    e2eQuota($adminToken, $destination, $date, 4);

    $list = e2e($userToken)->get('destinations')->json('data');
    $entry = collect($list)->firstWhere('guid', $destination);
    expect($entry['name'])->toBe('Candi E2E');
    // The user loads the picture through the API base URL, not through a storage path.
    $image = e2e()->get($entry['image_url']);
    expect($image->status())->toBe(200)->and($image->header('Content-Type'))->toBe('image/png');
    expect(strlen($image->body()))->toBe(strlen(tinyPng()));

    $booking = e2e($userToken)->withHeaders(['Idempotency-Key' => 'c-1'])->post('bookings', ['destination_guid' => $destination, 'visit_date' => $date, 'quantity' => 2, 'visitor_name' => 'V', 'visitor_email' => 'v@example.org', 'visitor_phone' => '1']);
    expect($booking->status())->toBe(200);
    $guid = $booking->json('data.guid');
    expect(e2e($adminToken)->post("admin/bookings/{$guid}/approve")->json('data.status'))->toBe('confirmed');

    // Replace the image: the new URL is versioned and the old file is gone.
    $replace = e2e($adminToken)->attach('image', tinyPng(), 'baru.png')->post("destinations/{$destination}", ['_method' => 'PATCH', 'name' => 'Candi E2E Baru']);
    expect($replace->status())->toBe(200)->and($replace->json('data.name'))->toBe('Candi E2E Baru');
    expect(e2e()->get($replace->json('data.image_url'))->status())->toBe(200);
    expect(e2e($userToken)->get("destinations/{$destination}")->json('data.name'))->toBe('Candi E2E Baru');

    // Category in use cannot be deactivated; deactivating the destination hides it for users only.
    expect(e2e($superToken)->delete("categories/{$categoryGuid}")->json('code'))->toBe('category_in_use');
    expect(e2e($adminToken)->delete("destinations/{$destination}")->status())->toBe(200);
    expect(collect(e2e($userToken)->get('destinations')->json('data'))->pluck('guid'))->not->toContain($destination);
    expect(e2e($userToken)->get("destinations/{$destination}")->status())->toBe(404);
    expect(e2e()->get("destinations/{$destination}/image")->status())->toBe(404);
    expect(collect(e2e($adminToken)->get('admin/destinations', ['status' => 'inactive'])->json('data'))->pluck('guid'))->toContain($destination);
    expect(e2e($adminToken)->get("admin/destinations/{$destination}/image")->status())->toBe(200);
    // History keeps the snapshot and stays consistent for both sides.
    expect(e2e($userToken)->get("bookings/{$guid}")->json('data.destination_name'))->toBe('Candi E2E');
    expect(e2e($adminToken)->get("admin/bookings/{$guid}")->json('data.status'))->toBe('confirmed');
    expect(e2e($superToken)->delete("categories/{$categoryGuid}")->json('data.is_active'))->toBeFalse();
    expect(e2e($adminToken)->patch("destinations/{$destination}", ['is_active' => true])->json('code'))->toBe('category_inactive');
    expect(e2e($superToken)->patch("categories/{$categoryGuid}", ['is_active' => true])->status())->toBe(200);
    expect(e2e($adminToken)->patch("destinations/{$destination}", ['is_active' => true])->json('data.is_active'))->toBeTrue();
});

it('Scenario D: authorization, isolation and session revocation hold over HTTP', function () {
    $userA = makeUser();
    $userB = makeUser();
    $admin = makeUser(1);
    $super = makeUser(2);
    $tokenA = e2eLogin($userA);
    $tokenB = e2eLogin($userB);
    $adminToken = e2eLogin($admin);
    $superToken = e2eLogin($super);
    $category = e2e($superToken)->post('categories', ['name' => 'Alam', 'slug' => 'alam'])->json('data.guid');
    $destination = e2eDestination($adminToken, $category);
    $date = now()->addDays(6)->toDateString();
    e2eQuota($adminToken, $destination, $date, 10);
    $guid = e2e($tokenA)->withHeaders(['Idempotency-Key' => 'd-1'])->post('bookings', ['destination_guid' => $destination, 'visit_date' => $date, 'quantity' => 1, 'visitor_name' => 'A', 'visitor_email' => 'a@example.org', 'visitor_phone' => '1'])->json('data.guid');

    // 1. Role 0 against every admin surface.
    foreach ([['get', 'admin/bookings'], ['get', "admin/bookings/{$guid}"], ['post', "admin/bookings/{$guid}/approve"], ['get', 'admin/destinations'], ['get', 'admin/categories'], ['get', 'admin/accounts'], ['post', 'categories'], ['post', 'destinations'], ['put', "destinations/{$destination}/quotas"], ['get', "destinations/{$destination}/quotas"]] as [$method, $path]) {
        expect(e2e($tokenA)->{$method}($path)->status())->toBe(403, "$method $path");
    }
    // 2. Role 1 against role-2 endpoints.
    foreach ([['get', 'admin/accounts'], ['post', 'admin/accounts'], ['patch', "admin/accounts/{$userA->guid}"], ['post', 'categories'], ['get', 'admin/categories']] as [$method, $path]) {
        expect(e2e($adminToken)->{$method}($path)->status())->toBe(403, "$method $path");
    }
    // 3. Unauthenticated.
    expect(e2e()->get('bookings')->status())->toBe(401);
    expect(e2e()->get('admin/bookings')->status())->toBe(401);
    // 4. User A's data is invisible to user B.
    expect(e2e($tokenB)->get("bookings/{$guid}")->status())->toBe(404);
    expect(e2e($tokenB)->get('bookings')->json('data'))->toBe([]);
    expect(e2e($tokenB)->post("bookings/{$guid}/cancel")->status())->toBe(404);
    expect(e2e($tokenB)->get("bookings/{$guid}/documents")->status())->toBe(404);
    expect(e2e($tokenB)->get('booking-intents/d-1')->json('data.booking'))->toBeNull();
    // 5. Logout revokes the session immediately; role/status changes revoke at once.
    expect(e2e($tokenA)->post('auth/logout')->status())->toBe(200);
    expect(e2e($tokenA)->get('bookings')->status())->toBe(401);
    expect(e2e($adminToken)->get('admin/bookings')->status())->toBe(200);
    expect(e2e($superToken)->patch("admin/accounts/{$admin->guid}", ['status' => 'suspended'])->status())->toBe(200);
    expect(e2e($adminToken)->get('admin/bookings')->status())->toBe(401);
    expect(e2e()->post('auth/login', ['email' => $admin->email, 'password' => 'password'])->status())->toBe(401);
    expect(e2e($superToken)->patch("admin/accounts/{$super->guid}", ['role' => 1])->status())->toBe(422);
    // 6. Google login for admin roles: see AuthSecurityTest (Firebase token verification is mocked there; not exercised here).
    expect(e2e()->post('auth/google', ['id_token' => 'invalid'])->status())->toBeIn([401, 422, 500]);
});

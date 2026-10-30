<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature', 'Mysql', 'E2E');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bearer header for a user, issued through the real login path. */
function bearer(User $user): array
{
    config(['api_tokens.secret' => str_repeat('s', 64)]);

    return ['Authorization' => 'Bearer '.app(AuthService::class)->login(['email' => $user->email, 'password' => 'password'])['access_token']];
}

function makeUser(int $role = 0, string $status = 'active'): User
{
    return User::factory()->create(['role' => $role, 'status' => $status]);
}

/** Active destination with a bookable quota row (opens yesterday, closes tomorrow, visit in `$days` days). */
function seedDestination(int $capacity = 10, int $price = 0, int $days = 5, array $destination = []): array
{
    $category = DestinationCategory::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature']);
    $dest = Destination::create($destination + ['destination_category_guid' => $category->guid, 'name' => 'Park '.Str::random(4), 'location' => 'Town', 'description' => 'Park', 'ticket_price' => $price, 'currency' => 'IDR', 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Jakarta']);
    $date = now()->addDays($days)->toDateString();
    $quotaGuid = (string) Str::uuid();
    DB::table('destination_quotas')->insert(['guid' => $quotaGuid, 'destination_guid' => $dest->guid, 'visit_date' => $date, 'capacity' => $capacity, 'booking_opens_at' => now()->subDay(), 'booking_closes_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    return [$dest, $date, $quotaGuid];
}

function bookingPayload(Destination $destination, string $date, int $quantity = 1): array
{
    return ['destination_guid' => $destination->guid, 'visit_date' => $date, 'quantity' => $quantity, 'visitor_name' => 'Visitor', 'visitor_email' => 'visitor@example.com', 'visitor_phone' => '0812'];
}

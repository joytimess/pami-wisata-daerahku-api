<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\BookingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opt-in development data after a database reset: one superadmin, one admin assigned to three bookable destinations.
 * It creates NEW development data; it does not restore any earlier account, destination or payment history.
 *
 * Run explicitly: php artisan db:seed --class=DevelopmentSeeder (credentials from DEV_SEED_* in the local .env).
 * Idempotent: accounts are matched by email and never overwritten; destinations use fixed GUIDs; quotas are unique per date.
 * No customer account, Google identity, booking, payment, ticket or refund is created.
 */
class DevelopmentSeeder extends Seeder
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'development', 'testing'];

    /** Days ahead for which quotas exist; the first bookable day must clear the 25h15m booking lead time. */
    private const QUOTA_FIRST_DAY = 2;

    private const QUOTA_LAST_DAY = 30;

    private const CATEGORIES = [
        ['guid' => 'a7d3c1e0-5b2f-4c8e-9f10-0d0e5e7a1001', 'slug' => 'alam', 'name' => 'Wisata Alam'],
        ['guid' => 'a7d3c1e0-5b2f-4c8e-9f10-0d0e5e7a1002', 'slug' => 'budaya', 'name' => 'Wisata Budaya'],
    ];

    private const DESTINATIONS = [
        [
            'guid' => 'b8e4d2f1-6c3a-4d9f-8a21-1e1f6f8b2001', 'category' => 'alam', 'name' => 'Curug Cikaso (Dev)',
            'location' => 'Kabupaten Sukabumi, Jawa Barat', 'ticket_price' => 15000, 'capacity' => 100,
            'opening_hours' => '07:00-17:00', 'timezone' => 'Asia/Jakarta', 'latitude' => -7.3634000, 'longitude' => 106.6185000,
            'description' => 'Air terjun bertingkat dengan kolam alami. Data development untuk menguji booking dan pembayaran.',
        ],
        [
            'guid' => 'b8e4d2f1-6c3a-4d9f-8a21-1e1f6f8b2002', 'category' => 'budaya', 'name' => 'Kampung Adat Ciptagelar (Dev)',
            'location' => 'Kabupaten Sukabumi, Jawa Barat', 'ticket_price' => 25000, 'capacity' => 50,
            'opening_hours' => '08:00-16:00', 'timezone' => 'Asia/Jakarta', 'latitude' => -6.7867000, 'longitude' => 106.4917000,
            'description' => 'Kampung adat dengan tradisi pertanian padi. Data development untuk menguji booking dan pembayaran.',
        ],
        [
            'guid' => 'b8e4d2f1-6c3a-4d9f-8a21-1e1f6f8b2003', 'category' => 'alam', 'name' => 'Pantai Ujung Genteng (Dev)',
            'location' => 'Kabupaten Sukabumi, Jawa Barat', 'ticket_price' => 10000, 'capacity' => 200,
            'opening_hours' => '06:00-18:00', 'timezone' => 'Asia/Jakarta', 'latitude' => -7.3719000, 'longitude' => 106.3986000,
            'description' => 'Pantai berpasir putih dengan area penangkaran penyu. Data development untuk menguji booking dan pembayaran.',
        ],
    ];

    public function run(): void
    {
        $environment = (string) config('app.env');
        if (app()->isProduction() || ! app()->environment(self::ALLOWED_ENVIRONMENTS) || ! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException('DevelopmentSeeder runs only in local, development or testing environments.');
        }
        $accounts = $this->accounts();

        DB::transaction(function () use ($accounts): void {
            $super = $this->account($accounts['superadmin'], 2);
            $admin = $this->account($accounts['admin'], 1);
            $categories = $this->categories();
            foreach (self::DESTINATIONS as $destination) {
                $this->destination($destination, $categories[$destination['category']]);
                $this->quotas($destination);
                $this->assign($destination['guid'], $admin->guid, $super->guid);
            }
        }, 3);

        $this->command?->info('Development data ready: 1 superadmin, 1 admin, '.count(self::DESTINATIONS).' destinations. Log in with the DEV_SEED_* credentials.');
    }

    /** @return array{superadmin: array{name: string, email: string, password: string}, admin: array{name: string, email: string, password: string}} */
    private function accounts(): array
    {
        $accounts = [];
        foreach (['superadmin', 'admin'] as $key) {
            $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) config("development.seed.$key"));
            $data['email'] = strtolower((string) ($data['email'] ?? ''));
            $validator = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8']]);
            if ($validator->fails()) {
                $upper = strtoupper($key);
                throw new RuntimeException("Set DEV_SEED_{$upper}_EMAIL and DEV_SEED_{$upper}_PASSWORD (at least 8 characters) in the local .env: ".$validator->errors()->first());
            }
            $accounts[$key] = $validator->validated();
        }
        if ($accounts['superadmin']['email'] === $accounts['admin']['email']) {
            throw new RuntimeException('The development superadmin and admin must use different emails.');
        }

        return $accounts;
    }

    /** Creates the account once. An existing account is kept as is; one with another role is never promoted or demoted. */
    private function account(array $data, int $role): User
    {
        $user = User::whereRaw('lower(email) = ?', [$data['email']])->lockForUpdate()->first();
        if ($user) {
            if ((int) $user->role !== $role) {
                throw new RuntimeException("Account {$data['email']} already exists with another role; the seeder does not change roles.");
            }

            return $user;
        }
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->forceFill(['role' => $role, 'status' => 'active', 'email_verified_at' => now()])->save();

        return $user;
    }

    /** @return array<string, string> slug => guid */
    private function categories(): array
    {
        $guids = [];
        foreach (self::CATEGORIES as $category) {
            DB::table('destination_categories')->insertOrIgnore(['guid' => $category['guid'], 'slug' => $category['slug'],
                'name' => $category['name'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $guids[$category['slug']] = (string) DB::table('destination_categories')->where('slug', $category['slug'])->value('guid');
        }

        return $guids;
    }

    private function destination(array $destination, string $categoryGuid): void
    {
        if (DB::table('destinations')->where('guid', $destination['guid'])->exists()) {
            return; // Keep any local edits.
        }
        DB::table('destinations')->insert(['guid' => $destination['guid'], 'destination_category_guid' => $categoryGuid,
            'name' => $destination['name'], 'location' => $destination['location'], 'description' => $destination['description'],
            'ticket_price' => $destination['ticket_price'], 'currency' => 'IDR', 'opening_hours' => $destination['opening_hours'],
            'timezone' => $destination['timezone'], 'latitude' => $destination['latitude'], 'longitude' => $destination['longitude'],
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Adds missing future quota dates; existing dates (and their allocations) are left untouched. */
    private function quotas(array $destination): void
    {
        $opensAt = CarbonImmutable::now()->utc()->startOfMinute();
        $today = CarbonImmutable::now($destination['timezone'])->startOfDay();
        for ($day = self::QUOTA_FIRST_DAY; $day <= self::QUOTA_LAST_DAY; $day++) {
            $date = $today->addDays($day)->toDateString();
            DB::table('destination_quotas')->insertOrIgnore(['guid' => (string) Str::uuid(), 'destination_guid' => $destination['guid'],
                'visit_date' => $date, 'capacity' => $destination['capacity'], 'booking_opens_at' => $opensAt,
                'booking_closes_at' => BookingRules::visitStart($date, $destination['timezone'])->subDay(),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function assign(string $destinationGuid, string $adminGuid, string $actorGuid): void
    {
        $inserted = DB::table('destination_admin_assignments')->insertOrIgnore(['guid' => (string) Str::uuid(),
            'destination_guid' => $destinationGuid, 'user_guid' => $adminGuid, 'assigned_by_user_guid' => $actorGuid, 'created_at' => now()]);
        if ($inserted > 0) {
            DB::table('destination_admin_assignment_histories')->insert(['guid' => (string) Str::uuid(), 'destination_guid' => $destinationGuid,
                'user_guid' => $adminGuid, 'actor_guid' => $actorGuid, 'action' => 'assigned', 'created_at' => now()]);
        }
    }
}

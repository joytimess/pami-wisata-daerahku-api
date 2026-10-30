<?php

use App\Models\Destination;
use App\Models\DestinationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function destinationFields(DestinationCategory $category, array $over = []): array
{
    return $over + ['destination_category_guid' => $category->guid, 'name' => 'Pantai Baru', 'location' => 'Bali', 'description' => 'Indah', 'ticket_price' => 15000, 'opening_hours' => '08:00-17:00', 'timezone' => 'Asia/Makassar'];
}

it('manages categories with role 2 only and protects categories in use', function () {
    $super = makeUser(2);
    $admin = makeUser(1);
    $this->postJson('/api/v1/categories', ['name' => 'Alam', 'slug' => 'alam'], bearer($admin))->assertForbidden();
    $this->getJson('/api/v1/admin/categories', bearer($admin))->assertForbidden();
    $guid = $this->postJson('/api/v1/categories', ['name' => 'Alam', 'slug' => 'alam'], bearer($super))->assertCreated()->json('data.guid');
    $this->postJson('/api/v1/categories', ['name' => 'Alam 2', 'slug' => 'alam'], bearer($super))->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson("/api/v1/categories/{$guid}", ['name' => 'Alam Indonesia', 'slug' => 'alam'], bearer($super))->assertOk()->assertJsonPath('data.name', 'Alam Indonesia');

    $category = DestinationCategory::find($guid);
    $destination = $this->postJson('/api/v1/destinations', destinationFields($category), bearer($admin))->assertCreated()->json('data.guid');
    $this->deleteJson("/api/v1/categories/{$guid}", [], bearer($super))->assertStatus(409)->assertJsonPath('code', 'category_in_use');
    $this->patchJson("/api/v1/categories/{$guid}", ['is_active' => false], bearer($super))->assertStatus(409)->assertJsonPath('code', 'category_in_use');
    expect(DestinationCategory::find($guid)->is_active)->toBeTrue();

    $this->deleteJson("/api/v1/destinations/{$destination}", [], bearer($admin))->assertOk();
    $this->deleteJson("/api/v1/categories/{$guid}", [], bearer($super))->assertOk()->assertJsonPath('data.is_active', false);
    // The relation is preserved; the inactive destination can still be read by admins.
    expect(Destination::find($destination)->destination_category_guid)->toBe($guid);
    $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/categories?status=inactive', bearer($super))->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/categories?status=active', bearer($super))->assertJsonCount(0, 'data');
    // An inactive-category destination cannot be reactivated.
    $this->patchJson("/api/v1/destinations/{$destination}", ['is_active' => true], bearer($admin))->assertStatus(409)->assertJsonPath('code', 'category_inactive');
    $this->patchJson("/api/v1/categories/{$guid}", ['is_active' => true], bearer($super))->assertOk();
    $this->patchJson("/api/v1/destinations/{$destination}", ['is_active' => true], bearer($admin))->assertOk()->assertJsonPath('data.is_active', true);
});

it('creates, updates, deactivates and reactivates destinations with the full contract', function () {
    $admin = makeUser(1);
    $user = makeUser();
    $category = DestinationCategory::create(['name' => 'Alam', 'slug' => 'alam']);
    $this->postJson('/api/v1/destinations', destinationFields($category), bearer($user))->assertForbidden();
    $this->postJson('/api/v1/destinations', destinationFields($category, ['timezone' => 'WIB']), bearer($admin))->assertUnprocessable()->assertJsonValidationErrors('timezone');
    $body = destinationFields($category);
    unset($body['timezone']);
    $this->postJson('/api/v1/destinations', $body, bearer($admin))->assertUnprocessable()->assertJsonValidationErrors('timezone');

    $guid = $this->postJson('/api/v1/destinations', destinationFields($category), bearer($admin))->assertCreated()
        ->assertJsonPath('data.timezone', 'Asia/Makassar')->assertJsonPath('data.has_image', false)->assertJsonPath('data.image_url', null)->json('data.guid');
    $this->patchJson("/api/v1/destinations/{$guid}", ['name' => 'Pantai Lama', 'ticket_price' => 0], bearer($admin))->assertOk();
    $this->getJson("/api/v1/destinations/{$guid}")->assertOk()->assertJsonPath('data.name', 'Pantai Lama');

    $this->deleteJson("/api/v1/destinations/{$guid}", [], bearer($admin))->assertOk();
    $this->getJson("/api/v1/destinations/{$guid}")->assertNotFound();
    $this->getJson('/api/v1/destinations')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/destinations?status=inactive', bearer($admin))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
    $this->getJson('/api/v1/admin/destinations?status=active', bearer($admin))->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/admin/destinations/{$guid}", bearer($admin))->assertOk();
    $this->getJson('/api/v1/admin/destinations', bearer($user))->assertForbidden();
    $this->getJson('/api/v1/admin/destinations')->assertUnauthorized();

    $this->patchJson("/api/v1/destinations/{$guid}", ['is_active' => true], bearer($admin))->assertOk();
    $this->getJson("/api/v1/destinations/{$guid}")->assertOk();
});

it('serves destination images through an authorized media endpoint and replaces them safely', function () {
    Storage::fake('local');
    $admin = makeUser(1);
    $user = makeUser();
    $category = DestinationCategory::create(['name' => 'Alam', 'slug' => 'alam']);
    $guid = $this->postJson('/api/v1/destinations', destinationFields($category), bearer($admin))->json('data.guid');

    $this->post("/api/v1/destinations/{$guid}/image", ['image' => UploadedFile::fake()->image('a.png')], bearer($user) + ['Accept' => 'application/json'])->assertForbidden();
    $this->post("/api/v1/destinations/{$guid}/image", ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], bearer($admin) + ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/v1/destinations/{$guid}/image", ['image' => UploadedFile::fake()->image('big.jpg')->size(5121)], bearer($admin) + ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/v1/destinations/{$guid}/image", [], bearer($admin) + ['Accept' => 'application/json'])->assertUnprocessable();

    $first = $this->post("/api/v1/destinations/{$guid}/image", ['image' => UploadedFile::fake()->image('a.png')], bearer($admin) + ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.has_image', true);
    expect($first->json('data.image_url'))->toStartWith("destinations/{$guid}/image?v=")->not->toContain('destinations/'.basename(Destination::find($guid)->image_path));
    $oldPath = Destination::find($guid)->image_path;
    Storage::disk('local')->assertExists($oldPath);

    $this->get("/api/v1/destinations/{$guid}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->post("/api/v1/destinations/{$guid}/image", ['image' => UploadedFile::fake()->image('b.webp')], bearer($admin) + ['Accept' => 'application/json'])->assertOk();
    $newPath = Destination::find($guid)->image_path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($newPath);
    $this->get("/api/v1/destinations/{$guid}/image")->assertOk();

    // Multipart edit through method spoofing keeps working for combined field + image updates.
    $this->post("/api/v1/destinations/{$guid}", ['_method' => 'PATCH', 'name' => 'Edited', 'image' => UploadedFile::fake()->image('c.jpg')], bearer($admin) + ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.name', 'Edited');
    Storage::disk('local')->assertMissing($newPath);

    $this->deleteJson("/api/v1/destinations/{$guid}", [], bearer($admin))->assertOk();
    $this->get("/api/v1/destinations/{$guid}/image")->assertNotFound();
    $this->get("/api/v1/admin/destinations/{$guid}/image", bearer($admin))->assertOk();
    $this->get("/api/v1/admin/destinations/{$guid}/image", bearer($user))->assertForbidden();
});

it('exposes total, allocated and remaining quota with strict window validation', function () {
    $admin = makeUser(1);
    $user = makeUser();
    [$destination, $date, $quota] = seedDestination(capacity: 10);
    $path = "/api/v1/destinations/{$destination->guid}/quotas";
    $visit = now()->addDays(20)->toDateString();
    $valid = ['visit_date' => $visit, 'capacity' => 8, 'booking_opens_at' => now()->toIso8601String(), 'booking_closes_at' => now()->addDays(10)->toIso8601String()];

    $this->putJson($path, $valid, bearer($user))->assertForbidden();
    $this->putJson($path, array_replace($valid, ['capacity' => -1]), bearer($admin))->assertUnprocessable();
    $this->putJson($path, array_replace($valid, ['booking_opens_at' => now()->format('Y-m-d H:i:s')]), bearer($admin))->assertUnprocessable()->assertJsonValidationErrors('booking_opens_at');
    $this->putJson($path, array_replace($valid, ['booking_closes_at' => now()->subDay()->toIso8601String()]), bearer($admin))->assertUnprocessable();
    $this->putJson($path, array_replace($valid, ['booking_closes_at' => now()->addDays(40)->toIso8601String()]), bearer($admin))->assertUnprocessable()->assertJsonValidationErrors('booking_closes_at');
    $this->putJson($path, $valid, bearer($admin))->assertCreated()->assertJsonPath('data.capacity', 8)->assertJsonPath('data.allocated', 0)->assertJsonPath('data.remaining', 8);

    $this->postJson('/api/v1/bookings', bookingPayload($destination, $date, 4), bearer($user) + ['Idempotency-Key' => 'q-1'])->assertOk();
    $this->getJson($path, bearer($admin))->assertOk()->assertJsonPath('data.0.visit_date', $date)
        ->assertJsonPath('data.0.capacity', 10)->assertJsonPath('data.0.allocated', 4)->assertJsonPath('data.0.remaining', 6);
    $this->putJson($path, array_replace($valid, ['visit_date' => $date, 'capacity' => 3, 'booking_closes_at' => now()->addHours(12)->toIso8601String()]), bearer($admin))
        ->assertUnprocessable()->assertJsonValidationErrors('capacity');
    $this->putJson($path, array_replace($valid, ['visit_date' => $date, 'capacity' => 4, 'booking_closes_at' => now()->addHours(12)->toIso8601String()]), bearer($admin))
        ->assertOk()->assertJsonPath('data.remaining', 0);
    $this->postJson('/api/v1/bookings', bookingPayload($destination, $date, 1), bearer($user) + ['Idempotency-Key' => 'q-2'])->assertStatus(409)->assertJsonPath('code', 'insufficient_capacity');
    $this->getJson($path, bearer($user))->assertForbidden();
});

it('lists, creates and updates admin accounts for role 2 only and prevents lockout', function () {
    $super = makeUser(2);
    $other = makeUser(2);
    $admin = makeUser(1);
    $user = makeUser();
    $this->getJson('/api/v1/admin/accounts', bearer($admin))->assertForbidden();
    $this->getJson('/api/v1/admin/accounts', bearer($user))->assertForbidden();
    $this->getJson('/api/v1/admin/accounts')->assertUnauthorized();
    $this->getJson('/api/v1/admin/accounts', bearer($super))->assertOk()->assertJsonCount(4, 'data');
    $this->getJson('/api/v1/admin/accounts?role=1', bearer($super))->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/accounts?search='.urlencode($admin->email), bearer($super))->assertJsonCount(1, 'data');
    expect($this->getJson('/api/v1/admin/accounts', bearer($super))->getContent())->not->toContain('password');

    $this->postJson('/api/v1/admin/accounts', ['name' => 'Baru', 'email' => 'baru@example.org', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 2], bearer($admin))->assertForbidden();
    $created = $this->postJson('/api/v1/admin/accounts', ['name' => 'Baru', 'email' => 'baru@example.org', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 2], bearer($super))->assertCreated();
    $created->assertJsonPath('data.role', 1);
    $this->postJson('/api/v1/admin/accounts', ['name' => 'Baru', 'email' => 'baru@example.org', 'password' => 'password123', 'password_confirmation' => 'password123'], bearer($super))->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/admin/accounts', ['name' => 'X', 'email' => 'bukan-email', 'password' => 'short'], bearer($super))->assertUnprocessable();

    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['role' => 2], bearer($admin))->assertForbidden();
    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['role' => 7], bearer($super))->assertUnprocessable();
    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['status' => 'deleted'], bearer($super))->assertUnprocessable();
    $this->patchJson("/api/v1/admin/accounts/{$super->guid}", ['role' => 1], bearer($super))->assertUnprocessable();
    $this->patchJson("/api/v1/admin/accounts/{$super->guid}", ['status' => 'suspended'], bearer($super))->assertUnprocessable();
    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['status' => 'suspended'], bearer($super))->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->patchJson("/api/v1/admin/accounts/{$admin->guid}", ['status' => 'active'], bearer($super))->assertOk()->assertJsonPath('data.status', 'active');

    // Removing the second superadmin is fine; the last active one is protected.
    $this->patchJson("/api/v1/admin/accounts/{$other->guid}", ['status' => 'suspended'], bearer($super))->assertOk();
    expect(DB::table('users')->where('role', 2)->where('status', 'active')->count())->toBe(1);
    $this->patchJson("/api/v1/admin/accounts/{$super->guid}", ['role' => 1], bearer($super))->assertUnprocessable();
});

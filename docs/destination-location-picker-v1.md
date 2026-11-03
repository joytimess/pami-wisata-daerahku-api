# Destination location picker V1

`location` remains the editable address (nonempty, maximum 255 characters). `latitude` and `longitude` are nullable `DECIMAL(10,7)` columns. Existing records remain null; no backfill runs.

## Migration

Run `php artisan migrate --no-interaction` before using the updated API. The new migration is `2026_11_03_110434_add_coordinates_to_destinations_table.php`. Its rollback drops both columns and loses saved coordinates; prefer a forward fix once populated.

## API

- `POST /api/v1/destinations`: existing authenticated admin gallery create; requires `location`, `latitude` [-90,90], `longitude` [-180,180] alongside existing fields/images and `Idempotency-Key`.
- `PATCH /api/v1/destinations/{guid}`: omit coordinates to keep the existing pair (including legacy null). If either coordinate is supplied, both must be numeric and in range; explicit null pairs are rejected. Address-only edits preserve coordinates.
- Gallery update retains `POST` with `_method=PATCH`, `gallery_manifest`, `gallery_version`, and `Idempotency-Key`. Coordinates are metadata in the existing operation hash and transaction. Same key/payload replays; changed coordinates with the same key conflict. A stale gallery version commits no location change.
- Public/admin destination reads include `location`, `latitude`, `longitude`; coordinates serialize as JSON numbers or null. Existing public visibility restrictions remain.
- `GET /api/v1/admin/geocoding/reverse?latitude=-7.8012345&longitude=110.3645678`: active role 1/2 only. Returns `{"data":{"latitude":-7.8012345,"longitude":110.3645678,"formatted_address":"Address or null","provider":"nominatim"}}`. No result returns actual JSON null. It never saves destination state.
- Invalid coordinates/address return standard `422` field errors. Geocoding busy/limit returns `429` with `Retry-After`; malformed/upstream failure returns `502`; connection/shared-cache failure returns `503`. Enter an address manually when geocoding fails.

## Provider configuration

`.env.example` lists `GEOCODING_REVERSE_URL`, `GEOCODING_USER_AGENT`, `GEOCODING_CACHE_STORE`, `GEOCODING_TIMEOUT`, `GEOCODING_CACHE_SECONDS`. Default cache store is `database`; all workers/hosts must use the same database cache and lock tables, or the same configured Redis cache and lock connections. Array/file/failover stores are refused. Ensure the normal cache-table migration has run. Cache/lock outages fail closed without contacting the provider.

The application deliberately uses public Nominatim for this small demo. Follow the [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/): application-wide maximum 1 request/second, identifying User-Agent, visible OSM attribution, cached results, no autocomplete or bulk/grid lookup. The service serializes upstream calls with a shared lock and a 1.1-second minimum start interval, caches addresses for 24 hours by default, and applies at least 60 seconds of cooldown on provider `429`/`503` or connection failure. It performs no automatic HTTP retry. Switch the configured provider for larger use; do not flush provider cache/rate keys during traffic.

## Focused smoke

1. Create with pin/address/gallery, reopen public and admin detail, and compare the returned coordinate pair.
2. Edit only address; confirm the pair is unchanged. Edit a legacy destination without a pin; confirm both coordinates remain null.
3. Change pin and gallery together; simulate stale `gallery_version` and confirm neither location nor gallery changed. Retry an unchanged payload/key; confirm no duplicate destination.
4. Request reverse as visitor (403), without token (401), and admin (200). Rapidly choose another uncached point (429); manual address must remain usable.
5. Simulate provider/tile/GPS failures in Flutter; keep confirmed pin and allow manual address. Detail navigation must use numeric coordinates.

Local focused tests use SQLite and fake provider HTTP. They do not prove live provider availability, MySQL multi-process behavior, device GPS/tiles, or production readiness. No migration is applied to the user's database by these checks.

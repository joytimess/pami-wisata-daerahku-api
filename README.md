# Wisata Daerahku Backend API

Laravel 13 API, currently under development. The implementation is not ready for production: payment provider, payment reconciliation, final visit cutoff and MySQL concurrency verification remain outstanding. No Firebase business data is migrated.

## Local setup

Install PHP 8.3+, Composer and MySQL, run `composer install`, copy `.env.example` to `.env`, and set `APP_KEY` with `php artisan key:generate`. Set `DB_CONNECTION=mysql` and the database connection values for an empty dedicated database. Set a random `JWT_SECRET` of at least 32 characters, `JWT_ISSUER`, `JWT_AUDIENCE`, and `FIREBASE_PROJECT_ID` when Google sign-in is configured. Never commit credentials. Run `php artisan migrate`; bootstrap a superadmin with `php artisan app:bootstrap-superadmin` (interactive). Configure SMTP and `PASSWORD_RESET_URL` for the mobile client before using email recovery. Use `QUEUE_CONNECTION=database`, `php artisan queue:work`, and schedule `php artisan schedule:run` every minute. Auth verification emails require a working queue; configure FIREBASE_CREDENTIALS with the path to a service account JSON for FCM.

Timestamps are UTC; visit dates are interpreted in each destination's timezone. The current conservative provisional cutoff requires creation more than 25 hours 15 minutes before midnight at the start of the visit date and approval more than 1 hour 15 minutes before it. Product approval of the final cutoff is a release gate. Pending payment remains allocated: after the payment deadline it enters verification and, after grace, review; it is never treated as unpaid without a verified provider response. Access tokens last 15 minutes; refresh tokens last 30 days and are stored hashed and rotated. Laravel infrastructure tables retain vendor column names; sessions use a UUID-compatible user reference.

## API examples

All endpoints use `/api/v1`; protected requests use `Authorization: Bearer <access_token>`. Collection responses are paginated. Examples:

- `POST /auth/register` with JSON `{"name":"Example","email":"person@example.test","password":"example-password","password_confirmation":"example-password"}`.
- `POST /auth/login` with `email` and `password`; `POST /auth/refresh` with `refresh_token`; `POST /auth/logout`; `GET /auth/me`.
- `GET /categories`, `GET /destinations?search=park`; administrators manage destinations and dated quotas, superadmins manage categories.
- `PUT /destinations/{guid}/quotas` with `visit_date`, integer `capacity`, `booking_opens_at` and `booking_closes_at`.
- `POST /bookings` with header `Idempotency-Key` and JSON `destination_guid`, `visit_date`, positive `quantity`, `visitor_name`, `visitor_email`, `visitor_phone`, optional `notes`. Price is calculated by the server. `GET /bookings`, `GET /bookings/{guid}`; admin `POST /admin/bookings/{guid}/approve` or `/reject`.
- `GET /notifications`, `GET /notifications/unread-count`, `PATCH /notifications/{guid}/read`; `PUT /devices` with `token` and `platform`, `DELETE /devices` with `token`.
- `GET /favorites`, `PUT` or `DELETE /favorites/{destination_guid}`.

Run `php artisan route:list --path=api` for the complete implemented route list. Payment, webhook, review and check-in endpoints are not implemented. Confirmed bookings receive a private confirmation invoice and group ticket PDF through the scheduled document dispatcher. The invoice is not proof of payment; its final semantics remain a release decision. Documents are available at GET /bookings/{guid}/documents and GET /bookings/{guid}/documents/{invoice|ticket}. A worker crash can leave an orphan private file; files deleted after their database records are created are not automatically regenerated. Email delivery uses the configured transport and may be retried; external delivery is at least once, not exactly once.

## Validation and deployment limits

Run `php artisan test` and `vendor/bin/pint --test`. SQLite tests do not demonstrate MySQL row locking. Run dedicated MySQL concurrency tests before release; do not run destructive test migrations against existing data. Real Firebase credentials, SMTP, FCM device delivery, gateway sandbox, operational alerting and deployment remain unverified. Production decisions remain open for payment provider, refund/cancellation, review duration, cutoff, invoice semantics, data import and review/check-in rules.

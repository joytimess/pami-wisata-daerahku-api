<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminAccountController;
use App\Http\Controllers\Api\V1\ApprovalQueueController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingDocumentController;
use App\Http\Controllers\Api\V1\BookingRefundController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\FinancialDashboardController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\LocationGeocodingController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\OperationalDashboardController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentPreviewController;
use App\Http\Controllers\Api\V1\PaymentRecoveryController;
use App\Http\Controllers\Api\V1\QuotaController;
use App\Http\Controllers\Api\V1\TicketCheckInController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\ThrottlePasswordReset;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 â€” Wisata Daerahku
|--------------------------------------------------------------------------
| Routes are grouped by feature. Public and authenticated endpoints remain
| within their respective feature prefixes. Existing URI, HTTP method,
| controller action, middleware, rate limits and route names are preserved.
| See docs/API-ROUTES.md for the endpoint inventory and conventions.
*/
Route::prefix('v1')->group(function () {
    Route::post('webhooks/midtrans', MidtransWebhookController::class)->middleware('throttle:300,1')->name('payments.midtrans.webhook');
    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::post('bookings/{booking}/payment-attempts', [PaymentController::class, 'initiate'])->middleware('throttle:30,1')->name('payments.initiate');
        Route::post('bookings/{booking}/payment-attempts/{attempt}/refresh', [PaymentController::class, 'refresh'])->whereUuid('attempt')
            ->middleware('throttle:20,1')->name('payments.refresh');
        Route::post('bookings/{booking}/payment-attempts/{attempt}/cancel', [PaymentController::class, 'cancelAttempt'])->whereUuid('attempt')
            ->middleware('throttle:10,1')->name('payments.cancel');
        Route::get('bookings/{booking}/payment-attempts/{attempt}/qr', [PaymentController::class, 'qrisImage'])->whereUuid('attempt')
            ->middleware('throttle:60,1')->name('payments.qr');
        Route::get('payments/methods', [PaymentController::class, 'methods'])->middleware('throttle:60,1');
        Route::get('bookings/{booking}/payment-intent', [PaymentController::class, 'show'])->middleware('throttle:60,1');
        Route::get('admin/bookings/{booking}/payment-intent', [PaymentController::class, 'adminShow'])->middleware('throttle:60,1');
        Route::post('bookings/{booking}/demo-payment', [PaymentController::class, 'initiateDemo'])->middleware('throttle:30,1')->name('payments.demo.initiate');
        Route::post('bookings/{booking}/demo-payment/simulate', [PaymentController::class, 'simulateDemo'])->middleware('throttle:30,1')->name('payments.demo.simulate');
        Route::get('bookings/{booking}/payment-preview', PaymentPreviewController::class);
        Route::get('admin/dashboard/financial', FinancialDashboardController::class)->middleware('throttle:60,1')->name('admin.dashboard.financial');
        Route::get('admin/dashboard/{section}', OperationalDashboardController::class)
            ->whereIn('section', ['overview', 'approvals', 'destinations', 'visitors', 'action-center'])->name('admin.dashboard');
        Route::get('admin/approval-queue', OperationalDashboardController::class)->name('admin.approval-queue');
        Route::post('admin/bookings/{booking}/claim', [ApprovalQueueController::class, 'claim'])->middleware('throttle:60,1');
        Route::post('admin/bookings/{booking}/release', [ApprovalQueueController::class, 'release'])->middleware('throttle:60,1');
        Route::post('superadmin/bookings/{booking}/reassign', [ApprovalQueueController::class, 'reassign'])->middleware('throttle:60,1')->name('superadmin.bookings.reassign');
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication & Email Verification
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('google', [AuthController::class, 'google']);
            Route::post('refresh', [AuthController::class, 'refresh']);

            Route::prefix('email-verification')->controller(AccountController::class)->group(function () {
                Route::post('verify-otp', 'verifyOtp')->middleware('throttle:verification-otp');
                Route::post('resend-otp', 'resendOtp')->middleware('throttle:verification-otp');
            });

            Route::post('forgot-password', [AccountController::class, 'forgot'])
                ->middleware(ThrottlePasswordReset::class);
            Route::post('reset-password', [AccountController::class, 'reset'])
                ->middleware(ThrottlePasswordReset::class);

            Route::prefix('password-reset')->controller(AccountController::class)->group(function () {
                Route::post('resend-otp', 'forgot')->middleware(ThrottlePasswordReset::class);
                Route::post('verify-otp', 'verifyPasswordResetOtp')->middleware(ThrottlePasswordReset::class);
            });
        });

        Route::get('email/verify/{id}/{hash}', [AccountController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::middleware(AuthenticateApiToken::class)->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('email/verification-notification', [AccountController::class, 'resend'])
                ->middleware('throttle:6,1');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Profile & Avatar
    |--------------------------------------------------------------------------
    */
    Route::prefix('profile')->middleware(AuthenticateApiToken::class)->group(function () {
        Route::patch('/', [AccountController::class, 'update']);
        Route::post('close', [AccountController::class, 'close'])->middleware('throttle:6,1');
        Route::get('statistics', [VisitController::class, 'statistics']);

        Route::prefix('avatar')->controller(AvatarController::class)->group(function () {
            Route::get('/', 'show')->name('profile.avatar');
            Route::post('/', 'upload')->middleware('throttle:20,1');
            Route::put('preference', 'preference')->middleware('throttle:20,1');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Destination Categories
    |--------------------------------------------------------------------------
    */
    Route::prefix('categories')->controller(CategoryController::class)->group(function () {
        Route::get('/', 'index')->name('categories.index');

        Route::middleware(AuthenticateApiToken::class)->group(function () {
            Route::post('/', 'store')->name('categories.store');
            Route::patch('{category}', 'update')->name('categories.update');
            Route::delete('{category}', 'destroy')->name('categories.destroy');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Destination Catalog, Gallery & Quotas
    |--------------------------------------------------------------------------
    */
    Route::prefix('destinations')->group(function () {
        Route::controller(DestinationController::class)->group(function () {
            Route::get('/', 'index')->name('destinations.index');
            Route::get('{destination}', 'show')->name('destinations.show');
            Route::get('{destination}/image', 'image')->name('destinations.image');
            Route::get('{destination}/images/{image}/{variant}', 'galleryImage')
                ->whereIn('variant', ['thumbnail', 'display', 'fullscreen'])
                ->name('destinations.gallery.image');
        });

        Route::get('{destination}/reviews', [VisitController::class, 'destinationReviews'])
            ->name('destinations.reviews');

        Route::middleware(AuthenticateApiToken::class)->group(function () {
            Route::controller(DestinationController::class)->group(function () {
                Route::post('/', 'store')->middleware('throttle:20,1')->name('destinations.store');
                Route::patch('{destination}', 'update')->middleware('throttle:20,1')->name('destinations.update');
                Route::delete('{destination}', 'destroy')->name('destinations.destroy');
                Route::post('{destination}/image', 'updateImage')
                    ->middleware('throttle:20,1')->name('destinations.image.update');
            });

            Route::prefix('{destination}')->controller(QuotaController::class)->group(function () {
                Route::get('availability', 'availability')->name('destinations.availability');
                Route::get('quotas', 'index');
                Route::put('quotas', 'upsert');
            });
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Facilities (Authenticated Catalog)
    |--------------------------------------------------------------------------
    */
    Route::prefix('facilities')
        ->middleware(AuthenticateApiToken::class)
        ->controller(FacilityController::class)
        ->group(function () {
            Route::get('/', 'index')->name('facilities.index');
        });

    /*
    |--------------------------------------------------------------------------
    | Favorites
    |--------------------------------------------------------------------------
    */
    Route::prefix('favorites')
        ->middleware(AuthenticateApiToken::class)
        ->controller(FavoriteController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::put('{destination}', 'store');
            Route::delete('{destination}', 'destroy');
        });

    /*
    |--------------------------------------------------------------------------
    | Booking Intents & Idempotency
    |--------------------------------------------------------------------------
    */
    Route::prefix('booking-intents')
        ->middleware(AuthenticateApiToken::class)
        ->controller(BookingController::class)
        ->group(function () {
            Route::get('{key}', 'intent');
            Route::post('{key}/abandon', 'abandonIntent');
        });

    /*
    |--------------------------------------------------------------------------
    | Bookings, Private Documents, Check-in & Reviews
    |--------------------------------------------------------------------------
    */
    Route::prefix('bookings')->middleware(AuthenticateApiToken::class)->group(function () {
        Route::controller(BookingController::class)->group(function () {
            Route::get('/', 'index')->name('bookings.index');
            Route::post('/', 'store');
            Route::get('{booking}', 'show');
            Route::post('{booking}/cancel', 'cancel');
        });

        Route::controller(BookingRefundController::class)->prefix('{booking}/refund-requests')->group(function () {
            Route::get('/', 'index')->name('refunds.index');
            Route::post('/', 'store')->middleware('throttle:10,1')->name('refunds.store');
        });

        Route::prefix('{booking}/documents')
            ->controller(BookingDocumentController::class)
            ->group(function () {
                Route::get('/', 'show');
                Route::get('{type}', 'download')->whereIn('type', ['invoice', 'ticket', 'receipt']);
            });

        // Private per-unit tickets; legacy check-in remains explicitly disabled.
        Route::get('{booking}/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('{booking}/tickets/{ticket}/qr', [TicketController::class, 'qr'])->name('tickets.qr');

        Route::post('{booking}/check-in', [VisitController::class, 'checkIn']);
        Route::post('{booking}/review', [VisitController::class, 'review']);
    });

    /*
    |--------------------------------------------------------------------------
    | Review History
    |--------------------------------------------------------------------------
    */
    Route::prefix('reviews')
        ->middleware(AuthenticateApiToken::class)
        ->controller(VisitController::class)
        ->group(function () {
            Route::get('/', 'history');
        });

    /*
    |--------------------------------------------------------------------------
    | Notifications & FCM Devices
    |--------------------------------------------------------------------------
    */
    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::prefix('notifications')->controller(InboxController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('unread-count', 'unread');
            Route::patch('{guid}/read', 'read');
        });

        Route::prefix('devices')->controller(InboxController::class)->group(function () {
            Route::put('/', 'register');
            Route::delete('/', 'unregister');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Admin â€” Accounts, Catalog, Facilities & Booking Approvals
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware(AuthenticateApiToken::class)->group(function () {
        // Online staff scanner: preview never commits; confirm requires Idempotency-Key.
        Route::prefix('ticket-check-ins')->controller(TicketCheckInController::class)->group(function () {
            Route::get('scope', 'scope');
            Route::post('preview', 'preview')->middleware('throttle:ticket-scans')->name('admin.ticket-check-ins.preview');
            Route::post('confirm', 'confirm')->middleware('throttle:ticket-scans')->name('admin.ticket-check-ins.confirm');
        });

        Route::get('geocoding/reverse', LocationGeocodingController::class)
            ->middleware('throttle:30,1')->name('admin.geocoding.reverse');

        Route::prefix('accounts')->controller(AdminAccountController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::patch('{user}', 'update');
        });

        Route::prefix('categories')->controller(CategoryController::class)->group(function () {
            Route::get('/', 'adminIndex')->name('admin.categories.index');
        });

        Route::prefix('destinations')->group(function () {
            Route::controller(DestinationController::class)->group(function () {
                Route::get('/', 'adminIndex')->name('admin.destinations.index');
                Route::get('{destination}', 'adminShow')->name('admin.destinations.show');
                Route::get('{destination}/image', 'image')->name('destinations.image.admin');
                Route::get('{destination}/images/{image}/{variant}', 'galleryImage')
                    ->whereIn('variant', ['thumbnail', 'display', 'fullscreen'])
                    ->name('admin.destinations.gallery.image');
            });

            // Assignment management is authorized for Superadmin in request/service.
            Route::get('{destination}/admins', [TicketCheckInController::class, 'admins']);
            Route::put('{destination}/admins', [TicketCheckInController::class, 'assign']);

            Route::put('{destination}/facilities', [FacilityController::class, 'assign'])
                ->name('admin.destinations.facilities');
        });

        Route::prefix('bookings')->controller(BookingController::class)->group(function () {
            Route::get('/', 'adminIndex')->name('admin.bookings.index');
            Route::get('{booking}', 'adminShow')->name('admin.bookings.show');
            Route::post('{booking}/approve', 'approve');
            Route::post('{booking}/reject', 'reject');
        });

        // Authorized recovery of payments, unknown Charge outcomes and refunds the engine can no longer move. Scope and role are
        // enforced in the service; evidence is verified by the server, never taken from the payload.
        Route::controller(PaymentRecoveryController::class)->group(function () {
            Route::get('payment-recovery', 'queue')->name('admin.recovery.queue');
            Route::get('bookings/{booking}/payment-recovery', 'show')->name('admin.recovery.show');
            Route::post('bookings/{booking}/payment-recovery', 'payment')->middleware('throttle:30,1')->name('admin.recovery.payment');
            Route::post('bookings/{booking}/refund-requests/{refund}/recovery', 'refund')->whereUuid('refund')->middleware('throttle:30,1')->name('admin.recovery.refund');
        });

        Route::controller(BookingRefundController::class)->prefix('bookings/{booking}/refund-requests')->group(function () {
            Route::get('/', 'index')->name('admin.refunds.index');
            Route::post('{refund}/decision', 'decide')->whereUuid('refund')->middleware('throttle:30,1')->name('admin.refunds.decision');
            Route::post('{refund}/manual-settlement', 'settleManually')->whereUuid('refund')->middleware('throttle:30,1')->name('admin.refunds.settlement');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Superadmin â€” Facility Master Data
    |--------------------------------------------------------------------------
    */
    Route::prefix('superadmin')
        ->middleware(AuthenticateApiToken::class)
        ->group(function () {
            Route::prefix('facilities')->controller(FacilityController::class)->group(function () {
                Route::get('/', 'index')->name('superadmin.facilities.index');
                Route::post('/', 'store')->name('superadmin.facilities.store');
                Route::patch('{facility}', 'update')->name('superadmin.facilities.update');
                Route::delete('{facility}', 'destroy')->name('superadmin.facilities.destroy');
            });
        });
});

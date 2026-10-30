<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminAccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingDocumentController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\QuotaController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/google', [AuthController::class, 'google']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/forgot-password', [AccountController::class, 'forgot']);
        Route::post('auth/reset-password', [AccountController::class, 'reset']);
    });
    Route::get('auth/email/verify/{id}/{hash}', [AccountController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('destinations', [DestinationController::class, 'index'])->name('destinations.index');
    Route::get('destinations/{destination}', [DestinationController::class, 'show'])->name('destinations.show');
    Route::get('destinations/{destination}/image', [DestinationController::class, 'image'])->name('destinations.image');
    Route::middleware(AuthenticateApiToken::class)->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('profile/close', [AccountController::class, 'close'])->middleware('throttle:6,1');
        Route::patch('profile', [AccountController::class, 'update']);
        Route::post('auth/email/verification-notification', [AccountController::class, 'resend'])->middleware('throttle:6,1');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('destinations', [DestinationController::class, 'store'])->name('destinations.store');
        Route::patch('destinations/{destination}', [DestinationController::class, 'update'])->name('destinations.update');
        Route::delete('destinations/{destination}', [DestinationController::class, 'destroy'])->name('destinations.destroy');
        Route::get('admin/categories', [CategoryController::class, 'adminIndex'])->name('admin.categories.index');
        Route::get('admin/destinations', [DestinationController::class, 'adminIndex'])->name('admin.destinations.index');
        Route::get('admin/destinations/{destination}', [DestinationController::class, 'adminShow'])->name('admin.destinations.show');
        Route::get('admin/destinations/{destination}/image', [DestinationController::class, 'image'])->name('destinations.image.admin');
        Route::post('destinations/{destination}/image', [DestinationController::class, 'updateImage'])->name('destinations.image.update');
        Route::get('destinations/{destination}/availability', [QuotaController::class, 'availability'])->name('destinations.availability');
        Route::get('destinations/{destination}/quotas', [QuotaController::class, 'index']);
        Route::put('destinations/{destination}/quotas', [QuotaController::class, 'upsert']);
        Route::get('favorites', [FavoriteController::class, 'index']);
        Route::put('favorites/{destination}', [FavoriteController::class, 'store']);
        Route::delete('favorites/{destination}', [FavoriteController::class, 'destroy']);
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('booking-intents/{key}', [BookingController::class, 'intent']);
        Route::post('booking-intents/{key}/abandon', [BookingController::class, 'abandonIntent']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::get('bookings/{booking}/documents', [BookingDocumentController::class, 'show']);
        Route::get('bookings/{booking}/documents/{type}', [BookingDocumentController::class, 'download'])->whereIn('type', ['invoice', 'ticket']);
        Route::get('bookings/{booking}', [BookingController::class, 'show']);
        Route::get('admin/accounts', [AdminAccountController::class, 'index']);
        Route::post('admin/accounts', [AdminAccountController::class, 'store']);
        Route::patch('admin/accounts/{user}', [AdminAccountController::class, 'update']);
        Route::get('admin/bookings', [BookingController::class, 'adminIndex'])->name('admin.bookings.index');
        Route::get('admin/bookings/{booking}', [BookingController::class, 'adminShow'])->name('admin.bookings.show');
        Route::post('admin/bookings/{booking}/approve', [BookingController::class, 'approve']);
        Route::post('admin/bookings/{booking}/reject', [BookingController::class, 'reject']);
        Route::get('notifications', [InboxController::class, 'index']);
        Route::get('notifications/unread-count', [InboxController::class, 'unread']);
        Route::patch('notifications/{guid}/read', [InboxController::class, 'read']);
        Route::put('devices', [InboxController::class, 'register']);
        Route::delete('devices', [InboxController::class, 'unregister']);
    });
});

<?php

use App\Http\Controllers\AppLinkController;
use App\Http\Controllers\PaymentReturnController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

// Stateless public pages: no session, cookies or CSRF, so a wallet redirect never creates server state.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->group(function () {
        Route::get('/payment/return', PaymentReturnController::class)->middleware('throttle:120,1')->name('payment.return');
        Route::get('/.well-known/assetlinks.json', AppLinkController::class)->name('app-links.assetlinks');
    });

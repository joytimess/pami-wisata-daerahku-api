<?php

use App\Http\Controllers\AppLinkController;
use App\Http\Controllers\TicketLinkController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

// Stateless public pages: no session, cookies or CSRF, so an app link never creates server state.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->group(function () {
        Route::get('/tickets/{booking}', TicketLinkController::class)->whereUuid('booking')->middleware('throttle:120,1')->name('tickets.link');
        Route::get('/.well-known/assetlinks.json', AppLinkController::class)->name('app-links.assetlinks');
    });

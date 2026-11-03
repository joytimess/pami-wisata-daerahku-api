<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire')->everyMinute();
Schedule::command('notifications:dispatch')->everyMinute();
Schedule::command('documents:dispatch')->everyMinute();

Schedule::command('destinations:cleanup-images')->everyMinute()->withoutOverlapping();
Schedule::command('avatars:cleanup')->everyMinute()->withoutOverlapping();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('library:sync')->hourly()->withoutOverlapping(60);
Schedule::command('wishlist:process')->hourly()->withoutOverlapping(60);
Schedule::command('downloads:sync')->everyMinute()->withoutOverlapping(5);

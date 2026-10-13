<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:process')->everyMinute()->withoutOverlapping();
Schedule::command('billing:process --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fiscal:process')->everyMinute()->withoutOverlapping();
Schedule::command('fiscal:backup')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('sat:catalogs:check')->dailyAt('04:00')->withoutOverlapping();

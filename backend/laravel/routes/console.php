<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('slots:expire-prepares')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('slots:import-pumpfun')->hourly()->withoutOverlapping();
Schedule::command('crm:sync')->daily()->withoutOverlapping();
// ~87 chunked eth_getLogs calls per run (1000-block node cap) — keep the
// interval well above the runtime and never overlap.
Schedule::command('dex:apr')->everyFifteenMinutes()->withoutOverlapping();

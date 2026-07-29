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
// Credit XP for on-chain activity the browser can't be trusted to report.
// Idempotent, and scoped to recently-active accounts, so a short interval
// keeps levels/quests feeling live without rescanning the whole user base.
Schedule::command('gamification:sync')->everyTenMinutes()->withoutOverlapping();
// Harvest satellite-chain emission channels and top up their FundedFarms with
// backed bridged ASH (unified ASH emission, Path A). Buffers days ahead, so
// hourly keeps balances and reward rates in sync without racing.
Schedule::command('farm:fund-satellites')->hourly()->withoutOverlapping();

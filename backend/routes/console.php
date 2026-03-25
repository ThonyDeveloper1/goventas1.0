<?php

use App\Jobs\CorteCronJob;
use App\Jobs\SyncAllMorososJob;
use App\Jobs\SyncMikrotikStatusJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes — GO Systems & Technology
|--------------------------------------------------------------------------
*/

Schedule::job(new SyncMikrotikStatusJob)
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Sync morosos / IP / estado from MikroTik every 2 minutes
Schedule::job(new SyncAllMorososJob)
    ->everyTwoMinutes()
    ->withoutOverlapping();

// Daily auto-cutoff at 2:00 AM (America/Lima) — suspend overdue clients
Schedule::job(new CorteCronJob)
    ->dailyAt('02:00')
    ->timezone('America/Lima')
    ->withoutOverlapping();

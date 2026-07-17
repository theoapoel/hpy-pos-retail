<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Auto-pull level stok dari ERP HPY tiap jam (gate via setting stock_auto_sync)
Schedule::command('stock:sync-erp')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

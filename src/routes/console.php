<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deployment opt-in is approval for this bounded expired-response maintenance policy, not a database reset.
if (config('platform_api.prune_scheduled') === true) {
    \Illuminate\Support\Facades\Schedule::command('platform:prune-idempotencies --apply --scheduled --no-interaction --batch-size=100 --max-batches=10')
        ->hourly();
}

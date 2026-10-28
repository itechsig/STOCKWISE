<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Important Request: deadline in 24 h / overdue — each told once (also runs when the dashboard is opened)
Artisan::command('important-requests:remind', function () {
    $this->info(app(\App\Services\ImportantRequest\ImportantRequestService::class)->remind().' pengingat dikirim.');
})->purpose('Kirim pengingat deadline Important Request');

<?php

use App\Console\Commands\SearchReindex;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::starting(function ($artisan) {
    $artisan->resolveCommands([
        SearchReindex::class,
    ]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

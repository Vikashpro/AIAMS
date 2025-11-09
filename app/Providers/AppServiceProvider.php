<?php

namespace App\Providers;

use App\Policies\NotificationPolicy;
use Gate;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\ServiceProvider;
use Smalot\PdfParser\Parser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Parser::class, static fn (): Parser => new Parser());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(
            DatabaseNotification::class,
            NotificationPolicy::class
        );
    }
}

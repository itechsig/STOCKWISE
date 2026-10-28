<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // whereLoose / orWhereLoose: "O" finds "Ø", a normal space finds a no-break space.
        \App\Support\LooseSearch::register();
    }
}

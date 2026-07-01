<?php

namespace App\Providers;

use App\Models\ProductBase;
use App\Observers\ProductBaseObserver;
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
        ProductBase::observe(ProductBaseObserver::class);
    }
}

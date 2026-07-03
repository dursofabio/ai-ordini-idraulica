<?php

namespace App\Providers;

use App\Models\ProductBase;
use App\Observers\ProductBaseObserver;
use App\Services\Ai\AiClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiClient::class, fn (): AiClient => match (config('services.ai_provider')) {
            'openrouter' => new OpenRouterClient,
            default => new ClaudeClient,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductBase::observe(ProductBaseObserver::class);
    }
}

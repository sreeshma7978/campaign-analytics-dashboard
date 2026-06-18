<?php

namespace App\Providers;

use App\Contracts\CampaignAnalyticsService;
use App\Contracts\CampaignService;
use App\Services\DatabaseCampaignAnalyticsService;
use App\Services\DatabaseCampaignService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CampaignAnalyticsService::class, DatabaseCampaignAnalyticsService::class);
        $this->app->bind(CampaignService::class, DatabaseCampaignService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('analytics-ingest', fn (Request $request) => Limit::perMinute(1500000)->by($request->ip()));
        RateLimiter::for('analytics-dashboard', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        Vite::prefetch(concurrency: 3);
    }
}

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
        $this->app->bind(\App\Services\Channels\TelegramAdapter::class, function () {
            return new \App\Services\Channels\TelegramAdapter(config('services.telegram.bot_token'));
        });

        $this->app->bind(\App\Services\AI\ClaudeAdapter::class, function () {
            return new \App\Services\AI\ClaudeAdapter(
                config('services.anthropic.api_key'),
                config('services.anthropic.model'),
            );
        });

        $this->app->bind(\App\Services\AI\JevAdapter::class, function () {
            return new \App\Services\AI\JevAdapter(config('services.typesafe.api_key'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

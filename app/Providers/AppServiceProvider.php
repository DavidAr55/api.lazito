<?php

namespace App\Providers;

use App\Models\Group;
use App\Services\ProductSearch\FakeProductSearchProvider;
use App\Services\ProductSearch\ProductSearchBudget;
use App\Services\ProductSearch\ProductSearchProvider;
use App\Services\ProductSearch\SerperProductSearchProvider;
use App\Services\ProductSearch\UnavailableProductSearchProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductSearchProvider::class, fn ($app): ProductSearchProvider => match (true) {
            config('services.product_search.driver') === 'serper' && filled(config('services.product_search.serper.key')) => new SerperProductSearchProvider(
                config('services.product_search.serper.key'),
                new ProductSearchBudget('serper', config('services.product_search.credit_limit')),
            ),
            config('services.product_search.driver') === 'fake' && $app->environment(['local', 'testing']) => new FakeProductSearchProvider,
            default => new UnavailableProductSearchProvider,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit binding: middleware also need the resolved group, and controllers like MeController don't type-hint it.
        Route::bind('group', fn (string $slug): Group => Group::query()->where('slug', $slug)->firstOrFail());

        // Searches may call a paid provider, so they are limited per session.
        RateLimiter::for('product-search', fn (Request $request): Limit => Limit::perMinute(30)->by(sha1($request->bearerToken().'|'.$request->ip())));

        RateLimiter::for('groups', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        // A 4-digit PIN is guessable, so attempts are limited per participant and client.
        RateLimiter::for('pin', function (Request $request): Limit {
            $participant = $request->route('participant');
            $participantKey = $participant instanceof Model ? $participant->getKey() : $participant;

            return Limit::perMinute(5)->by($participantKey.'|'.$request->ip());
        });
    }
}

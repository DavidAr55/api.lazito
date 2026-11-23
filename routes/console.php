<?php

use App\Models\ProductSearchLog;
use App\Services\ProductSearch\ProductSearchBudget;
use App\Services\ProductSearch\SerperProductSearchProvider;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('product-search:usage {--recent=10 : How many recent searches to list}', function (): int {
    $driver = (string) config('services.product_search.driver');
    $budget = new ProductSearchBudget($driver, (int) config('services.product_search.credit_limit'));

    $this->info("Product search provider: {$driver}");
    $this->line("Credits spent by this app: {$budget->spent()} of {$budget->limit()} (limit). Remaining before the limit: {$budget->remaining()}");

    $key = config('services.product_search.serper.key');
    if ($driver === 'serper' && filled($key)) {
        $balance = SerperProductSearchProvider::accountBalance($key);
        $this->line($balance === null ? 'serper.dev balance: could not be read.' : "serper.dev balance (real, all uses): {$balance} credits");
    }

    $recent = ProductSearchLog::query()->where('provider', $driver)->latest('id')->limit((int) $this->option('recent'))->get();
    $this->newLine();
    $this->table(
        ['When', 'Search', 'Credits', 'Results'],
        $recent->map(fn (ProductSearchLog $log): array => [$log->created_at->toDateTimeString(), $log->query, $log->credits, $log->result_count])->all(),
    );

    return self::SUCCESS;
})->purpose('Show how many product search credits have been spent and how many remain');

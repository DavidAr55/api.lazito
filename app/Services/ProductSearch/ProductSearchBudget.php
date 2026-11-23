<?php

namespace App\Services\ProductSearch;

use App\Models\ProductSearchLog;

/**
 * Hard cap on the credits this app may spend with a paid provider, so a free trial is never exceeded.
 */
class ProductSearchBudget
{
    public function __construct(
        private readonly string $provider,
        private readonly int $limit,
    ) {}

    public function limit(): int
    {
        return $this->limit;
    }

    public function spent(): int
    {
        return (int) ProductSearchLog::query()->where('provider', $this->provider)->sum('credits');
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->spent());
    }

    /**
     * @throws ProductSearchUnavailable when the credit limit has been reached
     */
    public function ensureAvailable(): void
    {
        if ($this->remaining() === 0) {
            throw new ProductSearchUnavailable('The product search credit limit has been reached.');
        }
    }

    public function record(string $query, int $credits, int $resultCount): void
    {
        ProductSearchLog::query()->create([
            'provider' => $this->provider,
            'query' => mb_substr(mb_strtolower($query), 0, 80),
            'credits' => $credits,
            'result_count' => $resultCount,
        ]);
    }
}

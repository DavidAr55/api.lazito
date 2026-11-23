<?php

namespace App\Services\ProductSearch;

/**
 * Used when no real provider is configured: searching fails clearly instead of returning made-up products.
 */
class UnavailableProductSearchProvider implements ProductSearchProvider
{
    public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
    {
        throw new ProductSearchUnavailable('No product search provider is configured.');
    }
}

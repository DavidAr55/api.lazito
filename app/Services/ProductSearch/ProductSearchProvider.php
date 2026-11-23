<?php

namespace App\Services\ProductSearch;

interface ProductSearchProvider
{
    /**
     * Finds products for a free-text query, optionally capped at a price in whole pesos.
     * `$page` starts at 1; each provider page holds up to `$limit` products.
     *
     * @return list<Product>
     *
     * @throws ProductSearchUnavailable when the provider cannot answer
     */
    public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array;
}

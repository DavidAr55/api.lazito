<?php

namespace App\Services\ProductSearch;

/**
 * A product found by a search provider, normalized so the rest of the app does not depend on the provider.
 */
final readonly class Product
{
    /**
     * @param  int  $price  whole pesos
     */
    public function __construct(
        public string $title,
        public int $price,
        public ?string $imageUrl,
        public string $url,
        public string $store,
    ) {}
}

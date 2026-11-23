<?php

namespace App\Services\ProductSearch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Shopping results for Mexico through serper.dev. Every call spends credits, which are recorded and capped.
 */
class SerperProductSearchProvider implements ProductSearchProvider
{
    private const SEARCH_URL = 'https://google.serper.dev/shopping';

    private const ACCOUNT_URL = 'https://google.serper.dev/account';

    /** Credits a Shopping search costs when the response does not say. */
    private const DEFAULT_CREDITS = 2;

    private const MAX_URL_LENGTH = 500;

    public function __construct(
        private readonly string $apiKey,
        private readonly ProductSearchBudget $budget,
    ) {}

    public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
    {
        $this->budget->ensureAvailable();

        try {
            $response = Http::withHeaders(['X-API-KEY' => $this->apiKey])
                ->acceptJson()
                ->timeout(10)
                ->post(self::SEARCH_URL, ['q' => $query, 'gl' => 'mx', 'hl' => 'es-419'] + ($page > 1 ? ['page' => $page] : []));
        } catch (ConnectionException) {
            throw new ProductSearchUnavailable('Could not reach the product search service.');
        }

        if ($response->failed()) {
            Log::warning('Product search request failed.', ['provider' => 'serper', 'status' => $response->status()]);

            throw new ProductSearchUnavailable('The product search service returned an error.');
        }

        $products = $this->toProducts((array) $response->json('shopping', []), $maxPrice);

        $this->budget->record($query.($page > 1 ? " (página {$page})" : ''), (int) $response->json('credits', self::DEFAULT_CREDITS), count($products));

        return array_slice($products, 0, $limit);
    }

    /**
     * Credits left in the serper.dev account, or null when it cannot be read. Checking it costs nothing.
     */
    public static function accountBalance(string $apiKey): ?int
    {
        try {
            $response = Http::withHeaders(['X-API-KEY' => $apiKey])->acceptJson()->timeout(10)->get(self::ACCOUNT_URL);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() && is_numeric($response->json('balance')) ? (int) $response->json('balance') : null;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<Product>
     */
    private function toProducts(array $items, ?int $maxPrice): array
    {
        $products = [];
        $seen = [];

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $price = self::parsePrice($item['price'] ?? null);
            $url = $this->productUrl($item, $title);

            if ($title === '' || $price === null || ($maxPrice !== null && $price > $maxPrice)) {
                continue;
            }

            if (isset($seen[$title.'|'.$price])) {
                continue;
            }
            $seen[$title.'|'.$price] = true;

            $image = (string) ($item['imageUrl'] ?? '');

            $products[] = new Product(
                title: $title,
                price: $price,
                imageUrl: self::isWebUrl($image) ? $image : null,
                url: $url,
                store: trim((string) ($item['source'] ?? '')) ?: 'Google Shopping',
            );
        }

        return $products;
    }

    /**
     * The result's own Google link, or a Google Shopping search for the product's name when it has none.
     * Serper never returns a direct store link, and its "productId" does not open a Google product page.
     *
     * @param  array<string, mixed>  $item
     */
    private function productUrl(array $item, string $title): string
    {
        $link = (string) ($item['link'] ?? '');

        return self::isWebUrl($link) ? $link : self::shoppingSearchUrl($title);
    }

    /**
     * Google Shopping results for a product name (`udm=28` is Google's Shopping view).
     */
    public static function shoppingSearchUrl(string $title): string
    {
        $words = mb_substr($title, 0, 100);

        do {
            $url = 'https://www.google.com/search?'.http_build_query(['q' => $words, 'udm' => 28, 'gl' => 'mx', 'hl' => 'es']);
            $words = mb_substr($words, 0, max(1, mb_strlen($words) - 10));
        } while (strlen($url) > self::MAX_URL_LENGTH && mb_strlen($words) > 1);

        return $url;
    }

    /**
     * Whole pesos from texts such as "MXN 3,932.95" or "MXN 429.00 ahora"; null when there is no usable price.
     */
    public static function parsePrice(mixed $text): ?int
    {
        if (! is_string($text) || preg_match('/\d[\d,]*(?:\.\d+)?/', $text, $match) !== 1) {
            return null;
        }

        $pesos = (int) round((float) str_replace(',', '', $match[0]));

        return $pesos > 0 ? $pesos : null;
    }

    private static function isWebUrl(string $url): bool
    {
        return strlen($url) <= self::MAX_URL_LENGTH && preg_match('#^https?://#i', $url) === 1;
    }
}

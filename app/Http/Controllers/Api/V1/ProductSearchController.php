<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductSearchRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\ProductSearch\Product;
use App\Services\ProductSearch\ProductSearchProvider;
use App\Services\ProductSearch\ProductSearchUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class ProductSearchController extends Controller
{
    /** Most products one response returns, after the price filter. */
    private const RESULT_LIMIT = 40;

    /** Products one provider page holds. */
    private const PROVIDER_LIMIT = 40;

    /** Pages per search; each one is a paid provider call, so this caps what one search can cost. */
    public const MAX_PAGE = 5;

    private const CACHE_SECONDS = 600;

    /**
     * Who the gift is for, and the words added to the search so Google Shopping narrows the results.
     *
     * @var array<string, string>
     */
    public const AUDIENCES = ['hombre' => 'hombre', 'mujer' => 'mujer', 'ninos' => 'niños'];

    /** A provider page with fewer products than this is taken to be the last one. */
    private const FULL_PAGE_MINIMUM = 10;

    /**
     * Searches products for the wishlist form. Each provider page is cached briefly so repeating it costs nothing.
     */
    public function index(ProductSearchRequest $request, ProductSearchProvider $provider): AnonymousResourceCollection|JsonResponse
    {
        $query = $request->validated('q');
        $minPrice = $request->validated('min_price');
        $maxPrice = $request->validated('max_price');
        $audience = $request->validated('audience');
        $search = $audience === null ? $query : $query.' para '.self::AUDIENCES[$audience];
        $page = (int) ($request->validated('page') ?? 1);

        try {
            // Cached per search (with its audience) and page, not per price range. Plain arrays, not Product objects: serializing stores refuse objects.
            $cached = Cache::remember(
                'product-search:'.sha1(mb_strtolower($search)).':'.$page,
                self::CACHE_SECONDS,
                fn (): array => array_map(
                    fn (Product $product): array => get_object_vars($product),
                    $provider->search($search, null, self::PROVIDER_LIMIT, $page),
                ),
            );
        } catch (ProductSearchUnavailable) {
            return response()->json([
                'message' => 'El buscador no está disponible por ahora. Agrega el producto a mano.',
                'code' => 'SEARCH_UNAVAILABLE',
            ], 503);
        }

        // The price range is applied here, on cached results, so changing it never costs another provider call.
        $products = collect($cached)
            ->map(fn (array $attributes): Product => new Product(...$attributes))
            ->when($minPrice !== null, fn ($products) => $products->filter(fn (Product $product): bool => $product->price >= $minPrice))
            ->when($maxPrice !== null, fn ($products) => $products->filter(fn (Product $product): bool => $product->price <= $maxPrice))
            ->take(self::RESULT_LIMIT)
            ->values();

        return ProductResource::collection($products)->additional([
            'meta' => [
                'page' => $page,
                'has_more' => $page < self::MAX_PAGE && count($cached) >= self::FULL_PAGE_MINIMUM,
            ],
        ]);
    }
}

<?php

use App\Models\Group;
use App\Models\Participant;
use App\Services\ProductSearch\Product;
use App\Services\ProductSearch\ProductSearchProvider;
use App\Services\ProductSearch\ProductSearchUnavailable;
use App\Services\ProductSearch\UnavailableProductSearchProvider;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->group = Group::factory()->create();
    Participant::factory()->for($this->group)->claimed('1234', 'my-token')->create();
    $this->url = "/api/v1/groups/{$this->group->slug}/me/products";
});

describe('searching products', function () {
    it('returns products that match the words, ignoring accents and case', function (string $query) {
        $titles = collect($this->withToken('my-token')->getJson($this->url.'?q='.urlencode($query))
            ->assertOk()
            ->assertJsonStructure(['data' => [['title', 'price', 'image_url', 'url', 'store']]])
            ->json('data'))->pluck('title');

        expect($titles)->toContain('Pijama de franela dos piezas')
            ->and($titles)->not->toContain('Taza de cerámica con diseño artesanal');
    })->with(['plain' => 'pijama', 'uppercase' => 'PIJAMA', 'with accent' => 'pijamá', 'plural' => 'pijamas']);

    it('never returns products above the maximum price', function () {
        $prices = collect($this->withToken('my-token')->getJson($this->url.'?q=pijama&max_price=300')
            ->assertOk()
            ->json('data'))->pluck('price');

        expect($prices)->not->toBeEmpty()
            ->and($prices->max())->toBeLessThanOrEqual(300);
    });

    it('requires every word to match', function () {
        $this->withToken('my-token')->getJson($this->url.'?q='.urlencode('pijama dinosaurio'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken('my-token')->getJson($this->url.'?q=zzzxyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('caps the number of results of one response', function () {
        $count = count($this->withToken('my-token')->getJson($this->url.'?q=de')->assertOk()->json('data'));

        expect($count)->toBeLessThanOrEqual(40);
    });

    it('rejects invalid searches', function (string $queryString, string $field) {
        $this->withToken('my-token')->getJson($this->url.$queryString)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'missing query' => ['', 'q'],
        'one letter' => ['?q=a', 'q'],
        'too long' => ['?q='.str_repeat('a', 81), 'q'],
        'price that is not a number' => ['?q=pijama&max_price=barato', 'max_price'],
        'zero price' => ['?q=pijama&max_price=0', 'max_price'],
        'negative minimum price' => ['?q=pijama&min_price=-5', 'min_price'],
        'minimum above maximum' => ['?q=pijama&min_price=500&max_price=300', 'min_price'],
        'unknown audience' => ['?q=pijama&audience=perros', 'audience'],
        'the display word instead of the key' => ['?q=pijama&audience=niños', 'audience'],
        'page zero' => ['?q=pijama&page=0', 'page'],
        'page beyond the limit' => ['?q=pijama&page=6', 'page'],
        'page that is not a number' => ['?q=pijama&page=siguiente', 'page'],
    ]);
});

describe('access', function () {
    it('requires a participant session', function () {
        $this->getJson($this->url.'?q=pijama')->assertUnauthorized();
        $this->withToken('nope')->getJson($this->url.'?q=pijama')->assertUnauthorized();
    });

    it('rejects a session from another group', function () {
        $other = Group::factory()->create();
        Participant::factory()->for($other)->claimed('1234', 'foreign-token')->create();

        $this->withToken('foreign-token')->getJson($this->url.'?q=pijama')->assertUnauthorized();
    });

    it('limits how many searches one session can make per minute', function () {
        foreach (range(1, 30) as $attempt) {
            $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();
        }

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertTooManyRequests();
    });
});

describe('when there is no real provider', function () {
    it('answers 503 instead of making up products', function () {
        $this->app->bind(ProductSearchProvider::class, UnavailableProductSearchProvider::class);

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'SEARCH_UNAVAILABLE');
    });

    it('does not use the sample catalog outside local and testing', function () {
        config(['services.product_search.driver' => 'fake']);
        $this->app['env'] = 'production';

        expect(app(ProductSearchProvider::class))->toBeInstanceOf(UnavailableProductSearchProvider::class)
            ->and(fn () => app(ProductSearchProvider::class)->search('pijama', null, 5))->toThrow(ProductSearchUnavailable::class);
    });

    it('does not use the sample catalog with an unknown driver', function () {
        config(['services.product_search.driver' => 'mercadolibre']);

        expect(app(ProductSearchProvider::class))->toBeInstanceOf(UnavailableProductSearchProvider::class);
    });
});

it('serves repeated searches from the cache', function () {
    $counter = new ArrayObject(['calls' => 0]);
    $this->app->bind(ProductSearchProvider::class, fn () => new class($counter) implements ProductSearchProvider
    {
        public function __construct(private ArrayObject $counter) {}

        public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
        {
            $this->counter['calls']++;

            return [];
        }
    });

    $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();
    $this->withToken('my-token')->getJson($this->url.'?q=PIJAMA')->assertOk();

    expect($counter['calls'])->toBe(1);
});

it('works with a cache store that serializes values', function () {
    config(['cache.default' => 'database']);

    foreach (range(1, 2) as $attempt) {
        $this->withToken('my-token')->getJson($this->url.'?q=pantuflas')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Pantuflas con suela antiderrapante')
            ->assertJsonPath('data.0.price', 159);
    }
});

describe('pages', function () {
    it('reports the page and whether there may be more', function () {
        $this->app->bind(ProductSearchProvider::class, fn () => new class implements ProductSearchProvider
        {
            public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
            {
                return array_map(
                    fn (int $index): Product => new Product("Producto {$page}-{$index}", 100 + $index, null, "https://tienda.example/{$page}-{$index}", 'Tienda'),
                    $page === 2 ? range(1, 3) : range(1, 12),
                );
            }
        });

        $first = $this->withToken('my-token')->getJson($this->url.'?q=algo')->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.has_more', true);
        $second = $this->withToken('my-token')->getJson($this->url.'?q=algo&page=2')->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.has_more', false);

        expect($first->json('data.0.title'))->toBe('Producto 1-1')
            ->and($second->json('data.0.title'))->toBe('Producto 2-1')
            ->and($second->json('data'))->toHaveCount(3);
    });

    it('says there is nothing more on the last allowed page', function () {
        $this->app->bind(ProductSearchProvider::class, fn () => new class implements ProductSearchProvider
        {
            public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
            {
                return array_map(fn (int $index): Product => new Product("P{$index}", 100, null, "https://tienda.example/{$page}-{$index}", 'Tienda'), range(1, 40));
            }
        });

        $this->withToken('my-token')->getJson($this->url.'?q=algo&page=5')->assertOk()->assertJsonPath('meta.has_more', false);
    });

    it('caches each page on its own', function () {
        $counter = new ArrayObject(['calls' => 0]);
        $this->app->bind(ProductSearchProvider::class, fn () => new class($counter) implements ProductSearchProvider
        {
            public function __construct(private ArrayObject $counter) {}

            public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
            {
                $this->counter['calls']++;

                return [];
            }
        });

        foreach ([1, 2, 1, 2, 2] as $page) {
            $this->withToken('my-token')->getJson($this->url.'?q=pijama&page='.$page)->assertOk();
        }

        expect($counter['calls'])->toBe(2);
    });

    it('filters by price on every page without extra calls', function () {
        $this->app->bind(ProductSearchProvider::class, fn () => new class implements ProductSearchProvider
        {
            public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
            {
                return array_map(fn (int $index): Product => new Product("P{$index}", $index * 100, null, "https://tienda.example/{$index}", 'Tienda'), range(1, 12));
            }
        });

        $prices = collect($this->withToken('my-token')->getJson($this->url.'?q=algo&max_price=500&page=2')->assertOk()->json('data'))->pluck('price');

        expect($prices->all())->toBe([100, 200, 300, 400, 500]);
    });
});

describe('filters', function () {
    function catalogOf(array $prices): ProductSearchProvider
    {
        return new class($prices) implements ProductSearchProvider
        {
            /** @var list<string> */
            public array $queries = [];

            public function __construct(private array $prices) {}

            public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
            {
                $this->queries[] = $query;

                return array_map(fn (int $price): Product => new Product("Producto {$price}", $price, null, "https://tienda.example/{$price}", 'Tienda'), $this->prices);
            }
        };
    }

    it('returns only the products inside the price range, edges included', function (string $queryString, array $expected) {
        $this->app->bind(ProductSearchProvider::class, fn () => catalogOf([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1200]));

        $prices = collect($this->withToken('my-token')->getJson($this->url.'?q=algo'.$queryString)->assertOk()->json('data'))->pluck('price');

        expect($prices->all())->toBe($expected);
    })->with([
        'only a maximum' => ['&max_price=200', [50, 100, 200]],
        'only a minimum' => ['&min_price=900', [900, 1000, 1200]],
        'both' => ['&min_price=200&max_price=500', [200, 300, 400, 500]],
        'a single price' => ['&min_price=300&max_price=300', [300]],
        'a range with nothing in it' => ['&min_price=1100&max_price=1150', []],
    ]);

    it('searches for the audience by adding it to the words, and caches it apart', function () {
        $provider = catalogOf([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000]);
        $this->app->bind(ProductSearchProvider::class, fn () => $provider);

        foreach (['', '&audience=mujer', '&audience=hombre', '&audience=ninos', '&audience=mujer'] as $queryString) {
            $this->withToken('my-token')->getJson($this->url.'?q=Pijama'.$queryString)->assertOk();
        }

        expect($provider->queries)->toBe(['Pijama', 'Pijama para mujer', 'Pijama para hombre', 'Pijama para niños']);
    });

    it('changing the price range never calls the provider again', function () {
        $provider = catalogOf([100, 200, 300, 400, 500, 600, 700, 800, 900, 1000]);
        $this->app->bind(ProductSearchProvider::class, fn () => $provider);

        foreach (['&max_price=300', '&min_price=500', '&min_price=200&max_price=800'] as $queryString) {
            $this->withToken('my-token')->getJson($this->url.'?q=algo&audience=mujer'.$queryString)->assertOk();
        }

        expect($provider->queries)->toHaveCount(1);
    });
});

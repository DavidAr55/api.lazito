<?php

use App\Models\Group;
use App\Models\Participant;
use App\Models\ProductSearchLog;
use App\Services\ProductSearch\ProductSearchProvider;
use App\Services\ProductSearch\SerperProductSearchProvider;
use App\Services\ProductSearch\UnavailableProductSearchProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A response shaped like the real one (captured from serper.dev for "pijama" in Mexico).
 *
 * @return array<string, mixed>
 */
function serperShoppingResponse(): array
{
    return [
        'searchParameters' => ['q' => 'pijama', 'gl' => 'mx', 'type' => 'shopping'],
        'shopping' => [
            ['title' => 'Pijama Hombre Algodón', 'source' => 'Amazon MX', 'price' => 'MXN 429.00 ahora', 'imageUrl' => 'https://img.example/a.jpg', 'productId' => '4250385773700323837', 'position' => 1],
            ['title' => 'Luxe Pijama de Seda', 'source' => 'ellesilk.com', 'link' => 'https://www.google.com/search?ibp=oshop&q=pijama', 'price' => 'MXN 3,932.95', 'imageUrl' => 'https://img.example/b.jpg', 'position' => 2],
            ['title' => 'Pijama Lovest para Mujer', 'source' => 'Coppel', 'price' => 'MXN 199.00', 'imageUrl' => 'javascript:alert(1)', 'productId' => '6033968244676534740', 'position' => 3],
            ['title' => 'Pijama sin precio', 'source' => 'Tienda', 'productId' => '6033968244676534741', 'position' => 4],
            ['title' => 'Pijama sin enlace ni id', 'source' => 'Tienda', 'price' => 'MXN 100.00', 'position' => 5],
            ['title' => 'Pijama Lovest para Mujer', 'source' => 'Otra tienda', 'price' => 'MXN 199.00', 'productId' => '6033968244676534742', 'position' => 6],
            ['title' => 'Pijama sin tienda', 'price' => 'MXN 250.00', 'productId' => '6033968244676534743', 'position' => 7],
        ],
        'credits' => 2,
    ];
}

beforeEach(function () {
    Cache::flush();
    config([
        'services.product_search.driver' => 'serper',
        'services.product_search.serper.key' => 'test-key',
        'services.product_search.credit_limit' => 2000,
    ]);

    $this->group = Group::factory()->create();
    Participant::factory()->for($this->group)->claimed('1234', 'my-token')->create();
    $this->url = "/api/v1/groups/{$this->group->slug}/me/products";
});

describe('results', function () {
    it('maps the response into products and skips the ones that cannot be used', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $products = collect($this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk()->json('data'));

        expect($products->pluck('title')->all())->toBe([
            'Pijama Hombre Algodón',
            'Luxe Pijama de Seda',
            'Pijama Lovest para Mujer',
            'Pijama sin enlace ni id',
            'Pijama sin tienda',
        ])
            ->and($products[0])->toMatchArray([
                'price' => 429,
                'image_url' => 'https://img.example/a.jpg',
                'url' => 'https://www.google.com/search?q=Pijama+Hombre+Algod%C3%B3n&udm=28&gl=mx&hl=es',
                'store' => 'Amazon MX',
            ])
            ->and($products[1]['price'])->toBe(3933)
            ->and($products[1]['url'])->toBe('https://www.google.com/search?ibp=oshop&q=pijama')
            ->and($products[2]['image_url'])->toBeNull()
            ->and($products[3]['url'])->toBe('https://www.google.com/search?q=Pijama+sin+enlace+ni+id&udm=28&gl=mx&hl=es')
            ->and($products[4]['store'])->toBe('Google Shopping');
    });

    it('asks serper for Mexico in Spanish with the key in a header', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://google.serper.dev/shopping'
            && $request['q'] === 'pijama'
            && $request['gl'] === 'mx'
            && $request['hl'] === 'es-419'
            && $request->hasHeader('X-API-KEY', 'test-key'));
    });

    it('applies the price filter after the search so it never costs more credits', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $cheap = $this->withToken('my-token')->getJson($this->url.'?q=pijama&max_price=300')->assertOk()->json('data');
        $all = $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk()->json('data');

        expect(collect($cheap)->pluck('price')->max())->toBeLessThanOrEqual(300)
            ->and(count($all))->toBeGreaterThan(count($cheap));

        Http::assertSentCount(1);
    });
});

describe('spending', function () {
    it('records the credits of every real call and nothing for cached ones', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $this->withToken('my-token')->getJson($this->url.'?q=Pijama')->assertOk();
        $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();

        expect(ProductSearchLog::query()->count())->toBe(1);
        $log = ProductSearchLog::query()->firstOrFail();
        expect($log->provider)->toBe('serper')
            ->and($log->query)->toBe('pijama')
            ->and($log->credits)->toBe(2)
            ->and($log->result_count)->toBe(5);
    });

    it('stops calling the provider once the credit limit is reached', function () {
        config(['services.product_search.credit_limit' => 10]);
        ProductSearchLog::factory()->create(['credits' => 10]);
        Http::fake();

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'SEARCH_UNAVAILABLE');

        Http::assertNothingSent();
    });

    it('does not count credits when the call fails', function (callable $fake) {
        Http::fake($fake());

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'SEARCH_UNAVAILABLE');

        expect(ProductSearchLog::query()->count())->toBe(0);
    })->with([
        'rejected key' => [fn () => ['google.serper.dev/*' => Http::response(['message' => 'Unauthorized'], 403)]],
        'out of credits' => [fn () => ['google.serper.dev/*' => Http::response(['message' => 'Not enough credits'], 400)]],
        'provider down' => [fn () => ['google.serper.dev/*' => Http::response('', 500)]],
        'no connection' => [fn () => fn () => throw new ConnectionException('timeout')],
    ]);

    it('counts only what the provider says a search cost', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response([...serperShoppingResponse(), 'credits' => 3])]);

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();

        expect(ProductSearchLog::query()->sum('credits'))->toBe(3);
    });
});

describe('configuration', function () {
    it('is unavailable when the serper driver has no key', function () {
        config(['services.product_search.serper.key' => null]);

        expect(app(ProductSearchProvider::class))->toBeInstanceOf(UnavailableProductSearchProvider::class);
    });

    it('never exposes the key in an error response', function () {
        Http::fake(['google.serper.dev/*' => Http::response('', 500)]);

        $body = $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertServiceUnavailable()->getContent();

        expect($body)->not->toContain('test-key');
    });

    it('reads prices in the formats Google uses', function (mixed $text, ?int $expected) {
        expect(SerperProductSearchProvider::parsePrice($text))->toBe($expected);
    })->with([
        ['MXN 429.00 ahora', 429],
        ['MXN 3,932.95', 3933],
        ['$1,299', 1299],
        ['MXN 199.50', 200],
        ['Gratis', null],
        ['MXN 0.00', null],
        [null, null],
        [199, null],
    ]);
});

describe('the usage command', function () {
    it('shows what was spent, what is left and the real balance', function () {
        ProductSearchLog::factory()->create(['query' => 'pantuflas', 'credits' => 2, 'result_count' => 12]);
        Http::fake(['google.serper.dev/account' => Http::response(['balance' => 2480, 'rateLimit' => 5])]);

        $this->artisan('product-search:usage')
            ->expectsOutputToContain('Credits spent by this app: 2 of 2000 (limit). Remaining before the limit: 1998')
            ->expectsOutputToContain('2480 credits')
            ->expectsOutputToContain('pantuflas')
            ->assertSuccessful();
    });

    it('says so when the balance cannot be read', function () {
        Http::fake(['google.serper.dev/account' => Http::response('', 500)]);

        $this->artisan('product-search:usage')
            ->expectsOutputToContain('could not be read')
            ->assertSuccessful();
    });
});

describe('pages', function () {
    it('asks serper for the requested page only after the first, and spends credits on each', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk();
        $this->withToken('my-token')->getJson($this->url.'?q=pijama&page=2')->assertOk();

        $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
        expect($sent)->toHaveCount(2)
            ->and($sent[0])->not->toHaveKey('page')
            ->and($sent[1]['page'])->toBe(2)
            ->and(ProductSearchLog::query()->count())->toBe(2)
            ->and(ProductSearchLog::query()->sum('credits'))->toBe(4)
            ->and(ProductSearchLog::query()->latest('id')->value('query'))->toBe('pijama (página 2)');
    });
});

describe('audience', function () {
    it('sends the search with the audience to serper and records it', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $this->withToken('my-token')->getJson($this->url.'?q=pijama&audience=mujer')->assertOk();

        Http::assertSent(fn ($request): bool => $request['q'] === 'pijama para mujer');
        expect(ProductSearchLog::query()->value('query'))->toBe('pijama para mujer');
    });
});

describe('product links', function () {
    it('never builds a link from the product id, which does not open a page', function () {
        Http::fake(['google.serper.dev/shopping' => Http::response(serperShoppingResponse())]);

        $urls = collect($this->withToken('my-token')->getJson($this->url.'?q=pijama')->assertOk()->json('data'))->pluck('url');

        expect($urls->contains(fn (string $url) => str_contains($url, '/shopping/product/')))->toBeFalse()
            ->and($urls->every(fn (string $url) => str_starts_with($url, 'https://www.google.com/search?')))->toBeTrue();
    });

    it('keeps the search link short enough to store, even for long accented titles', function () {
        $url = SerperProductSearchProvider::shoppingSearchUrl(str_repeat('ñandú pijama ', 30));

        expect(strlen($url))->toBeLessThanOrEqual(500)
            ->and($url)->toStartWith('https://www.google.com/search?q=')
            ->and($url)->toContain('udm=28');
    });
});

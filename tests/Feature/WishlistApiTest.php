<?php

use App\Models\Category;
use App\Models\Group;
use App\Models\Participant;
use App\Models\WishlistItem;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->category = Category::factory()->for($this->group)->create(['label' => 'Pantuflas']);
    $this->me = Participant::factory()->for($this->group)->claimed('1234', 'my-token')->create();
    $this->other = Participant::factory()->for($this->group)->claimed('1234', 'other-token')->create();
    Participant::factory()->for($this->group)->count(2)->create();
    $this->group->drawAssignments();
    $this->wishlistUrl = "/api/v1/groups/{$this->group->slug}/me/wishlist";
});

function itemPayload(array $overrides = []): array
{
    return [
        'title' => 'Pantuflas de borrego',
        'price' => 250,
        'url' => 'https://tienda.example/pantuflas',
        'image_url' => 'https://tienda.example/pantuflas.jpg',
        'note' => 'Talla M, por favor',
        ...$overrides,
    ];
}

describe('adding to my wishlist', function () {
    it('creates an item in one of the group categories', function () {
        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload(['category_id' => $this->category->id]))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pantuflas de borrego')
            ->assertJsonPath('data.category_id', $this->category->id);

        expect($this->me->wishlistItems()->count())->toBe(1)
            ->and($this->other->wishlistItems()->count())->toBe(0);
    });

    it('does not require a category even when the group defines categories', function () {
        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload())
            ->assertCreated()
            ->assertJsonPath('data.category_id', null);

        expect($this->me->wishlistItems()->count())->toBe(1);
    });

    it('also works in a group that has no categories', function () {
        $group = Group::factory()->create();
        Participant::factory()->for($group)->count(2)->create();
        Participant::factory()->for($group)->claimed('1234', 'free-token')->create();
        $group->drawAssignments();

        $this->withToken('free-token')
            ->postJson("/api/v1/groups/{$group->slug}/me/wishlist", itemPayload())
            ->assertCreated();
    });

    it('rejects a category from another group', function () {
        $foreign = Category::factory()->create();

        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload(['category_id' => $foreign->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    });

    it('allows a price above the budget because the interface only warns', function () {
        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload(['category_id' => $this->category->id, 'price' => $this->group->budget + 500]))
            ->assertCreated();
    });

    it('rejects invalid data', function (array $overrides, string $field) {
        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload(['category_id' => $this->category->id, ...$overrides]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(WishlistItem::query()->count())->toBe(0);
    })->with([
        'missing title' => [['title' => ''], 'title'],
        'javascript link' => [['url' => 'javascript:alert(1)'], 'url'],
        'link without scheme' => [['url' => 'tienda.example'], 'url'],
        'data image' => [['image_url' => 'data:image/png;base64,AAAA'], 'image_url'],
        'negative price' => [['price' => -1], 'price'],
        'long note' => [['note' => str_repeat('a', 201)], 'note'],
    ]);

    it('stops at the maximum number of items', function () {
        WishlistItem::factory()->for($this->me)->count(WishlistItem::MAXIMUM_PER_PARTICIPANT)->create();

        $this->withToken('my-token')
            ->postJson($this->wishlistUrl, itemPayload(['category_id' => $this->category->id]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'WISHLIST_FULL');
    });

    it('requires a session', function () {
        $this->postJson($this->wishlistUrl, itemPayload())->assertUnauthorized();
        $this->getJson($this->wishlistUrl)->assertUnauthorized();
    });
});

describe('listing, editing and removing my items', function () {
    it('lists only my own items', function () {
        WishlistItem::factory()->for($this->me)->count(2)->create();
        WishlistItem::factory()->for($this->other)->count(3)->create();

        $this->withToken('my-token')->getJson($this->wishlistUrl)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('updates my item', function () {
        $item = WishlistItem::factory()->for($this->me)->create(['title' => 'Viejo']);

        $this->withToken('my-token')
            ->putJson("{$this->wishlistUrl}/{$item->id}", itemPayload(['category_id' => $this->category->id, 'title' => 'Nuevo']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Nuevo');

        expect($item->refresh()->title)->toBe('Nuevo');
    });

    it('deletes my item', function () {
        $item = WishlistItem::factory()->for($this->me)->create();

        $this->withToken('my-token')->deleteJson("{$this->wishlistUrl}/{$item->id}")->assertNoContent();

        expect(WishlistItem::query()->count())->toBe(0);
    });

    it('answers 404 when touching someone else\'s item', function () {
        $item = WishlistItem::factory()->for($this->other)->create(['title' => 'Ajeno']);

        $this->withToken('my-token')
            ->putJson("{$this->wishlistUrl}/{$item->id}", itemPayload(['category_id' => $this->category->id]))
            ->assertNotFound();
        $this->withToken('my-token')->deleteJson("{$this->wishlistUrl}/{$item->id}")->assertNotFound();

        expect($item->refresh()->title)->toBe('Ajeno');
    });
});

describe('the wishlist of the person I give a gift to', function () {
    it('returns only the receiver\'s items', function () {
        $receiver = $this->me->fresh()->receiver;
        WishlistItem::factory()->for($receiver)->count(2)->create();
        WishlistItem::factory()->for($this->me)->count(3)->create();
        $bystander = Participant::query()->whereNotIn('id', [$this->me->id, $receiver->id])->first();
        WishlistItem::factory()->for($bystander)->count(4)->create();

        $this->withToken('my-token')
            ->getJson("/api/v1/groups/{$this->group->slug}/me/assignment/wishlist")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('requires a session', function () {
        $this->getJson("/api/v1/groups/{$this->group->slug}/me/assignment/wishlist")->assertUnauthorized();
    });
});

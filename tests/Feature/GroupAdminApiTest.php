<?php

use App\Models\Category;
use App\Models\Group;
use App\Models\Participant;
use App\Models\WishlistItem;

beforeEach(function () {
    $this->group = Group::factory()->withAdminToken('admin-token')->create(['event_date' => now()->addMonth()->toDateString()]);
    $this->participants = Participant::factory()->for($this->group)->count(5)->create();
    $this->group->drawAssignments();
    $this->adminUrl = "/api/v1/groups/{$this->group->slug}/admin";
});

/**
 * @return array<string, mixed>
 */
function groupUpdatePayload(array $overrides = []): array
{
    return [
        'name' => 'Nuevo nombre',
        'event_date' => now()->addMonths(2)->toDateString(),
        'budget' => 450,
        'theme' => 'Calcetines',
        'theme_description' => 'Los más escandalosos',
        'categories' => ['Lana', 'Algodón'],
        ...$overrides,
    ];
}

describe('the admin overview', function () {
    it('shows who has joined and who has seen their result, without assignments', function () {
        $this->participants[0]->forceFill(['claimed_at' => now()])->save();
        $this->participants[1]->forceFill(['claimed_at' => now(), 'revealed_at' => now()])->save();

        $response = $this->withToken('admin-token')->getJson($this->adminUrl)->assertOk();

        $response->assertJsonPath('data.participants.0.claimed', true)
            ->assertJsonPath('data.participants.0.revealed', false)
            ->assertJsonPath('data.participants.1.revealed', true)
            ->assertJsonPath('data.participants.2.claimed', false)
            ->assertJsonPath('data.can_redraw', false)
            ->assertJsonMissingPath('data.participants.0.receiver_id');
    });

    it('allows redrawing while nobody has seen their result', function () {
        $this->withToken('admin-token')->getJson($this->adminUrl)
            ->assertJsonPath('data.can_redraw', true);
    });

    it('does not expose the revealed flag on the public view', function () {
        $this->getJson("/api/v1/groups/{$this->group->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.participants.0.revealed')
            ->assertJsonMissingPath('data.can_redraw');
    });
});

describe('editing the group', function () {
    it('updates the details and the category list', function () {
        $keep = Category::factory()->for($this->group)->create(['label' => 'Lana']);
        $remove = Category::factory()->for($this->group)->create(['label' => 'Seda']);
        $item = WishlistItem::factory()->for($this->participants[0])->create(['category_id' => $remove->id]);

        $this->withToken('admin-token')->putJson($this->adminUrl, groupUpdatePayload())
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo nombre')
            ->assertJsonPath('data.budget', 450);

        $this->group->refresh();
        expect($this->group->theme)->toBe('Calcetines')
            ->and($this->group->categories->pluck('label')->all())->toBe(['Algodón', 'Lana'])
            ->and($this->group->categories->firstWhere('label', 'Lana')->id)->toBe($keep->id)
            ->and($item->refresh()->category_id)->toBeNull();
    });

    it('keeps the categories when the request does not mention them', function () {
        Category::factory()->for($this->group)->create(['label' => 'Lana']);
        $payload = groupUpdatePayload();
        unset($payload['categories']);

        $this->withToken('admin-token')->putJson($this->adminUrl, $payload)->assertOk();

        expect($this->group->categories()->count())->toBe(1);
    });

    it('does not change the participants or the assignments', function () {
        $before = $this->group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('admin-token')->putJson($this->adminUrl, groupUpdatePayload(['participants' => ['x', 'y', 'z']]))->assertOk();

        expect($this->group->participants()->pluck('receiver_id', 'id')->all())->toBe($before);
    });

    it('accepts an unchanged date even if it already passed, but rejects a new past date', function () {
        $this->group->update(['event_date' => '2020-01-01']);

        $this->withToken('admin-token')
            ->putJson($this->adminUrl, groupUpdatePayload(['event_date' => '2020-01-01']))
            ->assertOk();

        $this->withToken('admin-token')
            ->putJson($this->adminUrl, groupUpdatePayload(['event_date' => '2021-01-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_date');
    });

    it('rejects invalid data', function (array $overrides, string $field) {
        $this->withToken('admin-token')->putJson($this->adminUrl, groupUpdatePayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'empty name' => [['name' => ''], 'name'],
        'zero budget' => [['budget' => 0], 'budget'],
        'repeated categories' => [['categories' => ['Lana', 'lana']], 'categories.1'],
    ]);
});

describe('drawing again', function () {
    it('makes a new single-cycle draw when nobody has seen their result', function () {
        $drawnAt = $this->group->fresh()->drawn_at;
        $this->travel(5)->minutes();

        $this->withToken('admin-token')->postJson("{$this->adminUrl}/draw")->assertOk();

        $participants = $this->group->participants()->get();
        expect($participants->pluck('receiver_id')->unique())->toHaveCount(5)
            ->and($participants->every(fn (Participant $giver) => $giver->receiver_id !== $giver->id))->toBeTrue()
            ->and($this->group->fresh()->drawn_at->gt($drawnAt))->toBeTrue();
    });

    it('never leaves anybody without a receiver, even when a pairing repeats', function () {
        foreach (range(1, 15) as $attempt) {
            $this->withToken('admin-token')->postJson("{$this->adminUrl}/draw")->assertOk();

            expect($this->group->participants()->whereNull('receiver_id')->count())->toBe(0);
        }
    });

    it('refuses once somebody has seen their result and leaves the draw untouched', function () {
        $this->participants[0]->forceFill(['claimed_at' => now(), 'revealed_at' => now()])->save();
        $before = $this->group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('admin-token')->postJson("{$this->adminUrl}/draw")
            ->assertConflict()
            ->assertJsonPath('code', 'ALREADY_REVEALED');

        expect($this->group->participants()->pluck('receiver_id', 'id')->all())->toBe($before);
    });
});

describe('admin protection', function () {
    it('rejects every admin action without the admin token', function (string $method, string $suffix) {
        $url = "/api/v1/groups/{$this->group->slug}/admin{$suffix}";
        $this->participants[0]->forceFill(['claim_token' => 'participant-token', 'claimed_at' => now()])->save();

        $this->{$method}($url, groupUpdatePayload())->assertForbidden();
        $this->withToken('participant-token')->{$method}($url, groupUpdatePayload())->assertForbidden();
    })->with([
        'overview' => ['getJson', ''],
        'update' => ['putJson', ''],
        'redraw' => ['postJson', '/draw'],
    ]);
});

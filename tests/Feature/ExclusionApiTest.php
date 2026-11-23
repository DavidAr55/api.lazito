<?php

use App\Models\Exclusion;
use App\Models\Group;
use App\Models\Participant;

/**
 * @return array<string, mixed>
 */
function groupWithExclusionsPayload(array $overrides = []): array
{
    return [
        'name' => 'Navidad oficina',
        'event_date' => now()->addMonth()->toDateString(),
        'budget' => 300,
        'theme' => 'Pijamas',
        'participants' => ['Ana', 'Luis', 'Mar', 'Rob', 'Sof', 'Tere'],
        'exclusions' => [['a' => 'Ana', 'b' => 'Luis'], ['a' => 'Mar', 'b' => 'Rob']],
        ...$overrides,
    ];
}

/**
 * Whether two participants are neighbours in the draw, in either direction.
 */
function areNeighbours(Participant $first, Participant $second): bool
{
    return $first->receiver_id === $second->id || $second->receiver_id === $first->id;
}

describe('creating a group with exclusions', function () {
    it('never matches an excluded pair, in either direction', function () {
        foreach (range(1, 40) as $attempt) {
            $group = Group::openWithDraw(groupWithExclusionsPayload(), 'admin-token');
            $byName = $group->participants()->get()->keyBy('name');

            expect(areNeighbours($byName['Ana'], $byName['Luis']))->toBeFalse()
                ->and(areNeighbours($byName['Mar'], $byName['Rob']))->toBeFalse()
                ->and($byName->pluck('receiver_id')->unique())->toHaveCount(6);
        }
    });

    it('still draws a single cycle', function () {
        $slug = $this->postJson('/api/v1/groups', groupWithExclusionsPayload())->json('data.slug');
        $participants = Group::query()->where('slug', $slug)->firstOrFail()->participants;

        $visited = collect();
        $current = $participants->first();
        while (! $visited->contains($current->id)) {
            $visited->push($current->id);
            $current = $participants->firstWhere('id', $current->receiver_id);
        }

        expect($visited)->toHaveCount(6);
    });

    it('stores each pair once, whatever the order it was sent in', function () {
        $slug = $this->postJson('/api/v1/groups', groupWithExclusionsPayload([
            'exclusions' => [['a' => 'Ana', 'b' => 'Luis'], ['a' => 'Luis', 'b' => 'Ana']],
        ]))->json('data.slug');

        expect(Group::query()->where('slug', $slug)->firstOrFail()->exclusions)->toHaveCount(1);
    });

    it('rejects a draw that the exclusions make impossible and creates nothing', function () {
        $this->postJson('/api/v1/groups', groupWithExclusionsPayload([
            'participants' => ['Ana', 'Luis', 'Mar'],
            'exclusions' => [['a' => 'Ana', 'b' => 'Luis']],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect(Group::query()->count())->toBe(0)
            ->and(Participant::query()->count())->toBe(0)
            ->and(Exclusion::query()->count())->toBe(0);
    });

    it('rejects invalid exclusions', function (array $exclusions, string $field) {
        $this->postJson('/api/v1/groups', groupWithExclusionsPayload(['exclusions' => $exclusions]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'someone who does not participate' => [[['a' => 'Ana', 'b' => 'Fantasma']], 'exclusions.0.b'],
        'the same person twice' => [[['a' => 'Ana', 'b' => 'Ana']], 'exclusions.0.b'],
        'a pair without one side' => [[['a' => 'Ana']], 'exclusions.0.b'],
    ]);

    it('finds a draw when many exclusions leave only a few valid neighbours', function (int $people, int $blockedAhead) {
        $names = array_map(fn (int $index): string => "P{$index}", range(1, $people));
        $exclusions = [];
        foreach (range(1, $people) as $index) {
            foreach (range(1, $blockedAhead) as $offset) {
                $exclusions[] = ['a' => "P{$index}", 'b' => 'P'.((($index + $offset - 1) % $people) + 1)];
            }
        }

        $group = Group::openWithDraw(groupWithExclusionsPayload(['participants' => $names, 'exclusions' => $exclusions]), 'admin-token');

        expect($group->participants()->whereNull('receiver_id')->count())->toBe(0);
    })->with([
        'twenty people, six blocked each way' => [20, 6],
        'fifty people, fifteen blocked each way' => [50, 15],
    ]);

    it('rejects at once exclusions that cut the group in two, without searching', function () {
        $exclusions = [];
        foreach (range(1, 6) as $left) {
            foreach (range(7, 12) as $right) {
                $exclusions[] = ['a' => "P{$left}", 'b' => "P{$right}"];
            }
        }
        $names = array_map(fn (int $index): string => "P{$index}", range(1, 12));

        $started = microtime(true);
        $this->postJson('/api/v1/groups', groupWithExclusionsPayload(['participants' => $names, 'exclusions' => $exclusions]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect(microtime(true) - $started)->toBeLessThan(1.0);
    });

    it('rejects at once someone who cannot touch anybody but one person', function () {
        $names = array_map(fn (int $index): string => "P{$index}", range(1, 40));
        $exclusions = array_map(fn (int $index): array => ['a' => 'P1', 'b' => "P{$index}"], range(3, 40));

        $started = microtime(true);
        $this->postJson('/api/v1/groups', groupWithExclusionsPayload(['participants' => $names, 'exclusions' => $exclusions]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect(microtime(true) - $started)->toBeLessThan(1.0);
    });

    it('keeps working without exclusions', function () {
        $this->postJson('/api/v1/groups', groupWithExclusionsPayload(['exclusions' => []]))->assertCreated();
        $this->postJson('/api/v1/groups', collect(groupWithExclusionsPayload())->except('exclusions')->all())->assertCreated();
    });
});

describe('exclusions are private', function () {
    beforeEach(function () {
        $this->group = Group::factory()->withAdminToken('admin-token')->create();
        $this->participants = Participant::factory()->for($this->group)->count(6)->create();
        Exclusion::factory()->between($this->participants[0], $this->participants[1])->create();
        $this->group->drawAssignments();
    });

    it('does not appear in the public view', function () {
        $this->getJson("/api/v1/groups/{$this->group->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.exclusions');
    });

    it('appears in the admin view', function () {
        $this->withToken('admin-token')->getJson("/api/v1/groups/{$this->group->slug}/admin")
            ->assertOk()
            ->assertJsonCount(1, 'data.exclusions')
            ->assertJsonPath('data.exclusions.0.a', min($this->participants[0]->id, $this->participants[1]->id));
    });
});

describe('changing the exclusions as admin', function () {
    beforeEach(function () {
        $this->group = Group::factory()->withAdminToken('admin-token')->create();
        $this->participants = Participant::factory()->for($this->group)->count(6)->create();
        $this->group->drawAssignments();
        $this->url = "/api/v1/groups/{$this->group->slug}/admin/exclusions";
    });

    it('replaces the exclusions and draws again respecting them', function () {
        [$ana, $luis, $mar, $rob] = $this->participants;

        foreach (range(1, 25) as $attempt) {
            $this->withToken('admin-token')
                ->putJson($this->url, ['exclusions' => [['a' => $ana->id, 'b' => $luis->id], ['a' => $mar->id, 'b' => $rob->id]]])
                ->assertOk()
                ->assertJsonCount(2, 'data.exclusions');

            $fresh = $this->group->participants()->get()->keyBy('id');
            expect(areNeighbours($fresh[$ana->id], $fresh[$luis->id]))->toBeFalse()
                ->and(areNeighbours($fresh[$mar->id], $fresh[$rob->id]))->toBeFalse()
                ->and($fresh->pluck('receiver_id')->filter())->toHaveCount(6);
        }
    });

    it('clears the exclusions with an empty list', function () {
        Exclusion::factory()->between($this->participants[0], $this->participants[1])->create();

        $this->withToken('admin-token')->putJson($this->url, ['exclusions' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.exclusions');

        expect(Exclusion::query()->count())->toBe(0);
    });

    it('keeps the previous draw and exclusions when the new ones are impossible', function () {
        $group = Group::factory()->withAdminToken('small-token')->create();
        $three = Participant::factory()->for($group)->count(3)->create();
        $group->drawAssignments();
        $before = $group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('small-token')
            ->putJson("/api/v1/groups/{$group->slug}/admin/exclusions", ['exclusions' => [['a' => $three[0]->id, 'b' => $three[1]->id]]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect($group->participants()->pluck('receiver_id', 'id')->all())->toBe($before)
            ->and(Exclusion::query()->count())->toBe(0);
    });

    it('refuses once somebody has seen their result and changes nothing', function () {
        $this->participants[0]->forceFill(['claimed_at' => now(), 'revealed_at' => now()])->save();
        $before = $this->group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('admin-token')
            ->putJson($this->url, ['exclusions' => [['a' => $this->participants[1]->id, 'b' => $this->participants[2]->id]]])
            ->assertConflict()
            ->assertJsonPath('code', 'ALREADY_REVEALED');

        expect($this->group->participants()->pluck('receiver_id', 'id')->all())->toBe($before)
            ->and(Exclusion::query()->count())->toBe(0);
    });

    it('rejects participants of another group', function () {
        $foreign = Participant::factory()->create();

        $this->withToken('admin-token')
            ->putJson($this->url, ['exclusions' => [['a' => $this->participants[0]->id, 'b' => $foreign->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exclusions.0.b');
    });

    it('requires the admin token', function () {
        $this->putJson($this->url, ['exclusions' => []])->assertForbidden();
        $this->withToken('nope')->putJson($this->url, ['exclusions' => []])->assertForbidden();
    });
});

it('keeps honoring the exclusions on a plain redraw', function () {
    $group = Group::factory()->withAdminToken('admin-token')->create();
    $participants = Participant::factory()->for($group)->count(5)->create();
    Exclusion::factory()->between($participants[0], $participants[1])->create();
    $group->drawAssignments();

    foreach (range(1, 25) as $attempt) {
        $this->withToken('admin-token')->postJson("/api/v1/groups/{$group->slug}/admin/draw")->assertOk();

        $fresh = $group->participants()->get()->keyBy('id');
        expect(areNeighbours($fresh[$participants[0]->id], $fresh[$participants[1]->id]))->toBeFalse();
    }
});

<?php

use App\Models\Exclusion;
use App\Models\FixedAssignment;
use App\Models\Group;
use App\Models\Participant;

/**
 * @return array<string, mixed>
 */
function groupWithJokeRules(array $overrides = []): array
{
    return [
        'name' => 'Navidad oficina',
        'event_date' => now()->addMonth()->toDateString(),
        'budget' => 300,
        'theme' => 'Pijamas',
        'participants' => ['José', 'Ana', 'Luis', 'Mar', 'Rob', 'Sof'],
        'fixed_assignments' => [['giver' => 'José', 'receiver' => 'Ana']],
        ...$overrides,
    ];
}

/**
 * @return array<string, Participant> participants of the group by name, with their drawn receiver
 */
function drawnBy(Group $group): array
{
    return $group->participants()->get()->keyBy('name')->all();
}

/**
 * Whether two participants are next to each other in the draw, in either direction.
 */
function neighboursInDraw(Participant $first, Participant $second): bool
{
    return $first->receiver_id === $second->id || $second->receiver_id === $first->id;
}

function receiverNameOf(array $byName, string $giver): string
{
    return collect($byName)->firstWhere('id', $byName[$giver]->receiver_id)->name;
}

describe('creating a group with joke rules', function () {
    it('always gives the hand-picked pair, and still draws one cycle for everybody else', function () {
        foreach (range(1, 40) as $attempt) {
            $group = Group::openWithDraw(groupWithJokeRules(), 'admin-token');
            $byName = drawnBy($group);

            expect(receiverNameOf($byName, 'José'))->toBe('Ana')
                ->and(collect($byName)->pluck('receiver_id')->unique())->toHaveCount(6)
                ->and(collect($byName)->every(fn (Participant $giver) => $giver->receiver_id !== $giver->id))->toBeTrue();
        }
    });

    it('draws a single cycle through all participants', function () {
        $group = Group::openWithDraw(groupWithJokeRules(), 'admin-token');
        $participants = $group->participants()->get();

        $visited = collect();
        $current = $participants->first();
        while (! $visited->contains($current->id)) {
            $visited->push($current->id);
            $current = $participants->firstWhere('id', $current->receiver_id);
        }

        expect($visited)->toHaveCount(6);
    });

    it('keeps the randomness for the people without a rule', function () {
        $receivers = collect(range(1, 40))->map(function () {
            $byName = drawnBy(Group::openWithDraw(groupWithJokeRules(), 'admin-token'));

            return receiverNameOf($byName, 'Luis');
        });

        expect($receivers->unique()->count())->toBeGreaterThan(2);
    });

    it('chains several hand-picked pairs, even a long one', function () {
        foreach (range(1, 25) as $attempt) {
            $byName = drawnBy(Group::openWithDraw(groupWithJokeRules([
                'fixed_assignments' => [
                    ['giver' => 'José', 'receiver' => 'Ana'],
                    ['giver' => 'Ana', 'receiver' => 'Luis'],
                    ['giver' => 'Mar', 'receiver' => 'Rob'],
                ],
            ]), 'admin-token'));

            expect(receiverNameOf($byName, 'José'))->toBe('Ana')
                ->and(receiverNameOf($byName, 'Ana'))->toBe('Luis')
                ->and(receiverNameOf($byName, 'Mar'))->toBe('Rob')
                ->and(collect($byName)->pluck('receiver_id')->unique())->toHaveCount(6);
        }
    });

    it('works when the pairs leave only one or two blocks to order', function (array $names, array $pairs) {
        foreach (range(1, 15) as $attempt) {
            $byName = drawnBy(Group::openWithDraw(groupWithJokeRules([
                'participants' => $names,
                'fixed_assignments' => array_map(fn (array $pair): array => ['giver' => $pair[0], 'receiver' => $pair[1]], $pairs),
            ]), 'admin-token'));

            foreach ($pairs as [$giver, $receiver]) {
                expect(receiverNameOf($byName, $giver))->toBe($receiver);
            }
            expect(collect($byName)->pluck('receiver_id')->unique())->toHaveCount(count($names));
        }
    })->with([
        'one chain through everybody but the closing step' => [['A', 'B', 'C', 'D'], [['A', 'B'], ['B', 'C'], ['C', 'D']]],
        'two blocks' => [['A', 'B', 'C', 'D'], [['A', 'B'], ['C', 'D']]],
        'the whole cycle by hand' => [['A', 'B', 'C'], [['A', 'B'], ['B', 'C'], ['C', 'A']]],
        'three people, one pair' => [['A', 'B', 'C'], [['A', 'B']]],
    ]);

    it('respects exclusions and joke rules together', function () {
        foreach (range(1, 25) as $attempt) {
            $byName = drawnBy(Group::openWithDraw(groupWithJokeRules([
                'exclusions' => [['a' => 'Luis', 'b' => 'Mar'], ['a' => 'Rob', 'b' => 'Sof']],
            ]), 'admin-token'));

            expect(receiverNameOf($byName, 'José'))->toBe('Ana')
                ->and(neighboursInDraw($byName['Luis'], $byName['Mar']))->toBeFalse()
                ->and(neighboursInDraw($byName['Rob'], $byName['Sof']))->toBeFalse();
        }
    });

    it('refuses a rule that contradicts an exclusion, and creates nothing', function () {
        $this->postJson('/api/v1/groups', groupWithJokeRules([
            'exclusions' => [['a' => 'José', 'b' => 'Ana']],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect(Group::query()->count())->toBe(0)
            ->and(FixedAssignment::query()->count())->toBe(0);
    });

    it('refuses pairs that close a loop leaving somebody out', function () {
        $this->postJson('/api/v1/groups', groupWithJokeRules([
            'fixed_assignments' => [['giver' => 'José', 'receiver' => 'Ana'], ['giver' => 'Ana', 'receiver' => 'José']],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');
    });

    it('rejects invalid rules', function (array $fixed, string $field) {
        $this->postJson('/api/v1/groups', groupWithJokeRules(['fixed_assignments' => $fixed]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Group::query()->count())->toBe(0);
    })->with([
        'someone who does not participate' => [[['giver' => 'José', 'receiver' => 'Fantasma']], 'fixed_assignments.0.receiver'],
        'giving to themselves' => [[['giver' => 'José', 'receiver' => 'José']], 'fixed_assignments.0.receiver'],
        'a giver with two rules' => [[['giver' => 'José', 'receiver' => 'Ana'], ['giver' => 'José', 'receiver' => 'Luis']], 'fixed_assignments.1.giver'],
        'a receiver with two givers' => [[['giver' => 'José', 'receiver' => 'Ana'], ['giver' => 'Luis', 'receiver' => 'Ana']], 'fixed_assignments.1.receiver'],
        'a rule without receiver' => [[['giver' => 'José']], 'fixed_assignments.0.receiver'],
    ]);

    it('keeps working without rules', function () {
        $this->postJson('/api/v1/groups', collect(groupWithJokeRules())->except('fixed_assignments')->all())->assertCreated();
    });
});

describe('joke rules are private to the organizer', function () {
    beforeEach(function () {
        $this->group = Group::factory()->withAdminToken('admin-token')->create();
        $this->participants = Participant::factory()->for($this->group)->count(6)->create();
        FixedAssignment::factory()->between($this->participants[0], $this->participants[1])->create();
        $this->group->drawAssignments();
    });

    it('does not appear in the public view', function () {
        $this->getJson("/api/v1/groups/{$this->group->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.fixed_assignments');
    });

    it('does not reach the other participants, who only see their own result', function () {
        $this->participants[2]->forceFill(['claim_token' => 'luis-token', 'claimed_at' => now()])->save();

        $this->withToken('luis-token')->getJson("/api/v1/groups/{$this->group->slug}/me/assignment")
            ->assertOk()
            ->assertJsonMissingPath('data.fixed_assignments');
        $this->withToken('luis-token')->getJson("/api/v1/groups/{$this->group->slug}/admin")->assertForbidden();
    });

    it('appears in the admin view', function () {
        $this->withToken('admin-token')->getJson("/api/v1/groups/{$this->group->slug}/admin")
            ->assertOk()
            ->assertJsonCount(1, 'data.fixed_assignments')
            ->assertJsonPath('data.fixed_assignments.0.giver', $this->participants[0]->id)
            ->assertJsonPath('data.fixed_assignments.0.receiver', $this->participants[1]->id);
    });
});

describe('changing the joke rules as admin', function () {
    beforeEach(function () {
        $this->group = Group::factory()->withAdminToken('admin-token')->create();
        $this->participants = Participant::factory()->for($this->group)->count(6)->create();
        $this->group->drawAssignments();
        $this->url = "/api/v1/groups/{$this->group->slug}/admin/fixed-assignments";
    });

    it('replaces the rules and draws again respecting them', function () {
        [$jose, $ana, $luis, $mar] = $this->participants;

        foreach (range(1, 25) as $attempt) {
            $this->withToken('admin-token')
                ->putJson($this->url, ['fixed_assignments' => [['giver' => $jose->id, 'receiver' => $ana->id], ['giver' => $luis->id, 'receiver' => $mar->id]]])
                ->assertOk()
                ->assertJsonCount(2, 'data.fixed_assignments');

            $fresh = $this->group->participants()->get()->keyBy('id');
            expect($fresh[$jose->id]->receiver_id)->toBe($ana->id)
                ->and($fresh[$luis->id]->receiver_id)->toBe($mar->id)
                ->and($fresh->pluck('receiver_id')->filter())->toHaveCount(6);
        }
    });

    it('clears the rules with an empty list', function () {
        FixedAssignment::factory()->between($this->participants[0], $this->participants[1])->create();

        $this->withToken('admin-token')->putJson($this->url, ['fixed_assignments' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.fixed_assignments');

        expect(FixedAssignment::query()->count())->toBe(0);
    });

    it('keeps the previous draw and rules when the new ones are impossible', function () {
        FixedAssignment::factory()->between($this->participants[0], $this->participants[1])->create();
        Exclusion::factory()->between($this->participants[2], $this->participants[3])->create();
        $this->group->drawAssignments();
        $before = $this->group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('admin-token')
            ->putJson($this->url, ['fixed_assignments' => [['giver' => $this->participants[2]->id, 'receiver' => $this->participants[3]->id]]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'NO_VALID_DRAW');

        expect($this->group->participants()->pluck('receiver_id', 'id')->all())->toBe($before)
            ->and(FixedAssignment::query()->count())->toBe(1)
            ->and(FixedAssignment::query()->value('giver_id'))->toBe($this->participants[0]->id);
    });

    it('refuses once somebody has seen their result and changes nothing', function () {
        $this->participants[0]->forceFill(['claimed_at' => now(), 'revealed_at' => now()])->save();
        $before = $this->group->participants()->pluck('receiver_id', 'id')->all();

        $this->withToken('admin-token')
            ->putJson($this->url, ['fixed_assignments' => [['giver' => $this->participants[1]->id, 'receiver' => $this->participants[2]->id]]])
            ->assertConflict()
            ->assertJsonPath('code', 'ALREADY_REVEALED');

        expect($this->group->participants()->pluck('receiver_id', 'id')->all())->toBe($before)
            ->and(FixedAssignment::query()->count())->toBe(0);
    });

    it('does not touch the exclusions when only the joke rules change', function () {
        Exclusion::factory()->between($this->participants[2], $this->participants[3])->create();

        $this->withToken('admin-token')
            ->putJson($this->url, ['fixed_assignments' => [['giver' => $this->participants[0]->id, 'receiver' => $this->participants[1]->id]]])
            ->assertOk();

        expect(Exclusion::query()->count())->toBe(1);
    });

    it('rejects invalid rules', function (callable $payload, string $field) {
        $foreign = Participant::factory()->create();

        $this->withToken('admin-token')->putJson($this->url, $payload($this->participants, $foreign))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'participant of another group' => [fn ($p, $foreign) => ['fixed_assignments' => [['giver' => $p[0]->id, 'receiver' => $foreign->id]]], 'fixed_assignments.0.receiver'],
        'giving to themselves' => [fn ($p) => ['fixed_assignments' => [['giver' => $p[0]->id, 'receiver' => $p[0]->id]]], 'fixed_assignments.0.receiver'],
        'a giver twice' => [fn ($p) => ['fixed_assignments' => [['giver' => $p[0]->id, 'receiver' => $p[1]->id], ['giver' => $p[0]->id, 'receiver' => $p[2]->id]]], 'fixed_assignments.1.giver'],
        'a receiver twice' => [fn ($p) => ['fixed_assignments' => [['giver' => $p[0]->id, 'receiver' => $p[2]->id], ['giver' => $p[1]->id, 'receiver' => $p[2]->id]]], 'fixed_assignments.1.receiver'],
        'the list missing' => [fn () => [], 'fixed_assignments'],
    ]);

    it('requires the admin token', function () {
        $this->putJson($this->url, ['fixed_assignments' => []])->assertForbidden();
        $this->withToken('nope')->putJson($this->url, ['fixed_assignments' => []])->assertForbidden();
    });
});

it('keeps honoring the joke rules on a plain redraw', function () {
    $group = Group::factory()->withAdminToken('admin-token')->create();
    $participants = Participant::factory()->for($group)->count(5)->create();
    FixedAssignment::factory()->between($participants[0], $participants[1])->create();
    $group->drawAssignments();

    foreach (range(1, 25) as $attempt) {
        $this->withToken('admin-token')->postJson("/api/v1/groups/{$group->slug}/admin/draw")->assertOk();

        expect($group->participants()->whereKey($participants[0]->id)->value('receiver_id'))->toBe($participants[1]->id);
    }
});

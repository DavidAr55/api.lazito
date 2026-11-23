<?php

use App\Models\Category;
use App\Models\Group;
use App\Models\Participant;

/**
 * @return array<string, mixed>
 */
function validGroupPayload(array $overrides = []): array
{
    return [
        'name' => 'Navidad oficina',
        'event_date' => now()->addMonth()->toDateString(),
        'budget' => 300,
        'theme' => 'Pijamas',
        'theme_description' => 'Afelpadas, talla real',
        'categories' => ['Pijama completa', 'Pantuflas'],
        'participants' => ['Ana', 'Luis', 'Mar', 'Rob'],
        ...$overrides,
    ];
}

describe('creating a group', function () {
    it('creates the group and returns its slug and a one-time admin token', function () {
        $response = $this->postJson('/api/v1/groups', validGroupPayload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['slug', 'admin_token']]);

        $group = Group::query()->where('slug', $response->json('data.slug'))->firstOrFail();
        expect($group->participants)->toHaveCount(4)
            ->and($group->categories)->toHaveCount(2)
            ->and($group->hasAdminToken($response->json('data.admin_token')))->toBeTrue()
            ->and($group->admin_token_hash)->not->toBe($response->json('data.admin_token'));
    });

    it('draws a single cycle where everybody gives and receives exactly once', function () {
        $slug = $this->postJson('/api/v1/groups', validGroupPayload([
            'participants' => ['Ana', 'Luis', 'Mar', 'Rob', 'Sof', 'Tere', 'Beto'],
        ]))->json('data.slug');

        $participants = Group::query()->where('slug', $slug)->firstOrFail()->participants;

        expect($participants->pluck('receiver_id')->filter())->toHaveCount(7)
            ->and($participants->pluck('receiver_id')->unique())->toHaveCount(7)
            ->and($participants->every(fn (Participant $giver) => $giver->receiver_id !== $giver->id))->toBeTrue();

        $visited = collect();
        $current = $participants->first();
        while (! $visited->contains($current->id)) {
            $visited->push($current->id);
            $current = $participants->firstWhere('id', $current->receiver_id);
        }
        expect($visited)->toHaveCount(7);
    });

    it('rejects invalid data', function (array $overrides, string $field) {
        $this->postJson('/api/v1/groups', validGroupPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Group::query()->count())->toBe(0);
    })->with([
        'fewer than three participants' => [['participants' => ['Ana', 'Luis']], 'participants'],
        'duplicate names ignoring case' => [['participants' => ['Ana', 'ana', 'Luis']], 'participants.1'],
        'past date' => [['event_date' => '2020-01-01'], 'event_date'],
        'zero budget' => [['budget' => 0], 'budget'],
        'missing theme' => [['theme' => ''], 'theme'],
        'repeated categories' => [['categories' => ['Pantuflas', 'pantuflas']], 'categories.1'],
    ]);
});

describe('viewing a group', function () {
    it('shows only public data and never leaks assignments or secrets', function () {
        $group = Group::factory()->has(Category::factory()->count(2))->create();
        Participant::factory()->for($group)->count(3)->create();
        Participant::factory()->for($group)->claimed()->create(['name' => 'Ya entró']);
        $group->drawAssignments();

        $response = $this->getJson("/api/v1/groups/{$group->slug}")->assertOk();

        $response->assertJsonCount(4, 'data.participants')
            ->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.participants.3.claimed', true)
            ->assertJsonPath('data.participants.0.claimed', false)
            ->assertJsonMissingPath('data.participants.0.receiver_id')
            ->assertJsonMissingPath('data.participants.0.pin')
            ->assertJsonMissingPath('data.participants.0.claim_token')
            ->assertJsonMissingPath('data.admin_token_hash');
    });

    it('returns 404 for an unknown group', function () {
        $this->getJson('/api/v1/groups/no-existe')->assertNotFound();
    });
});

describe('group admin access', function () {
    it('accepts the admin token of that group', function () {
        $group = Group::factory()->withAdminToken('secret-admin-token')->create();

        $this->withToken('secret-admin-token')
            ->getJson("/api/v1/groups/{$group->slug}/admin")
            ->assertOk()
            ->assertJsonPath('data.slug', $group->slug);
    });

    it('rejects missing, wrong or another group\'s token', function (?string $token) {
        $group = Group::factory()->withAdminToken('secret-admin-token')->create();
        Group::factory()->withAdminToken('other-token')->create();

        $request = $token === null ? $this : $this->withToken($token);

        $request->getJson("/api/v1/groups/{$group->slug}/admin")
            ->assertForbidden()
            ->assertJsonPath('code', 'INVALID_ADMIN_TOKEN');
    })->with([
        'no token' => [null],
        'wrong token' => ['nope'],
        'token of another group' => ['other-token'],
    ]);
});

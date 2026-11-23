<?php

use App\Models\Group;
use App\Models\Participant;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->participants = Participant::factory()->for($this->group)->count(4)->create();
    $this->group->drawAssignments();
    $this->participant = $this->participants->first();
});

function participantUrl(Participant $participant, string $action): string
{
    return "/api/v1/groups/{$participant->group->slug}/participants/{$participant->id}/{$action}";
}

describe('claiming a name', function () {
    it('stores a hashed PIN and returns the session token', function () {
        $response = $this->postJson(participantUrl($this->participant, 'claim'), ['pin' => '1234'])
            ->assertCreated()
            ->assertJsonPath('data.revealed', false);

        $this->participant->refresh();
        expect($this->participant->isClaimed())->toBeTrue()
            ->and($this->participant->claim_token)->toBe($response->json('data.claim_token'))
            ->and($this->participant->getRawOriginal('pin'))->not->toBe('1234')
            ->and(Hash::check('1234', $this->participant->pin))->toBeTrue();
    });

    it('does not let a second person claim the same name', function () {
        $claimed = Participant::factory()->for($this->group)->claimed('1234', 'first-token')->create();

        $this->postJson(participantUrl($claimed, 'claim'), ['pin' => '9999'])
            ->assertConflict()
            ->assertJsonPath('code', 'ALREADY_CLAIMED');

        expect($claimed->refresh()->claim_token)->toBe('first-token')
            ->and(Hash::check('1234', $claimed->pin))->toBeTrue();
    });

    it('rejects a PIN that is not 4 digits', function (string $pin) {
        $this->postJson(participantUrl($this->participant, 'claim'), ['pin' => $pin])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pin');
    })->with(['too short' => '123', 'too long' => '12345', 'letters' => 'abcd']);

    it('returns 404 for a participant of another group', function () {
        $foreign = Participant::factory()->create();

        $this->postJson("/api/v1/groups/{$this->group->slug}/participants/{$foreign->id}/claim", ['pin' => '1234'])
            ->assertNotFound();
    });
});

describe('signing back in with the PIN', function () {
    it('returns the same session token when the PIN is correct', function () {
        $claimed = Participant::factory()->for($this->group)->revealed()->create();
        $claimed->forceFill(['claim_token' => 'my-token'])->save();

        $this->postJson(participantUrl($claimed, 'session'), ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.claim_token', 'my-token')
            ->assertJsonPath('data.revealed', true);
    });

    it('rejects a wrong PIN', function () {
        $claimed = Participant::factory()->for($this->group)->claimed('1234')->create();

        $this->postJson(participantUrl($claimed, 'session'), ['pin' => '0000'])
            ->assertForbidden()
            ->assertJsonPath('code', 'WRONG_PIN');
    });

    it('rejects a name nobody has claimed yet', function () {
        $this->postJson(participantUrl($this->participant, 'session'), ['pin' => '1234'])
            ->assertConflict()
            ->assertJsonPath('code', 'NOT_CLAIMED');
    });

    it('throttles repeated PIN guesses', function () {
        $claimed = Participant::factory()->for($this->group)->claimed('1234')->create();

        foreach (range(1, 5) as $attempt) {
            $this->postJson(participantUrl($claimed, 'session'), ['pin' => '0000'])->assertForbidden();
        }

        $this->postJson(participantUrl($claimed, 'session'), ['pin' => '1234'])->assertTooManyRequests();
    });
});

describe('the participant session', function () {
    it('identifies the participant by their token', function () {
        $claimed = Participant::factory()->for($this->group)->claimed('1234', 'my-token')->create();

        $this->withToken('my-token')
            ->getJson("/api/v1/groups/{$this->group->slug}/me")
            ->assertOk()
            ->assertJsonPath('data.id', $claimed->id)
            ->assertJsonPath('data.revealed', false);
    });

    it('rejects a missing, unknown or other group\'s token', function (?string $token) {
        $otherGroup = Group::factory()->create();
        Participant::factory()->for($otherGroup)->claimed('1234', 'foreign-token')->create();

        $request = $token === null ? $this : $this->withToken($token);

        $request->getJson("/api/v1/groups/{$this->group->slug}/me")
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_SESSION');
    })->with([
        'no token' => [null],
        'unknown token' => ['nope'],
        'token of another group' => ['foreign-token'],
    ]);
});

describe('the assignment', function () {
    it('returns only the participant\'s own receiver and marks it as revealed', function () {
        $giver = $this->participants->first()->fresh();
        $giver->forceFill(['claim_token' => 'giver-token', 'claimed_at' => now()])->save();

        $this->withToken('giver-token')
            ->getJson("/api/v1/groups/{$this->group->slug}/me/assignment")
            ->assertOk()
            ->assertJsonPath('data.id', $giver->receiver_id)
            ->assertJsonMissingPath('data.receiver_id');

        expect($giver->refresh()->revealed_at)->not->toBeNull();
    });

    it('requires a session', function () {
        $this->getJson("/api/v1/groups/{$this->group->slug}/me/assignment")->assertUnauthorized();
    });

    it('gives every participant a different receiver than themselves', function () {
        $receivers = $this->participants->map(function (Participant $giver) {
            $giver->forceFill(['claim_token' => 'token-'.$giver->id, 'claimed_at' => now()])->save();

            return $this->withToken('token-'.$giver->id)
                ->getJson("/api/v1/groups/{$this->group->slug}/me/assignment")
                ->assertOk()
                ->json('data.id');
        });

        expect($receivers->unique())->toHaveCount(4);
        $this->participants->each(fn (Participant $giver, int $index) => expect($receivers[$index])->not->toBe($giver->id));
    });
});

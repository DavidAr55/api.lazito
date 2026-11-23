<?php

namespace App\Models;

use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name'];

    /**
     * @var list<string>
     */
    protected $hidden = ['pin', 'claim_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pin' => 'hashed',
            'claimed_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * The participant this one gives a gift to.
     *
     * @return BelongsTo<Participant, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(self::class, 'receiver_id');
    }

    /**
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->orderBy('id');
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    /**
     * Claims the name atomically; returns the session token, or null if someone claimed it first.
     */
    public function claim(string $pin): ?string
    {
        $claimToken = Str::random(40);

        $claimedRows = self::query()
            ->whereKey($this->getKey())
            ->whereNull('claimed_at')
            ->update([
                'pin' => Hash::make($pin),
                'claim_token' => $claimToken,
                'claimed_at' => now(),
            ]);

        return $claimedRows === 1 ? $claimToken : null;
    }

    public function markRevealed(): void
    {
        if ($this->revealed_at === null) {
            $this->forceFill(['revealed_at' => now()])->save();
        }
    }
}

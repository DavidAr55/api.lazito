<?php

namespace App\Models;

use Database\Factories\ExclusionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pair of participants who must not draw each other (e.g. a couple), in either direction.
 */
class Exclusion extends Model
{
    /** @use HasFactory<ExclusionFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['participant_a_id', 'participant_b_id'];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Attributes for a pair, with the ids sorted so (A, B) and (B, A) are the same row.
     *
     * @return array{participant_a_id: string, participant_b_id: string}
     */
    public static function pairAttributes(string $first, string $second): array
    {
        [$a, $b] = $first < $second ? [$first, $second] : [$second, $first];

        return ['participant_a_id' => $a, 'participant_b_id' => $b];
    }
}

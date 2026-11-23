<?php

namespace App\Models;

use Database\Factories\FixedAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A draw result the organizer decided by hand (a "joke rule"): the giver always gives to the receiver.
 */
class FixedAssignment extends Model
{
    /** @use HasFactory<FixedAssignmentFactory> */
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['giver_id', 'receiver_id'];

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}

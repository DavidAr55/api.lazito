<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Participant
 */
class ParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'claimed' => $this->isClaimed(),
            $this->mergeWhen($request->routeIs('v1.me.show', 'v1.groups.admin.*'), [
                'revealed' => $this->revealed_at !== null,
            ]),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'event_date' => $this->event_date->toDateString(),
            'budget' => $this->budget,
            'theme' => $this->theme,
            'theme_description' => $this->theme_description,
            'categories' => $this->categories->map(fn ($category): array => [
                'id' => $category->id,
                'label' => $category->label,
            ])->all(),
            'participants' => ParticipantResource::collection($this->participants),
            $this->mergeWhen($request->routeIs('v1.groups.admin.*'), fn (): array => [
                'drawn_at' => $this->drawn_at?->toIso8601String(),
                'can_redraw' => ! $this->participants->contains(fn ($participant): bool => $participant->revealed_at !== null),
                'fixed_assignments' => $this->resource->loadMissing('fixedAssignments')->fixedAssignments->map(fn ($fixed): array => [
                    'giver' => $fixed->giver_id,
                    'receiver' => $fixed->receiver_id,
                ])->all(),
                'exclusions' => $this->resource->loadMissing('exclusions')->exclusions->map(fn ($exclusion): array => [
                    'a' => $exclusion->participant_a_id,
                    'b' => $exclusion->participant_b_id,
                ])->all(),
            ]),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateFixedAssignmentsRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use DomainException;
use Illuminate\Http\JsonResponse;

class GroupAdminFixedAssignmentController extends Controller
{
    /**
     * Replaces the hand-picked pairs ("joke rules") and draws again, while nobody has seen their result.
     */
    public function update(UpdateFixedAssignmentsRequest $request, Group $group): GroupResource|JsonResponse
    {
        $pairs = collect($request->validated('fixed_assignments'))
            ->map(fn (array $pair): array => [$pair['giver'], $pair['receiver']])
            ->all();

        try {
            $applied = $group->applyRulesAndRedraw(fixedAssignments: $pairs);
        } catch (DomainException) {
            return response()->json([
                'message' => 'Con esas reglas no encontramos un sorteo posible. Quita alguna e intenta de nuevo.',
                'code' => 'NO_VALID_DRAW',
            ], 422);
        }

        if (! $applied) {
            return response()->json([
                'message' => 'No se pueden cambiar las reglas: alguien ya vio su resultado.',
                'code' => 'ALREADY_REVEALED',
            ], 409);
        }

        return new GroupResource($group->load(['categories', 'participants']));
    }
}

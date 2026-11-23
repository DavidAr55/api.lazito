<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;

class GroupAdminDrawController extends Controller
{
    /**
     * Draws again, as long as nobody has seen their result yet.
     */
    public function store(Group $group): GroupResource|JsonResponse
    {
        if (! $group->redrawIfNobodyRevealed()) {
            return response()->json([
                'message' => 'No se puede volver a sortear: alguien ya vio su resultado.',
                'code' => 'ALREADY_REVEALED',
            ], 409);
        }

        return new GroupResource($group->load(['categories', 'participants']));
    }
}

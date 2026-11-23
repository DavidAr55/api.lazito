<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGroupRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    /**
     * Creates the group, draws the assignments and returns the one-time admin token.
     */
    public function store(StoreGroupRequest $request): JsonResponse
    {
        $adminToken = Str::random(40);

        try {
            $group = Group::openWithDraw($request->validated(), $adminToken);
        } catch (DomainException) {
            return response()->json([
                'message' => 'Con esas reglas no encontramos un sorteo posible. Quita alguna e intenta de nuevo.',
                'code' => 'NO_VALID_DRAW',
            ], 422);
        }

        return response()->json([
            'data' => ['slug' => $group->slug, 'admin_token' => $adminToken],
        ], 201);
    }

    public function show(Group $group): GroupResource
    {
        return new GroupResource($group->load(['categories', 'participants']));
    }
}

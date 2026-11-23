<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateGroupRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

class GroupAdminController extends Controller
{
    public function show(Group $group): GroupResource
    {
        return new GroupResource($group->load(['categories', 'participants']));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        DB::transaction(function () use ($request, $group): void {
            $group->update($request->safe()->except('categories'));

            if ($request->has('categories')) {
                $group->syncCategories($request->validated('categories') ?? []);
            }
        });

        return new GroupResource($group->load(['categories', 'participants']));
    }
}

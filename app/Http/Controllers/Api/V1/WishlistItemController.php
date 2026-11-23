<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WishlistItemRequest;
use App\Http\Resources\Api\V1\WishlistItemResource;
use App\Models\Group;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The authenticated participant's own wishlist.
 */
class WishlistItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return WishlistItemResource::collection($request->attributes->get('participant')->wishlistItems);
    }

    public function store(WishlistItemRequest $request): JsonResponse|WishlistItemResource
    {
        $participant = $request->attributes->get('participant');

        if ($participant->wishlistItems()->count() >= WishlistItem::MAXIMUM_PER_PARTICIPANT) {
            return response()->json([
                'message' => 'Tu wishlist está llena. Quita un producto para agregar otro.',
                'code' => 'WISHLIST_FULL',
            ], 422);
        }

        $item = $participant->wishlistItems()->create($request->validated());

        return (new WishlistItemResource($item))->response()->setStatusCode(201);
    }

    public function update(WishlistItemRequest $request, Group $group, WishlistItem $item): WishlistItemResource
    {
        $this->ensureOwnedBy($request, $item);
        $item->update($request->validated());

        return new WishlistItemResource($item);
    }

    public function destroy(Request $request, Group $group, WishlistItem $item): Response
    {
        $this->ensureOwnedBy($request, $item);
        $item->delete();

        return response()->noContent();
    }

    /**
     * Someone else's item answers 404 so its existence is not revealed.
     */
    private function ensureOwnedBy(Request $request, WishlistItem $item): void
    {
        abort_unless($item->participant_id === $request->attributes->get('participant')->getKey(), 404);
    }
}

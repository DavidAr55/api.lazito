<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WishlistNoteRequest;
use Illuminate\Http\JsonResponse;

/**
 * The general comment on the authenticated participant's own wishlist (e.g. "talla chica, sin perfume").
 */
class WishlistNoteController extends Controller
{
    public function update(WishlistNoteRequest $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $participant->forceFill(['wishlist_note' => $request->validated('note')])->save();

        return response()->json(['data' => ['note' => $participant->wishlist_note]]);
    }
}

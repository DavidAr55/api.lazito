<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WishlistItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeAssignmentWishlistController extends Controller
{
    /**
     * The wishlist of the person the authenticated participant gives a gift to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $receiver = $request->attributes->get('participant')->receiver;

        return WishlistItemResource::collection($receiver->wishlistItems);
    }
}

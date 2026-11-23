<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WishlistItem
 */
class WishlistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'price' => $this->price,
            'url' => $this->url,
            'image_url' => $this->image_url,
            'note' => $this->note,
        ];
    }
}

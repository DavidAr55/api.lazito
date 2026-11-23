<?php

namespace App\Http\Resources\Api\V1;

use App\Services\ProductSearch\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'price' => $this->price,
            'image_url' => $this->imageUrl,
            'url' => $this->url,
            'store' => $this->store,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lets a participant upload a photo (e.g. a screenshot) instead of linking to one.
 */
class WishlistImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'image.required' => 'Elige una imagen.',
            'image.file' => 'Elige una imagen válida.',
            'image.uploaded' => 'No pudimos subir la imagen. Prueba con una de menos de 5 MB.',
            'image.mimes' => 'La imagen debe ser JPG, PNG, WebP o GIF.',
            'image.max' => 'La imagen puede pesar hasta 5 MB.',
        ]);

        $path = $request->file('image')->store('wishlist', 'public');

        return response()->json(['data' => ['url' => Storage::disk('public')->url($path)]], 201);
    }
}

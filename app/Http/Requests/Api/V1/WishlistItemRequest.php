<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WishlistItemRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Group $group */
        $group = $this->route('group');

        return [
            'title' => ['required', 'string', 'max:100'],
            // A category is an optional label; when given it must belong to this group.
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('group_id', $group->getKey()),
            ],
            'price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Escribe el nombre del producto.',
            'title.max' => 'El nombre puede tener hasta 100 caracteres.',
            'category_id.exists' => 'Esa categoría no es parte de este intercambio.',
            'price.integer' => 'El precio debe ser un número entero de pesos.',
            'price.min' => 'El precio no puede ser negativo.',
            'price.max' => 'El precio no puede pasar de $1,000,000.',
            'url.url' => 'El enlace debe empezar con http:// o https://.',
            'image_url.url' => 'El enlace de la imagen debe empezar con http:// o https://.',
            'url.max' => 'El enlace es demasiado largo (máximo 2,048 caracteres).',
            'image_url.max' => 'El enlace de la imagen es demasiado largo (máximo 2,048 caracteres).',
            'note.max' => 'La nota puede tener hasta 200 caracteres.',
        ];
    }
}

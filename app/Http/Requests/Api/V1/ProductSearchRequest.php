<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Controllers\Api\V1\ProductSearchController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductSearchRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:1000000', Rule::when($this->filled('max_price'), 'lte:max_price')],
            'max_price' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'audience' => ['nullable', Rule::in(array_keys(ProductSearchController::AUDIENCES))],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.ProductSearchController::MAX_PAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'Escribe qué quieres buscar.',
            'q.min' => 'Escribe al menos 2 letras.',
            'q.max' => 'La búsqueda puede tener hasta 80 caracteres.',
            'min_price.integer' => 'El precio mínimo debe ser un número entero de pesos.',
            'min_price.min' => 'El precio mínimo no puede ser negativo.',
            'min_price.lte' => 'El precio mínimo no puede ser mayor que el máximo.',
            'audience.in' => 'Elige hombre, mujer o niños.',
            'max_price.integer' => 'El precio máximo debe ser un número entero de pesos.',
            'max_price.min' => 'El precio máximo debe ser mayor a $0.',
            'page.integer' => 'La página debe ser un número.',
            'page.min' => 'La página debe ser 1 o más.',
            'page.max' => 'No hay más resultados para esta búsqueda.',
        ];
    }
}

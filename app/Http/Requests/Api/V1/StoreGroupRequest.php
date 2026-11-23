<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'budget' => ['required', 'integer', 'min:1', 'max:1000000'],
            'theme' => ['required', 'string', 'max:60'],
            'theme_description' => ['nullable', 'string', 'max:300'],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'participants' => ['required', 'array', 'min:'.Group::MINIMUM_PARTICIPANTS, 'max:50'],
            'participants.*' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'exclusions' => ['nullable', 'array', 'max:50'],
            'exclusions.*.a' => ['required', 'string', Rule::in($this->input('participants', []))],
            'exclusions.*.b' => ['required', 'string', 'different:exclusions.*.a', Rule::in($this->input('participants', []))],
            'fixed_assignments' => ['nullable', 'array', 'max:50'],
            'fixed_assignments.*.giver' => ['required', 'string', 'distinct:ignore_case', Rule::in($this->input('participants', []))],
            'fixed_assignments.*.receiver' => ['required', 'string', 'distinct:ignore_case', 'different:fixed_assignments.*.giver', Rule::in($this->input('participants', []))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe un nombre para el intercambio.',
            'name.max' => 'El nombre puede tener hasta 80 caracteres.',
            'event_date.required' => 'Elige la fecha del intercambio.',
            'event_date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'event_date.after_or_equal' => 'La fecha ya pasó. Elige una futura.',
            'budget.required' => 'Escribe un presupuesto mayor a $0.',
            'budget.integer' => 'El presupuesto debe ser un número entero de pesos.',
            'budget.min' => 'Escribe un presupuesto mayor a $0.',
            'budget.max' => 'El presupuesto no puede pasar de $1,000,000.',
            'theme.required' => 'Escribe la temática, por ejemplo "Pijamas".',
            'theme.max' => 'La temática puede tener hasta 60 caracteres.',
            'theme_description.max' => 'Los detalles pueden tener hasta 300 caracteres.',
            'categories.max' => 'Puedes agregar hasta 20 categorías.',
            'categories.*.max' => 'Cada categoría puede tener hasta 40 caracteres.',
            'categories.*.distinct' => 'Hay categorías repetidas.',
            'participants.required' => 'Agrega a los participantes.',
            'participants.min' => 'Se necesitan al menos '.Group::MINIMUM_PARTICIPANTS.' participantes.',
            'participants.max' => 'El intercambio admite hasta 50 participantes.',
            'participants.*.required' => 'Todos los participantes necesitan un nombre.',
            'participants.*.max' => 'Cada nombre puede tener hasta 60 caracteres.',
            'participants.*.distinct' => 'Hay nombres repetidos. Agrega un apellido para distinguirlos.',
            'exclusions.max' => 'Puedes agregar hasta 50 exclusiones.',
            'exclusions.*.a.in' => 'Una exclusión menciona a alguien que no participa.',
            'exclusions.*.b.in' => 'Una exclusión menciona a alguien que no participa.',
            'exclusions.*.b.different' => 'Una exclusión necesita dos personas distintas.',
            'fixed_assignments.max' => 'Puedes agregar hasta 50 reglas de broma.',
            'fixed_assignments.*.giver.in' => 'Una regla de broma menciona a alguien que no participa.',
            'fixed_assignments.*.giver.distinct' => 'Alguien tiene más de una regla de broma: solo puede regalarle a una persona.',
            'fixed_assignments.*.receiver.in' => 'Una regla de broma menciona a alguien que no participa.',
            'fixed_assignments.*.receiver.distinct' => 'Alguien sería regalado por más de una persona: solo una puede regalarle.',
            'fixed_assignments.*.receiver.different' => 'Nadie puede regalarse a sí mismo.',
        ];
    }
}

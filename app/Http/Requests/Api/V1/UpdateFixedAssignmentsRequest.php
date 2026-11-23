<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFixedAssignmentsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Group $group */
        $group = $this->route('group');
        $belongsToGroup = Rule::exists('participants', 'id')->where('group_id', $group->getKey());

        return [
            'fixed_assignments' => ['present', 'array', 'max:50'],
            'fixed_assignments.*.giver' => ['required', 'string', 'distinct', $belongsToGroup],
            'fixed_assignments.*.receiver' => ['required', 'string', 'distinct', 'different:fixed_assignments.*.giver', $belongsToGroup],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fixed_assignments.present' => 'Envía la lista de reglas, aunque esté vacía.',
            'fixed_assignments.max' => 'Puedes agregar hasta 50 reglas de broma.',
            'fixed_assignments.*.giver.exists' => 'Una regla de broma menciona a alguien que no participa.',
            'fixed_assignments.*.giver.distinct' => 'Alguien tiene más de una regla de broma: solo puede regalarle a una persona.',
            'fixed_assignments.*.receiver.exists' => 'Una regla de broma menciona a alguien que no participa.',
            'fixed_assignments.*.receiver.distinct' => 'Alguien sería regalado por más de una persona: solo una puede regalarle.',
            'fixed_assignments.*.receiver.different' => 'Nadie puede regalarse a sí mismo.',
        ];
    }
}

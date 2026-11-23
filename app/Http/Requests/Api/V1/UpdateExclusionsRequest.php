<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExclusionsRequest extends FormRequest
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
            'exclusions' => ['present', 'array', 'max:50'],
            'exclusions.*.a' => ['required', 'string', $belongsToGroup],
            'exclusions.*.b' => ['required', 'string', 'different:exclusions.*.a', $belongsToGroup],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exclusions.present' => 'Envía la lista de exclusiones, aunque esté vacía.',
            'exclusions.max' => 'Puedes agregar hasta 50 exclusiones.',
            'exclusions.*.a.exists' => 'Una exclusión menciona a alguien que no participa.',
            'exclusions.*.b.exists' => 'Una exclusión menciona a alguien que no participa.',
            'exclusions.*.b.different' => 'Una exclusión necesita dos personas distintas.',
        ];
    }
}

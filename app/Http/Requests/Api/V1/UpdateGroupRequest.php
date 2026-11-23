<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class UpdateGroupRequest extends StoreGroupRequest
{
    /**
     * Same fields as creating, without participants. A date that is already stored may stay even if it passed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = Arr::except(parent::rules(), ['participants', 'participants.*', 'exclusions', 'exclusions.*.a', 'exclusions.*.b', 'fixed_assignments', 'fixed_assignments.*.giver', 'fixed_assignments.*.receiver']);

        if ($this->input('event_date') === $this->route('group')->event_date->toDateString()) {
            $rules['event_date'] = ['required', 'date_format:Y-m-d'];
        }

        return $rules;
    }
}

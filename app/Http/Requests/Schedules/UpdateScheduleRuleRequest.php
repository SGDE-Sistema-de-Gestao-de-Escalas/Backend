<?php

namespace App\Http\Requests\Schedules;

class UpdateScheduleRuleRequest extends StoreScheduleRuleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as $field => &$constraints) {
            if (! str_contains($field, '*')) {
                array_unshift($constraints, 'sometimes');
            }
        }

        return $rules;
    }
}

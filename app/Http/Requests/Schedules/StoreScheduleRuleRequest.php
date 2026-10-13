<?php

namespace App\Http\Requests\Schedules;

use App\Services\ScheduleRuleService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScheduleRuleRequest extends ScheduleRuleRequest
{
    public function rules(): array
    {
        $schoolId = $this->attributes->get('school_id');

        $rules = [
            'activity_type_id' => ['required', 'uuid', Rule::exists('activity_types', 'id')->where('school_id', $schoolId)->where('active', true)],
            'period_start' => ['required', 'date_format:H:i'],
            'period_end' => ['required', 'date_format:H:i'],
            'min' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'type' => ['required', Rule::in(['mandatory', 'optional'])],
            'start' => ['required', 'date_format:Y-m-d'],
            'days_of_week' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'days_of_week.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'assistant_ids' => ['required', 'array', 'list', 'min:1'],
            'assistant_ids.*' => [
                'required', 'uuid', 'distinct:ignore_case',
                Rule::exists('assistants', 'id')->where(function ($query) use ($schoolId) {
                    $query->where('school_id', $schoolId)->whereNull('deleted_at')
                        ->where(function ($query) {
                            $query->where('is_anonymized', false)->orWhereNull('is_anonymized');
                        })
                        ->whereIn('user_id', function ($query) {
                            $query->select('id')->from('users')->where('is_active', true)
                                ->whereNull('deleted_at')->whereNull('anonymized_at');
                        });
                }),
            ],
        ];

        $rules['substitute_assistant_id'] = ['prohibited'];

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rule = $this->route('schedule_rule');
            $start = $this->input('period_start', $rule?->period_start);
            $end = $this->input('period_end', $rule?->period_end);
            if (substr($end, 0, 5) <= substr($start, 0, 5)) {
                $validator->errors()->add('period_end', 'O fim do período deve ser posterior ao início.');
            }

            $ids = $this->input('assistant_ids', $rule?->assistants()->pluck('assistants.id')->all() ?? []);
            $type = $this->input('type', $rule?->type);
            foreach (ScheduleRuleService::selectionErrors($type, (int) $this->input('min', $rule?->min), $ids) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }
}

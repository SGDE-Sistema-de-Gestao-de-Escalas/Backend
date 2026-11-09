<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateAssistantScheduleProfileRequest extends StoreAssistantScheduleProfileRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            array_diff_key(parent::rules(), ['assistant_id' => true]),
            [
                'type' => ['sometimes', 'required', 'in:fixo,rotativo'],
                'valid_from' => ['sometimes', 'required', 'date'],
                'shifts' => ['sometimes', 'required', 'array', 'min:1'],
            ]
        );
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $profile = $this->route('assistant_schedule_profile');
            if (! $profile) {
                return;
            }

            $service = app(\App\Services\AssistantScheduleProfileService::class);
            if (! $service->canEdit($profile)) {
                $validator->errors()->add('profile', 'Não é possível editar um horário que já regeu escalas passadas.');
            }
        });
    }
}

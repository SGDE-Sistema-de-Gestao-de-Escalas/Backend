<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssistantScheduleProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assistant_id' => ['required', 'uuid', 'exists:assistants,id'],
            'type' => ['required', 'in:fixo,rotativo'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'rotation_period' => ['nullable', 'required_if:type,rotativo', 'in:weekly,biweekly,monthly'],
            'starts_with' => ['nullable', 'required_if:type,rotativo', 'in:A,B'],
            'shifts' => ['required', 'array', 'min:1'],
            'shifts.*.shift_label' => ['required', 'in:A,B'],
            'shifts.*.entry_time' => ['required', 'date_format:H:i'],
            'shifts.*.exit_time' => ['required', 'date_format:H:i'],
            'shifts.*.lunch_enabled' => ['sometimes', 'boolean'],
            'shifts.*.lunch_start' => ['nullable', 'date_format:H:i'],
            'shifts.*.lunch_end' => ['nullable', 'date_format:H:i'],
            'shifts.*.lunch_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'shifts.*.days' => ['required', 'array', 'min:1'],
            'shifts.*.days.*' => ['integer', 'between:1,7'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assistant_id.required' => 'O assistente é obrigatório.',
            'assistant_id.uuid' => 'O assistente selecionado é inválido.',
            'assistant_id.exists' => 'O assistente selecionado não existe.',
            'type.required' => 'O tipo de perfil de horário é obrigatório.',
            'type.in' => 'O tipo de perfil de horário tem de ser fixo ou rotativo.',
            'valid_from.required' => 'A data de início de validade do horário é obrigatória.',
            'valid_from.date' => 'A data de início de validade do horário é inválida.',
            'valid_until.date' => 'A data de fim de validade do horário é inválida.',
            'valid_until.after_or_equal' => 'A data de fim de validade tem de ser posterior ou igual à data de início.',
            'rotation_period.required_if' => 'O período de rotação é obrigatório para horários rotativos.',
            'rotation_period.in' => 'O período de rotação tem de ser semanal, quinzenal ou mensal.',
            'starts_with.required_if' => 'O turno inicial é obrigatório para horários rotativos.',
            'starts_with.in' => 'O turno inicial tem de ser A ou B.',
            'shifts.required' => 'É necessário definir pelo menos um turno.',
            'shifts.min' => 'É necessário definir pelo menos um turno.',
            'shifts.*.shift_label.required' => 'A identificação do turno (A/B) é obrigatória.',
            'shifts.*.shift_label.in' => 'A identificação do turno tem de ser A ou B.',
            'shifts.*.entry_time.required' => 'A hora de entrada do turno é obrigatória.',
            'shifts.*.entry_time.date_format' => 'A hora de entrada deve ter o formato HH:mm.',
            'shifts.*.exit_time.required' => 'A hora de saída do turno é obrigatória.',
            'shifts.*.exit_time.date_format' => 'A hora de saída deve ter o formato HH:mm.',
            'shifts.*.days.required' => 'Selecione pelo menos um dia da semana para o turno.',
            'shifts.*.days.min' => 'Selecione pelo menos um dia da semana para o turno.',
            'shifts.*.days.*.between' => 'O dia da semana selecionado é inválido (deve ser entre 1 e 7).',
        ];
    }
}

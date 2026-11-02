<?php

namespace App\Http\Requests\Assistants;

use App\Models\Assistants\Assistant;
use App\Services\AssistantExceptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreAssistantExceptionRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assistant_id' => 'required|uuid|exists:assistants,id',
            'type' => 'required|string|max:255',
            'description' => 'nullable|string',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'days' => 'nullable|array',
            'days.*' => 'integer|between:0,6',
            'lunch_enabled' => 'sometimes|boolean',
            'lunch_start' => 'nullable|date_format:H:i',
            'lunch_end' => 'nullable|date_format:H:i|after:lunch_start',
            'lunch_duration_minutes' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schoolId = $this->header('X-School-ID');

            if ($this->filled('assistant_id') && $schoolId) {
                $assistant = Assistant::find($this->assistant_id);
                if ($assistant && (string) $assistant->school_id !== (string) $schoolId) {
                    $validator->errors()->add('assistant_id', 'O assistente selecionado não pertence a esta escola.');

                    return;
                }
            }

            if ($this->filled('assistant_id') && $this->filled('valid_from')) {
                $assistant = Assistant::find($this->assistant_id);
                if ($assistant) {
                    try {
                        app(AssistantExceptionService::class)->validateAgainstSchoolCalendar($assistant->school_id, $this->all());
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $key => $messages) {
                            foreach ($messages as $msg) {
                                $validator->errors()->add($key, $msg);
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assistant_id.required' => 'Por favor, selecione um assistente.',
            'assistant_id.uuid' => 'O assistente selecionado é inválido.',
            'assistant_id.exists' => 'O assistente selecionado não foi encontrado.',
            'type.required' => 'O tipo de exceção é obrigatório.',
            'type.max' => 'O tipo de exceção não pode ter mais de 255 carateres.',
            'valid_from.required' => 'A data de início é obrigatória.',
            'valid_from.date' => 'A data de início introduzida não é válida.',
            'valid_until.date' => 'A data de fim introduzida não é válida.',
            'valid_until.after_or_equal' => 'A data de fim tem de ser igual ou posterior à data de início.',
            'start_time.date_format' => 'A hora de início tem de estar no formato HH:mm (ex: 09:30).',
            'end_time.date_format' => 'A hora de fim tem de estar no formato HH:mm (ex: 17:30).',
            'end_time.after' => 'A hora de fim tem de ser posterior à hora de início.',
            'days.array' => 'Os dias da semana devem ser indicados como uma lista.',
            'days.*.integer' => 'Cada dia da semana tem de ser um número inteiro.',
            'days.*.between' => 'O dia da semana tem de ser entre 0 (Segunda) e 6 (Domingo).',
            'lunch_enabled.boolean' => 'O campo de pausa de almoço tem de ser verdadeiro ou falso.',
            'lunch_start.date_format' => 'A hora de início de almoço tem de estar no formato HH:mm.',
            'lunch_end.date_format' => 'A hora de fim de almoço tem de estar no formato HH:mm.',
            'lunch_end.after' => 'A hora de fim de almoço tem de ser posterior à hora de início de almoço.',
            'lunch_duration_minutes.integer' => 'A duração do almoço em minutos tem de ser um número inteiro.',
            'lunch_duration_minutes.min' => 'A duração do almoço tem de ser de pelo menos 0 minutos.',
        ];
    }
}

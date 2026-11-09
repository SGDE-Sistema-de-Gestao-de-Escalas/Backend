<?php

namespace App\Http\Requests\Absences;

use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreAbsenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Apenas administradores podem criar ausências.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Absences\Absence::class) ?? false;
    }

    /**
     * Uma lista no lugar da data de início trata-se como em falta: a regra
     * "after_or_equal" rebenta com um TypeError se receber uma lista.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('valid_from') && ! is_string($this->input('valid_from'))) {
            $this->merge(['valid_from' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assistant_id' => ['required', 'uuid', 'exists:assistants,id'],
            'absence_type_id' => ['required', 'uuid', 'exists:absence_types,id'],
            'valid_from' => ['required', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from', 'before_or_equal:2100-12-31'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'total_days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:pending,justified,unjustified'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], // Máximo 5MB
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schoolId = $this->header('X-School-ID');

            if (is_string($this->input('assistant_id')) && $this->filled('assistant_id') && $schoolId) {
                $assistant = Assistant::find($this->assistant_id);
                if ($assistant && (string) $assistant->school_id !== (string) $schoolId) {
                    $validator->errors()->add('assistant_id', 'O assistente selecionado não pertence a esta escola.');
                }
            }

            if ($this->filled('valid_until') && ! $validator->errors()->hasAny(['valid_from', 'valid_until'])) {
                $from = Carbon::parse(substr((string) $this->input('valid_from'), 0, 10), 'UTC')->startOfDay();
                $until = Carbon::parse(substr((string) $this->input('valid_until'), 0, 10), 'UTC')->startOfDay();

                if ($from->diffInDays($until) > 365) {
                    $validator->errors()->add('valid_until', 'Uma falta não pode ter mais de um ano de duração.');
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
            'assistant_id.required' => 'O assistente é obrigatório.',
            'assistant_id.uuid' => 'O identificador do assistente é inválido.',
            'assistant_id.exists' => 'O assistente selecionado não existe.',
            'absence_type_id.required' => 'O tipo de ausência é obrigatório.',
            'absence_type_id.uuid' => 'O identificador do tipo de ausência é inválido.',
            'absence_type_id.exists' => 'O tipo de ausência selecionado não existe.',
            'valid_from.required' => 'A data de início da ausência é obrigatória.',
            'valid_from.date' => 'A data de início da ausência é inválida.',
            'valid_from.after_or_equal' => 'A data de início não pode ser anterior a 2000.',
            'valid_from.before_or_equal' => 'A data de início não pode ser posterior a 2100.',
            'valid_until.required' => 'A data de fim da ausência é obrigatória.',
            'valid_until.date' => 'A data de fim da ausência é inválida.',
            'valid_until.after_or_equal' => 'A data de fim tem de ser posterior ou igual à data de início.',
            'valid_until.before_or_equal' => 'A data de fim não pode ser posterior a 2100.',
            'total_days.max' => 'O número de dias não pode ser superior a 366.',
            'start_time.date_format' => 'A hora de início deve seguir o formato HH:mm.',
            'end_time.date_format' => 'A hora de fim deve seguir o formato HH:mm.',
            'status.in' => 'O estado da ausência é inválido.',
            'document.file' => 'O documento comprovativo deve ser um ficheiro válido.',
            'document.mimes' => 'O documento comprovativo deve estar no formato PDF, JPG, JPEG ou PNG.',
            'document.max' => 'O documento comprovativo não pode ter mais de 5MB.',
        ];
    }
}

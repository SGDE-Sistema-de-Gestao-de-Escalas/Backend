<?php

namespace App\Http\Requests\Absences;

use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class StoreAbsenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Apenas administradores podem criar ausências.
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
            'assistant_id' => ['required', 'uuid', 'exists:assistants,id'],
            'absence_type_id' => ['required', 'uuid', 'exists:absence_types,id'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'total_days' => ['nullable', 'integer', 'min:1'],
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

            if ($this->filled('assistant_id') && $schoolId) {
                $assistant = Assistant::find($this->assistant_id);
                if ($assistant && (string) $assistant->school_id !== (string) $schoolId) {
                    $validator->errors()->add('assistant_id', 'O assistente selecionado não pertence a esta escola.');
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
            'valid_until.date' => 'A data de fim da ausência é inválida.',
            'valid_until.after_or_equal' => 'A data de fim tem de ser posterior ou igual à data de início.',
            'start_time.date_format' => 'A hora de início deve seguir o formato HH:mm.',
            'end_time.date_format' => 'A hora de fim deve seguir o formato HH:mm.',
            'status.in' => 'O estado da ausência é inválido.',
            'document.file' => 'O documento comprovativo deve ser um ficheiro válido.',
            'document.mimes' => 'O documento comprovativo deve estar no formato PDF, JPG, JPEG ou PNG.',
            'document.max' => 'O documento comprovativo não pode ter mais de 5MB.',
        ];
    }
}

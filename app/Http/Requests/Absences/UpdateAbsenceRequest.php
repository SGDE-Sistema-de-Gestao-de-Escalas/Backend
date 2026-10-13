<?php

namespace App\Http\Requests\Absences;

use App\Models\Absences\AbsenceType;
use App\Models\Assistants\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAbsenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $absence = $this->route('absence');

        return $absence && $absence->assistant?->user_id === $user->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assistant_id' => ['sometimes', 'required', 'uuid', 'exists:assistants,id'],
            'absence_type_id' => ['sometimes', 'required', 'uuid', 'exists:absence_types,id'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'total_days' => ['nullable', 'integer', 'min:1'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', 'in:pending,justified,unjustified'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'remove_document' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $schoolId = $this->header('X-School-ID');
            $absence = $this->route('absence');

            // Assistente só pode enviar documento, remove_document, notas ou justificativa
            if ($user && ! $user->isAdmin()) {
                $forbiddenFields = [
                    'status' => 'Apenas os administradores podem alterar o estado da ausência.',
                    'assistant_id' => 'Não tem permissão para alterar o assistente da ausência.',
                    'absence_type_id' => 'Não tem permissão para alterar o tipo de ausência.',
                    'valid_from' => 'Não tem permissão para alterar as datas da ausência.',
                    'valid_until' => 'Não tem permissão para alterar as datas da ausência.',
                    'start_time' => 'Não tem permissão para alterar as horas da ausência.',
                    'end_time' => 'Não tem permissão para alterar as horas da ausência.',
                    'total_days' => 'Não tem permissão para alterar a duração da ausência.',
                ];

                foreach ($forbiddenFields as $field => $errorMessage) {
                    if ($this->has($field)) {
                        $validator->errors()->add($field, $errorMessage);
                    }
                }
            }

            if ($this->filled('assistant_id') && $schoolId) {
                $assistant = Assistant::find($this->assistant_id);
                if ($assistant && (string) $assistant->school_id !== (string) $schoolId) {
                    $validator->errors()->add('assistant_id', 'O assistente selecionado não pertence a esta escola.');
                }
            }

            $typeId = $this->input('absence_type_id', $absence?->absence_type_id);

            if ($typeId) {
                $absenceType = AbsenceType::find($typeId);
                $targetStatus = $this->input('status', $absence?->status);

                if ($absenceType && $absenceType->requires_document && $targetStatus === 'justified') {
                    $hasExistingDoc = $absence?->document_path && ! $this->boolean('remove_document');
                    $hasNewDoc = $this->hasFile('document');

                    if (! $hasExistingDoc && ! $hasNewDoc) {
                        $validator->errors()->add('document', 'Este tipo de ausência exige a apresentação de documento comprovativo para ser justificada.');
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

<?php

namespace App\Http\Requests\Assistants;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssistantExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
        ];
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
        ];
    }
}

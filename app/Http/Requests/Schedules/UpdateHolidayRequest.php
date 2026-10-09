<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holiday')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($holiday)],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do feriado é obrigatório.',
            'name.string' => 'O nome do feriado deve ser um texto.',
            'name.max' => 'O nome do feriado não pode exceder 255 caracteres.',
            'date.required' => 'A data do feriado é obrigatória.',
            'date.date_format' => 'A data deve estar no formato AAAA-MM-DD.',
            'date.unique' => 'Já existe um feriado registado nesta data.',
            'description.string' => 'A descrição deve ser um texto.',
        ];
    }
}

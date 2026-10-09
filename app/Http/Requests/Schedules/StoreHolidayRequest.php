<?php

namespace App\Http\Requests\Schedules;

use App\Models\Schedules\Holiday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Holiday::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'],
            'description' => ['nullable', 'string'],
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

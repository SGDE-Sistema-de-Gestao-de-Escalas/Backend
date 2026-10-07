<?php

namespace App\Http\Requests\Absences;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAbsenceTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('absence_type')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $absenceType = $this->route('absence_type');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('absence_types', 'name')->ignore($absenceType)],
            'requires_document' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Schools;

use App\Rules\NoHtmlCharacters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolOperatingExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('operating_exception')) ?? false;
    }

    /**
     * A escola de uma exceção não se altera, por isso `school_id` não é
     * aceite. Os campos não enviados mantêm-se. Se, depois de aplicadas as
     * alterações, a escola não fechar, as horas têm de estar todas preenchidas
     * (verificado no SchoolOperatingExceptionService, que também aplica as
     * regras da fase da exceção: futura, em curso ou terminada).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', new NoHtmlCharacters],
            'is_closed' => ['sometimes', 'required', 'boolean'],
            'opening_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'closing_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'lunch_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'lunch_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'lunch_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'valid_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return SchoolOperatingExceptionMessages::all();
    }
}

<?php

namespace App\Http\Requests\Schools;

use App\Models\Schools\SchoolOperatingException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchoolOperatingExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', SchoolOperatingException::class) ?? false;
    }

    /**
     * A escola vem do cabeçalho X-School-ID (já validado pelo middleware
     * school.context), nunca do corpo do pedido.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['school_id' => $this->attributes->get('school_id')]);
    }

    /**
     * Formato campo a campo. As horas só são obrigatórias se a escola não
     * fechar. As regras que dependem de vários campos (ordem das horas,
     * datas, sobreposição) são validadas no SchoolOperatingExceptionService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $open = Rule::requiredIf(fn () => ! $this->boolean('is_closed'));

        return [
            'school_id' => ['required', 'uuid', 'exists:schools,id'],
            'name' => ['required', 'string', 'max:255'],
            'is_closed' => ['required', 'boolean'],
            'opening_time' => [$open, 'nullable', 'date_format:H:i'],
            'closing_time' => [$open, 'nullable', 'date_format:H:i'],
            'lunch_start' => [$open, 'nullable', 'date_format:H:i'],
            'lunch_end' => [$open, 'nullable', 'date_format:H:i'],
            'lunch_duration_minutes' => [$open, 'nullable', 'integer', 'min:1', 'max:1440'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return SchoolOperatingExceptionMessages::all();
    }
}

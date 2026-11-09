<?php

namespace App\Http\Requests\Schools;

use App\Rules\NoHtmlCharacters;
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
            'name' => ['required', 'string', 'max:255', new NoHtmlCharacters],
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
        return [
            'school_id.required' => 'Indique a escola através do cabeçalho X-School-ID.',
            'name.required' => 'O nome da exceção é obrigatório.',
            'name.string' => 'O nome da exceção deve ser texto.',
            'name.max' => 'O nome da exceção não pode ter mais de 255 caracteres.',
            'is_closed.required' => 'Indique se a escola fecha neste período.',
            'is_closed.boolean' => 'O campo "escola fechada" deve ser verdadeiro ou falso.',
            'opening_time.required' => 'A hora de abertura é obrigatória quando a escola não fecha.',
            'opening_time.date_format' => 'A hora de abertura deve estar no formato HH:MM.',
            'closing_time.required' => 'A hora de fecho é obrigatória quando a escola não fecha.',
            'closing_time.date_format' => 'A hora de fecho deve estar no formato HH:MM.',
            'lunch_start.required' => 'O início do almoço é obrigatório quando a escola não fecha.',
            'lunch_start.date_format' => 'O início do almoço deve estar no formato HH:MM.',
            'lunch_end.required' => 'O fim do almoço é obrigatório quando a escola não fecha.',
            'lunch_end.date_format' => 'O fim do almoço deve estar no formato HH:MM.',
            'lunch_duration_minutes.required' => 'A duração do almoço é obrigatória quando a escola não fecha.',
            'lunch_duration_minutes.integer' => 'A duração do almoço deve ser um número de minutos.',
            'lunch_duration_minutes.min' => 'A duração do almoço deve ser de pelo menos 1 minuto.',
            'lunch_duration_minutes.max' => 'A duração do almoço não pode exceder 1440 minutos.',
            'valid_from.required' => 'A data de início é obrigatória.',
            'valid_from.date_format' => 'A data de início deve estar no formato AAAA-MM-DD.',
            'valid_until.required' => 'A data de fim é obrigatória.',
            'valid_until.date_format' => 'A data de fim deve estar no formato AAAA-MM-DD.',
        ];
    }
}

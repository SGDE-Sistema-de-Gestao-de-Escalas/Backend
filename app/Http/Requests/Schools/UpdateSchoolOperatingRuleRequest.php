<?php

namespace App\Http\Requests\Schools;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolOperatingRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('operating_rule')) ?? false;
    }

    /**
     * A escola de uma versão não se altera, por isso `school_id` não é aceite.
     * Se `days` for enviado, substitui a lista inteira de dias. As validações
     * que dependem de vários campos e da fase da versão (já em vigor) são
     * feitas no SchoolOperatingRuleService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'valid_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.opening_time' => ['required', 'date_format:H:i'],
            'days.*.closing_time' => ['required', 'date_format:H:i'],
            'days.*.lunch_start' => ['required', 'date_format:H:i'],
            'days.*.lunch_end' => ['required', 'date_format:H:i'],
            'days.*.lunch_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    public function messages(): array
    {
        return [
            'valid_from.required' => 'A data de início é obrigatória.',
            'valid_from.date_format' => 'A data de início deve estar no formato AAAA-MM-DD.',
            'days.required' => 'Indique pelo menos um dia da semana.',
            'days.array' => 'Os dias da semana devem ser uma lista.',
            'days.min' => 'Indique pelo menos um dia da semana.',
            'days.max' => 'Não pode indicar mais de 7 dias da semana.',
            'days.*.day_of_week.required' => 'Indique o dia da semana.',
            'days.*.day_of_week.integer' => 'O dia da semana deve ser um número de 1 (segunda) a 7 (domingo).',
            'days.*.day_of_week.between' => 'O dia da semana deve ser um número de 1 (segunda) a 7 (domingo).',
            'days.*.day_of_week.distinct' => 'Há dias da semana repetidos.',
            'days.*.opening_time.required' => 'A hora de abertura é obrigatória.',
            'days.*.opening_time.date_format' => 'A hora de abertura deve estar no formato HH:MM.',
            'days.*.closing_time.required' => 'A hora de fecho é obrigatória.',
            'days.*.closing_time.date_format' => 'A hora de fecho deve estar no formato HH:MM.',
            'days.*.lunch_start.required' => 'O início do almoço é obrigatório.',
            'days.*.lunch_start.date_format' => 'O início do almoço deve estar no formato HH:MM.',
            'days.*.lunch_end.required' => 'O fim do almoço é obrigatório.',
            'days.*.lunch_end.date_format' => 'O fim do almoço deve estar no formato HH:MM.',
            'days.*.lunch_duration_minutes.required' => 'A duração do almoço é obrigatória.',
            'days.*.lunch_duration_minutes.integer' => 'A duração do almoço deve ser um número de minutos.',
            'days.*.lunch_duration_minutes.min' => 'A duração do almoço deve ser de pelo menos 1 minuto.',
            'days.*.lunch_duration_minutes.max' => 'A duração do almoço não pode exceder 1440 minutos.',
        ];
    }
}

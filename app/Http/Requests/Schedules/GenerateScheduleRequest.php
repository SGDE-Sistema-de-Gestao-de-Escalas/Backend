<?php

namespace App\Http\Requests\Schedules;

use Illuminate\Foundation\Http\FormRequest;

class GenerateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'preserve_locked' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'transfers' => ['nullable', 'array'],
            'transfers.*.assistant_id' => ['required', 'uuid', 'exists:assistants,id'],
            'transfers.*.date' => ['required', 'date_format:Y-m-d'],
            'transfers.*.rule_id' => ['nullable', 'uuid'],
            'transfers.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schoolId = $this->attributes->get('currentSchoolId') ?? $this->header('X-School-ID');
            if (! $schoolId) {
                $validator->errors()->add('school_id', 'O cabeçalho X-School-ID é obrigatório para gerar escalas.');
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');
            if ($start && $end) {
                $days = \Carbon\CarbonImmutable::parse($start)->diffInDays(\Carbon\CarbonImmutable::parse($end), false);
                if ($days > 31) {
                    $validator->errors()->add('end_date', 'O intervalo máximo de geração automática é de 31 dias.');
                }
            }
        });
    }
}

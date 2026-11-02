<?php

namespace App\Http\Requests\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->attributes->get('school_id')) {
                $validator->errors()->add('school_id', 'O cabeçalho X-School-ID é obrigatório.');
            }

            if (! $validator->errors()->has('start_date') && ! $validator->errors()->has('end_date')) {
                $days = CarbonImmutable::parse($this->input('start_date'))->diffInDays(CarbonImmutable::parse($this->input('end_date')));
                if ($days > 365) {
                    $validator->errors()->add('end_date', 'O intervalo não pode exceder 366 dias, incluindo as duas datas.');
                }
            }
        });
    }
}

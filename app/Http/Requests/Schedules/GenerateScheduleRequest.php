<?php

namespace App\Http\Requests\Schedules;

use App\Models\Assistants\Assistant;
use Carbon\CarbonImmutable;
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
            $schoolId = (string) ($this->attributes->get('currentSchoolId') ?? $this->header('X-School-ID'));
            if (! $schoolId) {
                $validator->errors()->add('school_id', 'O cabeçalho X-School-ID é obrigatório para gerar escalas.');
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');
            if ($start && $end) {
                $days = CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end), false);
                if ($days > 31) {
                    $validator->errors()->add('end_date', 'O intervalo máximo de geração automática é de 31 dias.');
                }
            }

            $transfers = $this->input('transfers');
            if (! empty($transfers) && is_array($transfers)) {
                $assistants = Assistant::whereIn(
                    'id',
                    array_filter(array_column($transfers, 'assistant_id'))
                )->get()->keyBy('id');

                foreach ($transfers as $index => $tr) {
                    $trDate = $tr['date'] ?? null;
                    if ($trDate && $start && $end) {
                        if ($trDate < $start || $trDate > $end) {
                            $validator->errors()->add(
                                "transfers.{$index}.date",
                                "A data da cedência ({$trDate}) deve estar dentro do intervalo a gerar ({$start} a {$end})."
                            );
                        }
                    }

                    $assistantId = $tr['assistant_id'] ?? null;
                    if ($assistantId && isset($assistants[$assistantId])) {
                        $assistant = $assistants[$assistantId];

                        if (! $assistant->available_for_transfer) {
                            $validator->errors()->add(
                                "transfers.{$index}.assistant_id",
                                "O assistente selecionado não se encontra disponível para transferência ou cedência temporária."
                            );
                        }

                        if ($schoolId && (string) $assistant->school_id === (string) $schoolId) {
                            $validator->errors()->add(
                                "transfers.{$index}.assistant_id",
                                "O assistente já pertence à escola onde a escala está a ser gerada."
                            );
                        }
                    }
                }
            }
        });
    }
}
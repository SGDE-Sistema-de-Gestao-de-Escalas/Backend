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

    /**
     * Datas que não sejam texto (listas, objetos) tratam-se como em falta: a regra
     * "after_or_equal" rebenta com um TypeError se receber uma lista.
     */
    protected function prepareForValidation(): void
    {
        foreach (['start_date', 'end_date'] as $field) {
            if ($this->has($field) && ! is_string($this->input($field))) {
                $this->merge([$field => null]);
            }
        }
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
            'transfers.*.start_time' => ['nullable', 'date_format:H:i'],
            'transfers.*.end_time' => ['nullable', 'date_format:H:i'],
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
            // Só calcula o intervalo se as duas datas já passaram nas regras
            // (o Carbon::parse rebenta com "abc" ou com o mês 13).
            if ($start && $end && ! $validator->errors()->hasAny(['start_date', 'end_date'])) {
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

                    $trStart = $tr['start_time'] ?? null;
                    $trEnd = $tr['end_time'] ?? null;
                    if (($trStart && ! $trEnd) || (! $trStart && $trEnd)) {
                        $validator->errors()->add(
                            "transfers.{$index}.start_time",
                            "Se for especificado um horário parcial, tanto a hora de início como a hora de fim são obrigatórias."
                        );
                    } elseif ($trStart && $trEnd && $trEnd <= $trStart) {
                        $validator->errors()->add(
                            "transfers.{$index}.end_time",
                            "A hora de fim da cedência deve ser posterior à hora de início."
                        );
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
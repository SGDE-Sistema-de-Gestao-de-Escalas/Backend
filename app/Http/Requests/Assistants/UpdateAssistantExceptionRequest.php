<?php

namespace App\Http\Requests\Assistants;

use App\Services\AssistantExceptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateAssistantExceptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'valid_from' => 'sometimes|required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'days' => 'nullable|array',
            'days.*' => 'integer|between:0,6',
            'lunch_enabled' => 'sometimes|boolean',
            'lunch_start' => 'nullable|date_format:H:i',
            'lunch_end' => 'nullable|date_format:H:i|after:lunch_start',
            'lunch_duration_minutes' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $exception = $this->route('assistant_exception');
            if (! $exception) {
                return;
            }

            $today = Carbon::today();
            $validFrom = $exception->valid_from ? Carbon::parse($exception->valid_from)->startOfDay() : null;
            $validUntil = $exception->valid_until ? Carbon::parse($exception->valid_until)->startOfDay() : null;

            // 1. Se a exceção for passada (valid_until < hoje)
            if ($validUntil && $validUntil->isBefore($today)) {
                $validator->errors()->add('valid_until', 'Não é possível editar uma exceção cuja vigência já terminou no passado.');

                return;
            }

            // 2. Se a exceção estiver em vigor (valid_from <= hoje e (valid_until >= hoje ou null))
            if ($validFrom && ! $validFrom->isAfter($today)) {
                $immutableFields = ['type', 'valid_from', 'start_time', 'end_time', 'days', 'lunch_enabled', 'lunch_start', 'lunch_end', 'lunch_duration_minutes'];
                foreach ($immutableFields as $field) {
                    if ($this->has($field)) {
                        $validator->errors()->add($field, 'Não é possível alterar os dados base ou data de início de uma exceção em vigor; apenas a data de fim pode ser ajustada.');
                    }
                }

                if ($this->filled('valid_until')) {
                    $newUntil = Carbon::parse($this->input('valid_until'))->startOfDay();
                    if ($newUntil->isBefore($today)) {
                        $validator->errors()->add('valid_until', 'A data de fim não pode ser anterior à data de hoje.');
                    }
                }
            }

            // 3. Validação contra o calendário da escola
            if ($exception->assistant) {
                $merged = array_merge([
                    'valid_from' => $exception->valid_from?->format('Y-m-d'),
                    'valid_until' => $exception->valid_until?->format('Y-m-d'),
                    'start_time' => $exception->start_time ? Carbon::parse($exception->start_time)->format('H:i') : null,
                    'end_time' => $exception->end_time ? Carbon::parse($exception->end_time)->format('H:i') : null,
                    'days' => $exception->days,
                    'lunch_enabled' => $exception->lunch_enabled,
                    'lunch_start' => $exception->lunch_start ? Carbon::parse($exception->lunch_start)->format('H:i') : null,
                    'lunch_end' => $exception->lunch_end ? Carbon::parse($exception->lunch_end)->format('H:i') : null,
                    'lunch_duration_minutes' => $exception->lunch_duration_minutes,
                    'type' => $exception->type,
                ], $this->all());

                try {
                    app(AssistantExceptionService::class)->validateAgainstSchoolCalendar($exception->assistant->school_id, $merged);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $msg) {
                            $validator->errors()->add($key, $msg);
                        }
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'O tipo de exceção é obrigatório.',
            'type.max' => 'O tipo de exceção não pode ter mais de 255 carateres.',
            'valid_from.required' => 'A data de início é obrigatória.',
            'valid_from.date' => 'A data de início introduzida não é válida.',
            'valid_until.date' => 'A data de fim introduzida não é válida.',
            'valid_until.after_or_equal' => 'A data de fim tem de ser igual ou posterior à data de início.',
            'start_time.date_format' => 'A hora de início tem de estar no formato HH:mm (ex: 09:30).',
            'end_time.date_format' => 'A hora de fim tem de estar no formato HH:mm (ex: 17:30).',
            'end_time.after' => 'A hora de fim tem de ser posterior à hora de início.',
            'days.array' => 'Os dias da semana devem ser indicados como uma lista.',
            'days.*.integer' => 'Cada dia da semana tem de ser um número inteiro.',
            'days.*.between' => 'O dia da semana tem de ser entre 0 (Segunda) e 6 (Domingo).',
            'lunch_enabled.boolean' => 'O campo de pausa de almoço tem de ser verdadeiro ou falso.',
            'lunch_start.date_format' => 'A hora de início de almoço tem de estar no formato HH:mm.',
            'lunch_end.date_format' => 'A hora de fim de almoço tem de estar no formato HH:mm.',
            'lunch_end.after' => 'A hora de fim de almoço tem de ser posterior à hora de início de almoço.',
            'lunch_duration_minutes.integer' => 'A duração do almoço em minutos tem de ser um número inteiro.',
            'lunch_duration_minutes.min' => 'A duração do almoço tem de ser de pelo menos 0 minutos.',
        ];
    }
}

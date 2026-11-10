<?php

namespace App\Http\Requests\Assistants;

use App\Services\Assistants\AssistantExceptionService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateAssistantExceptionRequest extends StoreAssistantExceptionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            array_diff_key(parent::rules(), ['assistant_id' => true]),
            [
                'type' => 'sometimes|required|string|max:255',
                'valid_from' => 'sometimes|required|date',
            ]
        );
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
            $validUntil = $exception->valid_until ? Carbon::parse($exception->valid_until)->startOfDay() : null;

            // 1. Se a exceção for passada (valid_until < hoje)
            if ($validUntil && $validUntil->isBefore($today)) {
                $validator->errors()->add('valid_until', 'Não é possível editar uma exceção cuja vigência já terminou no passado.');

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // 2. Validação contra o calendário da escola
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
}

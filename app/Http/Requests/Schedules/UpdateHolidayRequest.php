<?php

namespace App\Http\Requests\Schedules;

use App\Rules\NoHtmlCharacters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends StoreHolidayRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holiday')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', new NoHtmlCharacters],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($holiday)],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

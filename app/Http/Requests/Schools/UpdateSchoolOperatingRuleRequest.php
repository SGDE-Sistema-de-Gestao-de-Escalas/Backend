<?php

namespace App\Http\Requests\Schools;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateSchoolOperatingRuleRequest extends StoreSchoolOperatingRuleRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('operating_rule')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // A escola não é alterável na atualização.
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
        return array_merge(
            array_diff_key(parent::rules(), ['school_id' => true]),
            [
                'valid_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'days' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            ]
        );
    }
}

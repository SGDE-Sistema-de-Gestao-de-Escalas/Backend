<?php

namespace App\Http\Requests\System;

use App\Rules\NoHtmlCharacters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('activity_type')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A escola e o carácter predefinido não se alteram por aqui.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $activityType = $this->route('activity_type');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255', new NoHtmlCharacters,
                Rule::unique('activity_types', 'name')
                    ->where('school_id', $activityType->school_id)
                    ->ignore($activityType),
            ],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Esta escola já tem uma atividade com este nome.',
            'color.regex' => 'A cor deve estar no formato #RRGGBB.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $activityType = $this->route('activity_type');
            if ($activityType?->is_fallback && $this->has('active') && ! $this->boolean('active')) {
                $validator->errors()->add(
                    'active',
                    'A atividade base de preenchimento automático tem de permanecer sempre ativa.'
                );
            }
        }];
    }
}

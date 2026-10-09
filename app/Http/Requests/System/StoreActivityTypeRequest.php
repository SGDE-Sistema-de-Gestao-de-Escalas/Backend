<?php

namespace App\Http\Requests\System;

use App\Models\System\ActivityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ActivityType::class) ?? false;
    }

    /**
     * A escola vem do cabeçalho X-School-ID (já validado pelo middleware
     * school.context), nunca do corpo do pedido.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['school_id' => $this->attributes->get('school_id')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['required', 'uuid', 'exists:schools,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('activity_types', 'name')->where('school_id', $this->input('school_id')),
            ],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'school_id.required' => 'Indique a escola através do cabeçalho X-School-ID.',
            'name.unique' => 'Esta escola já tem uma atividade com este nome.',
            'color.regex' => 'A cor deve estar no formato #RRGGBB.',
        ];
    }
}

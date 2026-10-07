<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): \Illuminate\Auth\Access\Response|bool
    {
        $target = $this->route('user') ?? $this->user();

        if (! $target) {
            return false;
        }

        $response = \Illuminate\Support\Facades\Gate::inspect('update', $target);

        return $response->allowed() ? true : $response;
    }

    public function rules(): array
    {
        $target = $this->route('user') ?? $this->user();
        $targetId = is_object($target) ? $target->id : $target;
        $isAdmin = $this->user()?->isAdmin() ?? false;
        $presenceRule = $this->isMethod('PUT') ? 'required' : 'sometimes';

        $rules = [
            'first_name' => [$presenceRule, 'string', 'max:255'],
            'last_name' => [$presenceRule, 'string', 'max:255'],
            'email' => [
                $presenceRule,
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
        ];

        if ($isAdmin) {
            $rules['role_id'] = [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('roles', 'id')
                    ->whereIn('slug', ['admin', 'staff']),
            ];

            $rules['is_active'] = ['sometimes', 'required', 'boolean', 'accepted'];
        } else {
            $rules['role_id'] = ['prohibited'];
            $rules['is_active'] = ['prohibited'];
            $rules['school_id'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'role_id.prohibited' => 'Não tem permissão para alterar o perfil de utilizador.',
            'is_active.prohibited' => 'Não tem permissão para alterar o estado da conta.',
            'school_id.prohibited' => 'Não tem permissão para alterar a associação de escola.',
        ];
    }
}
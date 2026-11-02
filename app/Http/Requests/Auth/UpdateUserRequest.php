<?php

namespace App\Http\Requests\Auth;

use App\Rules\NoHtmlCharacters;
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
            'first_name' => [$presenceRule, 'string', 'max:255', new NoHtmlCharacters],
            'last_name' => [$presenceRule, 'string', 'max:255', new NoHtmlCharacters],
            'email' => [
                $presenceRule,
                'string',
                'email',
                'max:255',
                // Ignora a própria conta: sem isto, guardar o perfil com o email
                // inalterado dava "o email já existe" (em /me a rota não tem {user}).
                Rule::unique('users', 'email')
                    ->ignore($targetId)
                    ->whereNull('deleted_at'),
            ],
        ];

        // F-08: mudar o email da PRÓPRIA conta exige a password atual. O email
        // recebe a recuperação de password: uma sessão roubada não pode trocá-lo.
        if ($this->changesOwnEmail($target)) {
            $rules['current_password'] = ['required', 'string', 'current_password:sanctum'];
        }

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

    /**
     * É um pedido do próprio utilizador a mudar o seu email? (Um administrador a
     * editar a conta de outra pessoa não conta.)
     */
    private function changesOwnEmail(mixed $target): bool
    {
        $user = $this->user();

        if (! $user || ! $this->has('email')) {
            return false;
        }

        $targetId = is_object($target) ? $target->getKey() : $target;

        if ((string) $targetId !== (string) $user->getKey()) {
            return false;
        }

        return mb_strtolower(trim((string) $this->input('email')))
            !== mb_strtolower(trim((string) $user->email));
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Indique a sua password atual para alterar o email.',
            'current_password.current_password' => 'A password atual está incorreta.',
            'role_id.prohibited' => 'Não tem permissão para alterar o perfil de utilizador.',
            'is_active.prohibited' => 'Não tem permissão para alterar o estado da conta.',
            'school_id.prohibited' => 'Não tem permissão para alterar a associação de escola.',
        ];
    }
}
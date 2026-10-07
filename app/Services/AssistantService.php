<?php

namespace App\Services;

use App\Models\Assistants\Assistant;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class AssistantService
{
    /**
     * Campos que pertencem à conta (tabela users).
     * O email é partilhado: fica no user (login) e no assistente (contacto).
     */
    private const USER_FIELDS = ['first_name', 'last_name', 'email'];

    public function __construct(
        private readonly AuthService $authService
    ) {}

    /**
     * Cria o user STAFF e o assistente na mesma transação: ou ficam os dois
     * criados, ou nenhum. O email para definir a password só é enviado
     * depois do commit, para nunca mandar emails de contas que não existem.
     */
    public function create(array $data): Assistant
    {
        $assistant = DB::transaction(function () use ($data) {
            $staffRole = Role::where('slug', 'staff')->firstOrFail();

            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'role_id' => $staffRole->id,
                // Password inutilizável; o assistente define a sua pelo link do email.
                'password' => Hash::make(Str::random(64)),
            ]);

            return $user->assistant()->create(
                Arr::except($data, self::USER_FIELDS)
            );
        });

        $this->sendAccessEmail($assistant->user);

        return $assistant->load(['user', 'school']);
    }

    public function update(Assistant $assistant, array $data): Assistant
    {
        return DB::transaction(function () use ($assistant, $data) {
            $userData = Arr::only($data, self::USER_FIELDS);

            if ($userData !== []) {
                $user = $assistant->user;
                $user->fill($userData);

                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }

                $user->save();
            }

            $assistantData = Arr::except($data, ['first_name', 'last_name']);
            $assistant->update($assistantData);

            return $assistant->refresh()->load(['user', 'school']);
        });
    }

    /**
     * Soft delete do assistente e da respetiva conta, e revoga as sessões
     * abertas para que deixe de ter acesso imediatamente.
     */
    public function delete(Assistant $assistant): void
    {
        DB::transaction(function () use ($assistant) {
            $user = $assistant->user;

            $assistant->delete();

            if ($user && ! $user->trashed()) {
                $user->tokens()->delete();
                $user->delete();
            }
        });
    }

    /**
     * Uma falha no envio do email não deve anular a criação do assistente:
     * regista o erro e o admin pode reenviar pelo "esqueci-me da password".
     */
    private function sendAccessEmail(User $user): void
    {
        try {
            $this->authService->sendPasswordResetLink($user->email);
        } catch (Throwable $e) {
            report($e);
        }
    }
}


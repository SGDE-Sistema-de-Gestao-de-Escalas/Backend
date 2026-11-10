<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongPassword implements ValidationRule
{
    public const MIN_LENGTH = 10;

    private const COMMON_WORDS = [
        'password', 'passwrd', 'qwerty', 'qwertyuiop', 'azerty', 'admin', 'administrador',
        'welcome', 'letmein', 'iloveyou', 'abc', 'abcdef', 'senha', 'palavrapasse',
        'escola', 'sgde', 'segredo', 'mudar', 'login', 'master', 'secret',
    ];

    public function __construct(private readonly ?string $email = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (
            mb_strlen($value) < self::MIN_LENGTH
            || preg_match('/\p{Lu}/u', $value) !== 1
            || preg_match('/\p{Ll}/u', $value) !== 1
            || preg_match('/\p{N}/u', $value) !== 1
        ) {
            $fail('A password deve ter pelo menos 10 caracteres, com letras maiúsculas, minúsculas e números.');

            return;
        }

        $letters = mb_strtolower((string) preg_replace('/[^\p{L}]/u', '', $value));

        if (in_array($letters, self::COMMON_WORDS, true)) {
            $fail('Esta password é demasiado comum. Escolha outra.');

            return;
        }

        $localPart = mb_strtolower((string) strstr((string) $this->email, '@', true));

        if (mb_strlen($localPart) >= 4 && str_contains(mb_strtolower($value), $localPart)) {
            $fail('A password não pode conter o seu email.');
        }
    }
}

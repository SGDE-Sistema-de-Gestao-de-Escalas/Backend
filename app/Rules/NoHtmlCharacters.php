<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoHtmlCharacters implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[<>]/', $value) === 1) {
            $fail('O campo :attribute não pode conter os carateres < ou >.');
        }
    }
}

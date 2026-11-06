<?php

namespace App\Support;

use Illuminate\Http\Request;

class QueryInput
{
    /**
     * Lê um parâmetro como texto. Se vier uma lista (?search[]=a) ou outro tipo
     * que não texto, devolve o valor por defeito em vez de rebentar com erro 500.
     */
    public static function string(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : $default;
    }
}

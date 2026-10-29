<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Extensão com que um documento é guardado no disco.
 *
 * Vem sempre do CONTEÚDO do ficheiro (que a validação já confirmou ser PDF ou
 * imagem) e nunca do nome enviado pelo cliente. Caso contrário, um
 * "atestado.html" com cabeçalho de PDF ficava gravado como .html e era servido
 * como text/html (F-03).
 */
class DocumentExtension
{
    public const ALLOWED = ['pdf', 'jpg', 'png'];

    public static function for(UploadedFile $file): string
    {
        $guessed = strtolower((string) $file->guessExtension());

        if ($guessed === 'jpeg') {
            $guessed = 'jpg';
        }

        // Se, por alguma razão, o tipo real não for permitido, grava-se como pdf:
        // o servidor responderá com esse tipo e o browser não executa nada.
        return in_array($guessed, self::ALLOWED, true) ? $guessed : 'pdf';
    }
}

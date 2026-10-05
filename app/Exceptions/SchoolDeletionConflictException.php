<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Lançada quando se tenta eliminar uma escola que, no momento exato da
 * eliminação, ainda tem dependências ativas (assistentes alocados ou
 * horários não arquivados). O render() próprio garante sempre uma
 * resposta 409 Conflict, não importa de onde a exceção seja lançada.
 */
class SchoolDeletionConflictException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'CONFLICT',
            'message' => $this->getMessage(),
        ], 409);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve o ID da escola ativa a partir dos atributos do request, cabeçalho X-School-ID
     * ou (opcionalmente) da escola associada ao utilizador/assistente autenticado.
     */
    protected function resolveSchoolId(Request $request, bool $fallbackToAssistant = false): ?string
    {
        $schoolId = $request->attributes->get('currentSchoolId')
            ?? $request->attributes->get('school_id')
            ?? $request->header('X-School-ID');

        if (! $schoolId && $fallbackToAssistant) {
            $schoolId = $request->user()?->school_id
                ?? $request->user()?->assistant?->school_id;
        }

        return $schoolId ? (string) $schoolId : null;
    }

    /**
     * Garante que o recurso pertence à escola selecionada no contexto atual (devolve 404 caso contrário).
     */
    protected function ensureInSchoolContext(Request $request, ?string $resourceSchoolId): void
    {
        $schoolId = $this->resolveSchoolId($request);

        if ($schoolId !== null && $resourceSchoolId !== null && $resourceSchoolId !== $schoolId) {
            abort(404);
        }
    }
}

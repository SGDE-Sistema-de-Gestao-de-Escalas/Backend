<?php

namespace App\Http\Middleware;

use App\Services\SchoolAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lê o cabeçalho `X-School-ID`, valida-o e disponibiliza-o ao resto do pedido.
 *
 * O cabeçalho é opcional: sem ele o pedido segue normalmente e
 * `school_id` fica a null (controllers globais, como /me ou /schools,
 * ignoram-no). Quando existe, tem de ser o UUID de uma escola a que o
 * utilizador tem acesso. Os controllers contextuais usam:
 *
 *     $schoolId = $request->attributes->get('school_id');
 *
 * Deve ser aplicado depois de `auth:sanctum`, porque precisa do utilizador.
 */
class SetSchoolContext
{
    public const HEADER = 'X-School-ID';

    public function __construct(
        private readonly SchoolAccessService $schoolAccess
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $schoolId = trim((string) $request->header(self::HEADER, ''));

        if ($schoolId === '') {
            $request->attributes->set('school_id', null);

            return $next($request);
        }

        abort_unless(Str::isUuid($schoolId), 422, 'O cabeçalho X-School-ID é inválido.');

        $user = $request->user();

        abort_unless($user, 401, 'Não autenticado.');

        // Um único 403 para "não existe" e "sem acesso": não revela a
        // existência de escolas a quem não as pode ver.
        abort_unless(
            $this->schoolAccess->canAccess($user, $schoolId),
            403,
            'Não tem acesso a esta escola.'
        );

        $request->attributes->set('school_id', $schoolId);

        return $next($request);
    }
}

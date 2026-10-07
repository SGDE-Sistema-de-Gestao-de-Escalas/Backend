<?php
use App\Http\Controllers\API\Absences\AbsenceController;
use App\Http\Controllers\API\Absences\AbsenceTypeController;
use App\Http\Controllers\API\Assistants\AssistantController;
use App\Http\Controllers\API\Auth\RoleController;
use App\Http\Controllers\API\Schedules\ScheduleController;
use App\Http\Controllers\API\Schools\SchoolController;
use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\Auth\UserController;
use App\Models\Schedules\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/teste', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!'
        ]);
});

// Login público, limitado a 5 pedidos por minuto.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');

Route::post('/password/forgot', [AuthController::class, 'requestPasswordReset'])
    ->name('password.email');

// Repor Password (utilizado após receber o email)
Route::post('/password/reset', [AuthController::class, 'resetPassword'])
    ->name('password.update');

// Rotas OAuth
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\API\Auth\OAuthController::class, 'redirect'])
    ->name('oauth.redirect');
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\API\Auth\OAuthController::class, 'externalLoginCallback'])
    ->name('oauth.callback');

// Exige autenticação e uma conta ativa em todas as rotas deste grupo.
// Estas rotas são globais (não dependem de uma escola) e NÃO usam o
// school.context: um X-School-ID antigo ou inválido não as pode bloquear.
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Devolve os dados do utilizador autenticado.
    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');
    Route::match(['put', 'patch'], '/me', [AuthController::class, 'updateMe'])
        ->name('auth.me.update');

    // Termina a sessão do utilizador autenticado.
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::post('/update-password', [AuthController::class, 'updatePassword'])
        ->name('password.change');

    // Gestão de utilizadores (autorização granular via UserPolicy e FormRequests)
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->name('users.deactivate');
    Route::apiResource('users', UserController::class);

    // Parametrização global da plataforma: só administradores.
    Route::middleware('can:manage-system')->group(function () {
        Route::apiResource('roles', RoleController::class)->only(['index']);
        Route::apiResource('schools', SchoolController::class);
        Route::apiResource('absence-types', AbsenceTypeController::class);
    });
});

// Rotas que dependem de uma escola. O school.context lê o cabeçalho opcional
// X-School-ID e valida a existência e o acesso (ver SetSchoolContext).
Route::middleware(['auth:sanctum', 'active', 'school.context'])->group(function () {

    // Restringe a gestão destes recursos aos administradores.
    Route::middleware('can:manage-system')->group(function () {
        Route::apiResource('assistants', AssistantController::class);
        Route::apiResource('absences', AbsenceController::class);
    });

    // Autoriza a listagem de escalas; o controller deve filtrar os resultados.
    Route::get('/schedules', [ScheduleController::class, 'index'])
        ->can('viewAny', Schedule::class)
        ->name('schedules.index');

    // Verifica a permissão para consultar a escala pedida.
    Route::get('/schedules/{schedule}', [ScheduleController::class, 'show'])
        ->can('view', 'schedule')
        ->name('schedules.show');
});
